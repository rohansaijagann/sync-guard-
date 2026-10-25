<?php

declare(strict_types=1);

namespace SyncGuard\Repositories;

use PDO;
use SyncGuard\Services\HealthService;

/**
 * HealthRepository
 *
 * Manages atomic channel health calculations, persistence, and concurrency synchronization.
 *
 * WHY THE HEALTH ROW IS LOCKED BEFORE INSERTING ATTEMPTS:
 * In a multi-worker architecture, two worker processes may finish pushing delivery jobs for the same
 * booking site (e.g. Site C) at the exact same millisecond.
 * If both workers recomputed channel health in parallel without locking:
 *   1. Worker 1 reads the current attempts (snapshot A)
 *   2. Worker 2 reads the current attempts (snapshot A)
 *   3. Worker 1 writes its attempt row, computes health from snapshot A, and writes channel_health
 *   4. Worker 2 writes its attempt row, computes health from snapshot A, and overwrites channel_health
 * This race condition would result in Worker 2's write silently wiping Worker 1's attempt from the metrics.
 *
 * To prevent this, the finalization transaction executes in a strict, deterministic sequence:
 *   Step 1: Lock the channel's health row via SELECT ... FOR UPDATE (inserting an initial row if missing).
 *   Step 2: Insert the new attempt record into sync_attempts.
 *   Step 3: Update the job status in sync_jobs.
 *   Step 4: Recompute channel_health from sync_attempts (now guaranteed to see all prior committed attempts).
 *   Step 5: Commit transaction.
 *
 * By locking the health row first, concurrent workers for the same channel are serialized cleanly.
 * Deadlocks are impossible because every worker acquires locks in the exact same order (channel_health first).
 */
class HealthRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Acquire an exclusive row lock on channel_health for the given channel.
     * Must be called inside an active transaction before inserting attempts.
     */
    public function lockChannel(int $channelId): void
    {
        // Lock row exclusively for the duration of the current transaction
        $lock = $this->pdo->prepare('SELECT channel_id FROM channel_health WHERE channel_id = ? FOR UPDATE');
        $lock->execute([$channelId]);

        // If channel row does not exist yet, insert default
        if ($lock->fetch() === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO channel_health 
                    (channel_id, success_rate, avg_response_ms, circuit_state, consecutive_failures) 
                 VALUES (?, 100.00, 0, "closed", 0)
                 ON DUPLICATE KEY UPDATE channel_id = channel_id'
            );
            $insert->execute([$channelId]);
        }
    }

    /**
     * Recomputes and updates the channel_health row for a specific channel.
     * Uses the last N attempts (rolling window) to determine success rate and avg latency.
     *
     * @param int $channelId Channel identifier
     * @param int $window    Evaluation window size (default 50)
     * @return array Calculated health metrics
     */
    public function recordAttempt(int $channelId, int $window = HealthService::DEFAULT_WINDOW_ATTEMPTS): array
    {
        // 1. Fetch the last N attempts for this channel ordered newest to oldest
        $stmt = $this->pdo->prepare(
            'SELECT a.outcome, a.duration_ms, a.started_at 
             FROM sync_attempts a 
             INNER JOIN sync_jobs j ON a.job_id = j.id 
             WHERE j.channel_id = :channel_id 
             ORDER BY a.id DESC 
             LIMIT :window'
        );
        $stmt->bindValue(':channel_id', $channelId, PDO::PARAM_INT);
        $stmt->bindValue(':window', $window, PDO::PARAM_INT);
        $stmt->execute();
        $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalAttempts = count($attempts);

        if ($totalAttempts === 0) {
            $successRate = 100.00;
            $avgResponseMs = 0;
            $consecutiveFailures = 0;
            $lastSuccessAt = null;
            $lastFailureAt = null;
        } else {
            $successCount = 0;
            $successDurations = [];
            $consecutiveFailures = 0;
            $countingFailures = true;
            $lastSuccessAt = null;
            $lastFailureAt = null;

            foreach ($attempts as $att) {
                $outcome = $att['outcome'];
                $startedAt = $att['started_at'];

                if ($outcome === 'success') {
                    $successCount++;
                    $successDurations[] = (int) $att['duration_ms'];
                    if ($lastSuccessAt === null) {
                        $lastSuccessAt = $startedAt;
                    }
                    // A success halts the consecutive failure streak
                    $countingFailures = false;
                } else {
                    if ($lastFailureAt === null) {
                        $lastFailureAt = $startedAt;
                    }
                    if ($countingFailures) {
                        $consecutiveFailures++;
                    }
                }
            }

            // Success rate over the window
            $successRate = round(($successCount / $totalAttempts) * 100, 2);

            // Average response time of SUCCESSFUL attempts only (failures/timeouts are excluded)
            $avgResponseMs = !empty($successDurations)
                ? (int) round(array_sum($successDurations) / count($successDurations))
                : 0;

            // If last_success_at was not within the rolling window, search earlier attempts
            if ($lastSuccessAt === null) {
                $earlierSuccess = $this->pdo->prepare(
                    "SELECT a.started_at 
                     FROM sync_attempts a 
                     INNER JOIN sync_jobs j ON a.job_id = j.id 
                     WHERE j.channel_id = :channel_id AND a.outcome = 'success' 
                     ORDER BY a.id DESC 
                     LIMIT 1"
                );
                $earlierSuccess->execute([':channel_id' => $channelId]);
                $val = $earlierSuccess->fetchColumn();
                if ($val !== false) {
                    $lastSuccessAt = $val;
                }
            }

            // If last_failure_at was not within the rolling window, search earlier attempts
            if ($lastFailureAt === null) {
                $earlierFailure = $this->pdo->prepare(
                    "SELECT a.started_at 
                     FROM sync_attempts a 
                     INNER JOIN sync_jobs j ON a.job_id = j.id 
                     WHERE j.channel_id = :channel_id AND a.outcome != 'success' 
                     ORDER BY a.id DESC 
                     LIMIT 1"
                );
                $earlierFailure->execute([':channel_id' => $channelId]);
                $val = $earlierFailure->fetchColumn();
                if ($val !== false) {
                    $lastFailureAt = $val;
                }
            }
        }

        // 2. Persist updated health numbers back to channel_health
        // Note: circuit_state and circuit_opened_at are intentionally untouched (Phase 6 scope)
        $update = $this->pdo->prepare(
            'UPDATE channel_health 
             SET success_rate = :success_rate,
                 avg_response_ms = :avg_response_ms,
                 consecutive_failures = :consecutive_failures,
                 last_success_at = :last_success_at,
                 last_failure_at = :last_failure_at
             WHERE channel_id = :channel_id'
        );
        $update->bindValue(':success_rate', $successRate);
        $update->bindValue(':avg_response_ms', $avgResponseMs, PDO::PARAM_INT);
        $update->bindValue(':consecutive_failures', $consecutiveFailures, PDO::PARAM_INT);
        $update->bindValue(':last_success_at', $lastSuccessAt);
        $update->bindValue(':last_failure_at', $lastFailureAt);
        $update->bindValue(':channel_id', $channelId, PDO::PARAM_INT);
        $update->execute();

        return [
            'channel_id'           => $channelId,
            'success_rate'         => $successRate,
            'avg_response_ms'      => $avgResponseMs,
            'consecutive_failures' => $consecutiveFailures,
            'last_success_at'      => $lastSuccessAt,
            'last_failure_at'      => $lastFailureAt,
        ];
    }

    /**
     * Rebuilds channel_health for all channels in the database.
     * Shares exact same computation logic as recordAttempt.
     *
     * @param int $window Evaluation window size
     * @return array List of rebuilt channel metrics
     */
    public function rebuildAll(int $window = HealthService::DEFAULT_WINDOW_ATTEMPTS): array
    {
        $channelsStmt = $this->pdo->query('SELECT id, name FROM channels ORDER BY id ASC');
        $channels = $channelsStmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($channels as $c) {
            $cid = (int) $c['id'];
            // Lock and recompute
            $this->lockChannel($cid);
            $metrics = $this->recordAttempt($cid, $window);
            $metrics['channel_name'] = $c['name'];
            $results[] = $metrics;
        }

        return $results;
    }

    /**
     * Reset channel_health rows to initial default values (100% success rate, 0 failures).
     * Used by bin/reset_queue.php. Never deletes rows to preserve foreign key stability.
     */
    public function resetAllToDefaults(): void
    {
        $this->pdo->exec(
            'UPDATE channel_health 
             SET success_rate = 100.00,
                 avg_response_ms = 0,
                 last_success_at = NULL,
                 last_failure_at = NULL,
                 circuit_state = "closed",
                 consecutive_failures = 0,
                 circuit_opened_at = NULL'
        );
    }
}
