<?php

declare(strict_types=1);

namespace SyncGuard\Worker;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use SyncGuard\Adapters\AdapterResolver;
use SyncGuard\Adapters\PushResult;
use SyncGuard\Repositories\AttemptRepository;
use SyncGuard\Repositories\HealthRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Retry\Backoff;
use SyncGuard\Support\AlertWriter;
use Throwable;

/**
 * Class JobProcessor
 *
 * Core worker execution engine for consuming, dispatching, and auditing outbox sync jobs.
 *
 * KEY INVARIANTS:
 * 1. ATOMIC CLAIM:
 *    A conditional SQL UPDATE guarantees that multiple concurrent workers never claim or execute
 *    the exact same job row.
 * 2. UNIFIED ATTEMPT TRANSACTION:
 *    Recording the audit attempt row in `sync_attempts` and transitioning the job status
 *    in `sync_jobs` occur inside ONE database transaction. This ensures the telemetry log
 *    and job queue state never drift out of sync.
 * 3. BACKOFF & DEAD STATE:
 *    Failed jobs receive progressively increasing delays (1m -> 5m -> 15m -> 1h) with randomized
 *    jitter. After 5 attempts, the job transitions to 'dead' and is never polled again automatically.
 * 4. CHANNEL HEALTH SERIALIZATION:
 *    The channel health row is locked FOR UPDATE as the FIRST statement of the finalization
 *    transaction, ensuring concurrent worker deliveries for the same channel are processed in order
 *    without race conditions or dropped attempt counts.
 * 5. ALERT WRITING AFTER COMMIT:
 *    Alert notifications are dispatched strictly AFTER the database transaction commits to prevent
 *    external I/O latency from holding row locks or aborting transaction commits.
 * 6. FAULT ISOLATION:
 *    An unhandled exception or transport failure in an adapter cannot crash the worker loop.
 */
class JobProcessor
{
    /**
     * @var callable|null Optional logger callable: fn(string $message): void
     */
    private $logger;
    private readonly Backoff $backoff;
    private readonly AlertWriter $alertWriter;
    private readonly HealthRepository $healthRepo;

    public function __construct(
        private readonly PDO $pdo,
        private readonly JobRepository $jobRepo,
        private readonly UpdateRepository $updateRepo,
        private readonly AttemptRepository $attemptRepo,
        private readonly AdapterResolver $adapterResolver,
        private readonly int $stuckThresholdSeconds = 300,
        ?callable $logger = null,
        ?Backoff $backoff = null,
        ?AlertWriter $alertWriter = null,
        ?HealthRepository $healthRepo = null
    ) {
        $this->logger = $logger;
        $this->backoff = $backoff ?? new Backoff();
        $this->alertWriter = $alertWriter ?? new AlertWriter();
        $this->healthRepo = $healthRepo ?? new HealthRepository($this->pdo);
    }


    /**
     * Execute a single queue processing cycle.
     *
     * @param int $batchSize Number of due jobs to claim per pass (default 10)
     * @param array<int>|null $onlyJobIds Optional filter to restrict execution (used in tests)
     * @return array{
     *     recovered: int,
     *     found: int,
     *     skipped_no_adapter: int,
     *     lost_race: int,
     *     succeeded: int,
     *     failed: int,
     *     dead: int
     * }
     */
    public function runOnce(int $batchSize = 10, ?array $onlyJobIds = null): array
    {
        $counts = [
            'recovered'          => 0,
            'found'              => 0,
            'skipped_no_adapter' => 0,
            'lost_race'          => 0,
            'succeeded'          => 0,
            'failed'             => 0,
            'dead'               => 0,
        ];

        // 1. Recover jobs stranded in 'in_progress' by dead or terminated workers
        $counts['recovered'] = $this->jobRepo->recoverStuck($this->stuckThresholdSeconds);
        if ($counts['recovered'] > 0) {
            $this->log(sprintf('Recovered %d stuck in_progress jobs.', $counts['recovered']));
        }

        // 2. Poll due jobs using database clock (UTC_TIMESTAMP). Dead jobs are never returned.
        $dueJobs = $this->jobRepo->findDue($batchSize, $onlyJobIds);
        $counts['found'] = count($dueJobs);

        if ($counts['found'] === 0) {
            return $counts;
        }

        // 3. Process each eligible job
        foreach ($dueJobs as $job) {
            $jobId = (int) $job['id'];
            $adapterClass = (string) $job['adapter_class'];

            // Guard: Check if adapter is implemented before claiming
            if (!$this->adapterResolver->has($adapterClass)) {
                $counts['skipped_no_adapter']++;
                continue;
            }

            // Step 4: Atomically claim the job
            if (!$this->jobRepo->claim($jobId)) {
                $counts['lost_race']++;
                continue;
            }

            // Step 5: Load payload and dispatch via adapter
            $update = $this->updateRepo->findById((int) $job['update_id']);
            if ($update === null) {
                $pushResult = PushResult::httpError(0, null, 'Orphaned job: associated inventory_updates row not found');
            } else {
                $pushResult = $this->executePush($job, $update);
            }

            // Step 6: Commit attempt and job state transition in ONE transaction
            $startedAtUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $finalized = $this->finalizeJob($job, $startedAtUtc, $pushResult);

            if ($finalized['success']) {
                if ($pushResult->success) {
                    $counts['succeeded']++;
                } elseif ($finalized['is_dead']) {
                    $counts['dead']++;
                } else {
                    $counts['failed']++;
                }
            } else {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * Dispatch update via the resolved adapter with comprehensive exception safety.
     *
     * @param array<string, mixed> $job
     * @param array<string, mixed> $update
     * @return PushResult
     */
    private function executePush(array $job, array $update): PushResult
    {
        $jobId = (int) $job['id'];
        $adapterClass = (string) $job['adapter_class'];

        try {
            $adapter = $this->adapterResolver->resolve($adapterClass);
            if ($adapter === null) {
                return PushResult::httpError(0, null, "Could not resolve adapter '{$adapterClass}'");
            }

            return $adapter->push($update, $job);
        } catch (Throwable $e) {
            // Fault isolation: log internal error without crashing the processor loop
            error_log(sprintf('[Job #%d] Exception in adapter %s: %s', $jobId, $adapterClass, $e->getMessage()));
            return PushResult::httpError(0, null, 'Internal adapter error');
        }
    }

    /**
     * Atomically log delivery attempt and transition job queue state in ONE transaction.
     *
     * WHY ONE TRANSACTION FOR ATTEMPT + JOB STATUS:
     * If the attempt record and the job status transition were executed in separate queries,
     * a network disconnect or database failure between the two operations could leave an attempt
     * without a status change, or a status transition without an audit trail.
     * Wrapping them in a single transaction guarantees strict atomicity and audit consistency.
     *
     * @param array<string, mixed> $job
     * @param DateTimeImmutable $startedAtUtc
     * @param PushResult $result
     * @return array{success: bool, is_dead: bool}
     */
    private function finalizeJob(array $job, DateTimeImmutable $startedAtUtc, PushResult $result): array
    {
        $jobId = (int) $job['id'];
        $channelId = (int) $job['channel_id'];
        $isDead = false;

        $maxRetries = 3;
        for ($retryAttempt = 1; $retryAttempt <= $maxRetries; $retryAttempt++) {
            try {
                $this->pdo->beginTransaction();

                // 1. Lock channel health row FIRST to serialize concurrent workers on this channel
                // This prevents race conditions where concurrent workers recompute from stale snapshots
                $this->healthRepo->lockChannel($channelId);

                // 2. Audit log the attempt
                $this->attemptRepo->record($jobId, $startedAtUtc, $result);

                // 3. Transition job status based on success or retry decision
                if ($result->success) {
                    $updated = $this->jobRepo->markSuccess($jobId);
                } else {
                    // Compute new attempt count (including this failed attempt)
                    $newAttemptCount = ((int) $job['attempt_count']) + 1;
                    $decision = $this->backoff->calculate($newAttemptCount);

                    if ($decision->isDead) {
                        $updated = $this->jobRepo->markDead($jobId, $result->message);
                        $isDead = true;
                    } else {
                        $updated = $this->jobRepo->markFailed($jobId, $result->message, $decision->delaySeconds);
                    }
                }

                if (!$updated) {
                    // If markSuccess, markFailed, or markDead returned false, the worker did not hold the claim
                    $this->pdo->rollBack();
                    error_log(sprintf('[Job #%d] Could not finalize job: row was not in in_progress state.', $jobId));
                    return ['success' => false, 'is_dead' => false];
                }

                // 4. Recompute and persist updated channel_health inside the SAME transaction
                // (Guaranteed to see this and all previous committed attempts)
                $this->healthRepo->recordAttempt($channelId);

                $this->pdo->commit();

                // 5. Trigger alert AFTER commit (never inside the transaction to avoid holding locks)
                if ($isDead) {
                    $this->alertWriter->writeJobDead(
                        jobId: $jobId,
                        updateId: (int) $job['update_id'],
                        channel: (string) ($job['channel_name'] ?? 'Unknown Channel'),
                        attemptCount: ((int) $job['attempt_count']) + 1,
                        lastError: $result->message
                    );
                }

                return ['success' => true, 'is_dead' => $isDead];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                $isDeadlock = false;
                if ($e instanceof \PDOException) {
                    $code = (string) $e->getCode();
                    $msg = $e->getMessage();
                    if ($code === '40001' || str_contains($msg, '1213') || str_contains($msg, 'Deadlock') || str_contains($msg, 'Lock wait timeout')) {
                        $isDeadlock = true;
                    }
                }

                if ($isDeadlock && $retryAttempt < $maxRetries) {
                    usleep(random_int(60000, 180000)); // 60ms - 180ms backoff to allow competing worker commit
                    continue;
                }

                error_log(sprintf('[Job #%d] Database failure finalizing job: %s', $jobId, $e->getMessage()));
                return ['success' => false, 'is_dead' => false];
            }
        }

        return ['success' => false, 'is_dead' => false];
    }

    /**
     * Helper logger method.
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
