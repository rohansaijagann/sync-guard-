<?php

declare(strict_types=1);

namespace SyncGuard\Repositories;

use PDO;

/**
 * Class RoomRepository
 *
 * Encapsulates all SQL queries relating to the `rooms` table.
 * Strictly uses prepared statements to eliminate SQL injection vulnerabilities.
 */
class RoomRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Find a room by its primary key ID.
     *
     * @param int $id
     * @return array<string, mixed>|null Associative array of room data or null if not found
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, total_units, base_price FROM rooms WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Retrieve all rooms ordered by ID ascending.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, total_units, base_price FROM rooms ORDER BY id ASC');
        return $stmt->fetchAll();
    }
}
