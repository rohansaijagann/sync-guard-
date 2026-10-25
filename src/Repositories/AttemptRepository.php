<?php

declare(strict_types=1);

namespace SyncGuard\Repositories;

use DateTimeImmutable;
use PDO;
use SyncGuard\Adapters\PushResult;

/**
 * Class AttemptRepository
 *
 * Encapsulates telemetry persistence for the immutable `sync_attempts` audit table.
 * All queries use prepared statements with strict parameter binding.
 */
class AttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record an individual delivery attempt for a sync job.
     *
     * @param int $jobId
     * @param DateTimeImmutable $startedAtUtc
     * @param PushResult $result
     * @return int Inserted attempt ID
     */
    public function record(int $jobId, DateTimeImmutable $startedAtUtc, PushResult $result): int
    {
        $sql = 'INSERT INTO sync_attempts (
                    job_id,
                    started_at,
                    duration_ms,
                    http_status,
                    outcome,
                    message
                ) VALUES (
                    :job_id,
                    :started_at,
                    :duration_ms,
                    :http_status,
                    :outcome,
                    :message
                )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':job_id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':started_at', $startedAtUtc->format('Y-m-d H:i:s'), PDO::PARAM_STR);
        $stmt->bindValue(':duration_ms', $result->durationMs, PDO::PARAM_INT);

        if ($result->httpStatus === null) {
            $stmt->bindValue(':http_status', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':http_status', $result->httpStatus, PDO::PARAM_INT);
        }

        $stmt->bindValue(':outcome', $result->outcome->value, PDO::PARAM_STR);
        $stmt->bindValue(':message', $result->message, PDO::PARAM_STR);

        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Retrieve all attempt logs recorded for a given sync job.
     *
     * @param int $jobId
     * @return array<int, array<string, mixed>>
     */
    public function getByJobId(int $jobId): array
    {
        $sql = 'SELECT id, job_id, started_at, duration_ms, http_status, outcome, message
                FROM sync_attempts
                WHERE job_id = :job_id
                ORDER BY id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':job_id', $jobId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
