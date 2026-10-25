<?php

declare(strict_types=1);

namespace SyncGuard\Repositories;

use PDO;

/**
 * Class JobRepository
 *
 * Encapsulates all SQL interactions for the `sync_jobs` queue table.
 * All queries use prepared statements with strict parameter binding.
 */
class JobRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Create queued sync jobs for each target channel in an update.
     *
     * @param int $updateId Primary key of the inventory update
     * @param array<int> $channelIds List of active channel IDs
     * @return int Number of sync jobs created
     */
    public function createJobsForUpdate(int $updateId, array $channelIds): int
    {
        if (empty($channelIds)) {
            return 0;
        }

        $sql = 'INSERT INTO sync_jobs (
                    update_id,
                    channel_id,
                    status,
                    attempt_count,
                    next_attempt_at
                ) VALUES (
                    :update_id,
                    :channel_id,
                    "pending",
                    0,
                    CURRENT_TIMESTAMP
                )';

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($channelIds as $channelId) {
            $stmt->bindValue(':update_id', $updateId, PDO::PARAM_INT);
            $stmt->bindValue(':channel_id', (int) $channelId, PDO::PARAM_INT);
            $stmt->execute();
            $count++;
        }

        return $count;
    }

    /**
     * Fetch all sync jobs associated with an inventory update, joined with channel info.
     *
     * @param int $updateId
     * @return array<int, array<string, mixed>>
     */
    public function getByUpdateId(int $updateId): array
    {
        $sql = 'SELECT 
                    j.id,
                    j.update_id,
                    j.channel_id,
                    c.name AS channel_name,
                    c.adapter_class,
                    j.status,
                    j.attempt_count,
                    j.next_attempt_at,
                    j.last_error,
                    j.updated_at
                FROM sync_jobs j
                INNER JOIN channels c ON c.id = j.channel_id
                WHERE j.update_id = :update_id
                ORDER BY j.channel_id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':update_id', $updateId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Count the total number of sync jobs created for an update.
     *
     * @param int $updateId
     * @return int
     */
    public function countByUpdateId(int $updateId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sync_jobs WHERE update_id = :update_id');
        $stmt->bindValue(':update_id', $updateId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Find a single job by its primary key ID.
     *
     * @param int $jobId
     * @return array<string, mixed>|null
     */
    public function findById(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sync_jobs WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Query jobs that are due for delivery.
     *
     * WHY USING DATABASE CLOCK (UTC_TIMESTAMP):
     * In distributed or multi-container deployments, application clocks and database clocks can drift.
     * Comparing `next_attempt_at <= UTC_TIMESTAMP()` directly in SQL guarantees that scheduling decisions
     * rely on the exact monotonic database clock rather than fluctuating PHP system clocks.
     *
     * @param int $limit Batch size
     * @param array<int>|null $onlyJobIds Optional filter to restrict to specific jobs (used in tests)
     * @return array<int, array<string, mixed>>
     */
    public function findDue(int $limit, ?array $onlyJobIds = null): array
    {
        $sql = 'SELECT 
                    j.id,
                    j.update_id,
                    j.channel_id,
                    j.status,
                    j.attempt_count,
                    j.next_attempt_at,
                    j.last_error,
                    j.updated_at,
                    c.name AS channel_name,
                    c.adapter_class,
                    c.endpoint_url,
                    c.is_active
                FROM sync_jobs j
                INNER JOIN channels c ON c.id = j.channel_id
                WHERE j.status IN ("pending", "failed")
                  AND j.next_attempt_at <= UTC_TIMESTAMP()
                  AND c.is_active = 1';

        if ($onlyJobIds !== null) {
            if (empty($onlyJobIds)) {
                return [];
            }
            $cleanIds = implode(',', array_map('intval', $onlyJobIds));
            $sql .= " AND j.id IN ({$cleanIds})";
        }

        $sql .= ' ORDER BY j.next_attempt_at ASC, j.id ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Atomically claim a job for worker processing.
     *
     * WHY THE CLAIM IS ONE CONDITIONAL UPDATE (Beat the race):
     * Reading a job and then updating it in two separate queries is prone to race conditions:
     * two concurrent workers can both read the exact same row with status='pending'. Both would then
     * proceed to claim and push the same job, causing duplicate bookings or duplicate OTA charges.
     * A single conditional UPDATE lets MySQL atomically decide a winner via row-level locks.
     * The winning worker gets rowCount = 1 and proceeds. The losing worker gets rowCount = 0 and skips.
     *
     * @param int $jobId
     * @return bool True if the claim was secured (exactly 1 row affected), false if lost to another worker
     */
    public function claim(int $jobId): bool
    {
        $sql = 'UPDATE sync_jobs
                SET status = "in_progress",
                    updated_at = UTC_TIMESTAMP()
                WHERE id = :id
                  AND status IN ("pending", "failed")
                  AND next_attempt_at <= UTC_TIMESTAMP()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Mark an in-progress job as successfully delivered.
     *
     * @param int $jobId
     * @return bool True if updated, false if the job was not in 'in_progress' state
     */
    public function markSuccess(int $jobId): bool
    {
        $sql = 'UPDATE sync_jobs
                SET status = "success",
                    attempt_count = attempt_count + 1,
                    last_error = NULL,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = :id
                  AND status = "in_progress"';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Mark an in-progress job as failed and schedule the next retry attempt using backoff delay.
     *
     * WHY A CONDITIONAL UPDATE:
     * Guarding with `WHERE id = :id AND status = "in_progress"` ensures that only the specific worker
     * instance holding the active atomic claim can register failure and reschedule the job.
     *
     * @param int $jobId
     * @param string $error Diagnostic error message
     * @param int $delaySeconds Calculated backoff delay in seconds (default 60)
     * @return bool True if updated, false if the job was not in 'in_progress' state
     */
    public function markFailed(int $jobId, string $error, int $delaySeconds = 60): bool
    {
        $truncatedError = mb_substr($error, 0, 255, 'UTF-8');
        $safeDelay = max(1, $delaySeconds);

        $sql = 'UPDATE sync_jobs
                SET status = "failed",
                    attempt_count = attempt_count + 1,
                    last_error = :error,
                    next_attempt_at = UTC_TIMESTAMP() + INTERVAL :delay SECOND,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = :id
                  AND status = "in_progress"';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':error', $truncatedError, PDO::PARAM_STR);
        $stmt->bindValue(':delay', $safeDelay, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Mark an in-progress job as 'dead' after exhausting the maximum retry attempts.
     *
     * Invariant: `next_attempt_at` is left unchanged because dead jobs are never polled by findDue().
     *
     * @param int $jobId
     * @param string $error Diagnostic error message
     * @return bool True if updated, false if the job was not in 'in_progress' state
     */
    public function markDead(int $jobId, string $error): bool
    {
        $truncatedError = mb_substr($error, 0, 255, 'UTF-8');

        $sql = 'UPDATE sync_jobs
                SET status = "dead",
                    attempt_count = attempt_count + 1,
                    last_error = :error,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = :id
                  AND status = "in_progress"';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':error', $truncatedError, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Reset a failed or dead job back to 'pending' for immediate manual re-delivery.
     *
     * WHY A SINGLE CONDITIONAL UPDATE:
     * A manual retry requested by staff or CLI must never race against a background worker that has
     * just claimed the job. By restricting the statement to `status IN ('failed', 'dead')`, MySQL's
     * row-level locking ensures that if a worker just transitioned the job to 'in_progress', the
     * manual retry cleanly affects 0 rows and returns 'not_retryable'.
     *
     * DESIGN DECISION ON RESETTING ATTEMPT_COUNT:
     * A manual retry resets `attempt_count` back to 0 so the job receives a brand-new 5-attempt retry budget.
     * Historical audit data is NEVER lost, because all previous execution attempts remain permanently
     * logged in `sync_attempts`. Without resetting `attempt_count`, a dead job (5 previous failures) would
     * fail once on retry and immediately become dead again.
     *
     * @param int $jobId
     * @return string 'retried' | 'not_found' | 'not_retryable'
     */
    public function retry(int $jobId): string
    {
        $sql = 'UPDATE sync_jobs
                SET status = "pending",
                    attempt_count = 0,
                    next_attempt_at = UTC_TIMESTAMP(),
                    last_error = NULL,
                    updated_at = UTC_TIMESTAMP()
                WHERE id = :id
                  AND status IN ("failed", "dead")';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 1) {
            return 'retried';
        }

        // If zero rows changed, distinguish between nonexistent job and invalid status
        $checkStmt = $this->pdo->prepare('SELECT id, status FROM sync_jobs WHERE id = :id LIMIT 1');
        $checkStmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $checkStmt->execute();
        $job = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            return 'not_found';
        }

        return 'not_retryable';
    }

    /**
     * Manually queue all dead jobs for immediate retry.
     *
     * @param array<int>|null $onlyJobIds Optional filter (useful in test suites)
     * @return int Number of dead jobs queued for retry
     */
    public function retryAllDead(?array $onlyJobIds = null): int
    {
        $sql = 'UPDATE sync_jobs
                SET status = "pending",
                    attempt_count = 0,
                    next_attempt_at = UTC_TIMESTAMP(),
                    last_error = NULL,
                    updated_at = UTC_TIMESTAMP()
                WHERE status = "dead"';

        if ($onlyJobIds !== null) {
            if (empty($onlyJobIds)) {
                return 0;
            }
            $cleanIds = implode(',', array_map('intval', $onlyJobIds));
            $sql .= " AND id IN ({$cleanIds})";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Recover orphaned jobs stranded in 'in_progress' due to sudden worker crashes or OOM kills.
     *
     * WHY STUCK JOB RECOVERY IS ESSENTIAL:
     * If a worker container crashes, is terminated with SIGKILL, or suffers an unhandled fatal error,
     * claimed jobs would remain in 'in_progress' forever. `recoverStuck()` resets stale jobs back to
     * 'failed' so they become immediately eligible for delivery without incrementing attempt_count.
     *
     * @param int $olderThanSeconds Threshold in seconds (e.g. 300)
     * @return int Number of recovered jobs
     */
    public function recoverStuck(int $olderThanSeconds): int
    {
        $sql = 'UPDATE sync_jobs
                SET status = "failed",
                    last_error = "Recovered after worker stopped unexpectedly",
                    next_attempt_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()
                WHERE status = "in_progress"
                  AND updated_at < UTC_TIMESTAMP() - INTERVAL :seconds SECOND';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':seconds', $olderThanSeconds, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Fast-forward retry delays by moving next_attempt_at into the past for failed jobs.
     * Shared across CLI (bin/fast_forward.php) and demo API (/api/demo.php action: skip_wait).
     *
     * @param int|null $jobId Optional single job ID filter
     * @return int Number of updated failed jobs
     */
    public function skipWait(?int $jobId = null): int
    {
        if ($jobId !== null) {
            $stmt = $this->pdo->prepare('UPDATE sync_jobs SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 10 SECOND WHERE id = :id AND status = "failed"');
            $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount();
        }

        return (int) $this->pdo->exec('UPDATE sync_jobs SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 10 SECOND WHERE status = "failed"');
    }
}
