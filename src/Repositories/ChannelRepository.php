<?php

declare(strict_types=1);

namespace SyncGuard\Repositories;

use PDO;

/**
 * Class ChannelRepository
 *
 * Encapsulates all SQL queries relating to the `channels` table.
 * Strictly uses prepared statements to ensure parameter safety.
 */
class ChannelRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Retrieve all active booking channels (is_active = 1) ordered by ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveChannels(): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, adapter_class, endpoint_url, is_active FROM channels WHERE is_active = 1 ORDER BY id ASC');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Retrieve all booking channels regardless of active status.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, adapter_class, endpoint_url, is_active FROM channels ORDER BY id ASC');
        return $stmt->fetchAll();
    }

    /**
     * Find a channel by its primary key ID.
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, adapter_class, endpoint_url, is_active FROM channels WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }
}
