<?php

declare(strict_types=1);

namespace SyncGuard\Repositories;

use PDO;

/**
 * Class UpdateRepository
 *
 * Encapsulates all database interactions for the `inventory_updates` table.
 * All queries use prepared statements with strict parameter binding.
 */
class UpdateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Insert a new inventory update record.
     *
     * @param array{
     *     room_id: int,
     *     date_from: string,
     *     date_to: string,
     *     available_units: int|null,
     *     price: string|null,
     *     idempotency_key: string,
     *     correlation_id: string
     * } $data
     * @return int The newly generated update ID
     */
    public function insert(array $data): int
    {
        $sql = 'INSERT INTO inventory_updates (
                    room_id,
                    date_from,
                    date_to,
                    available_units,
                    price,
                    idempotency_key,
                    correlation_id
                ) VALUES (
                    :room_id,
                    :date_from,
                    :date_to,
                    :available_units,
                    :price,
                    :idempotency_key,
                    :correlation_id
                )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':room_id', $data['room_id'], PDO::PARAM_INT);
        $stmt->bindValue(':date_from', $data['date_from'], PDO::PARAM_STR);
        $stmt->bindValue(':date_to', $data['date_to'], PDO::PARAM_STR);

        if ($data['available_units'] === null) {
            $stmt->bindValue(':available_units', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':available_units', (int) $data['available_units'], PDO::PARAM_INT);
        }

        if ($data['price'] === null) {
            $stmt->bindValue(':price', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':price', $data['price'], PDO::PARAM_STR);
        }

        $stmt->bindValue(':idempotency_key', $data['idempotency_key'], PDO::PARAM_STR);
        $stmt->bindValue(':correlation_id', $data['correlation_id'], PDO::PARAM_STR);

        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Find an inventory update by its unique idempotency key.
     *
     * @param string $key
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM inventory_updates WHERE idempotency_key = :key LIMIT 1');
        $stmt->bindValue(':key', $key, PDO::PARAM_STR);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Find an inventory update by its primary key ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $sql = 'SELECT u.*, r.name AS room_name
                FROM inventory_updates u
                LEFT JOIN rooms r ON r.id = u.room_id
                WHERE u.id = :id
                LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Retrieve recent inventory updates joined with room details and queued job counts.
     *
     * @param int $limit Number of recent updates to retrieve (default 10)
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit = 10): array
    {
        // NOTE: We count all jobs belonging to the update regardless of channel active status
        // to keep the aggregation simple, predictable, and fully resolved in ONE grouped query.
        $sql = 'SELECT 
                    u.id,
                    u.room_id,
                    r.name AS room_name,
                    u.date_from,
                    u.date_to,
                    u.available_units,
                    u.price,
                    u.idempotency_key,
                    u.correlation_id,
                    u.created_at,
                    COUNT(j.id) AS total_jobs,
                    COALESCE(SUM(CASE WHEN j.status = "pending" THEN 1 ELSE 0 END), 0) AS pending_jobs,
                    COUNT(j.id) AS jobs_total,
                    COALESCE(SUM(CASE WHEN j.status = "success" THEN 1 ELSE 0 END), 0) AS jobs_success,
                    COALESCE(SUM(CASE WHEN j.status IN ("pending", "in_progress") THEN 1 ELSE 0 END), 0) AS jobs_pending,
                    COALESCE(SUM(CASE WHEN j.status = "failed" THEN 1 ELSE 0 END), 0) AS jobs_failed,
                    COALESCE(SUM(CASE WHEN j.status = "dead" THEN 1 ELSE 0 END), 0) AS jobs_dead
                FROM inventory_updates u
                INNER JOIN rooms r ON r.id = u.room_id
                LEFT JOIN sync_jobs j ON j.update_id = u.id
                GROUP BY u.id, u.room_id, r.name, u.date_from, u.date_to, u.available_units, u.price, u.idempotency_key, u.correlation_id, u.created_at
                ORDER BY u.id DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
