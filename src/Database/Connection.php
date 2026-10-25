<?php

declare(strict_types=1);

namespace SyncGuard\Database;

use PDO;
use PDOException;

/**
 * Class Connection
 *
 * Manages the singleton PDO database connection for Sync Guard.
 * Configured strictly with UTF-8 (utf8mb4), UTC time zone, exceptions on error,
 * associative array fetching, and native prepared statements.
 */
class Connection
{
    /**
     * @var PDO|null Cached singleton PDO instance
     */
    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation (Singleton pattern).
     */
    private function __construct()
    {
    }

    /**
     * Returns the singleton PDO database connection.
     *
     * @param array<string, mixed>|null $customConfig Optional custom config for testing or override
     * @return PDO
     * @throws PDOException if connection fails
     */
    public static function get(?array $customConfig = null): PDO
    {
        if (self::$instance !== null && $customConfig === null) {
            return self::$instance;
        }

        // Load configuration array from config/config.php if not explicitly passed
        $config = $customConfig ?? (require dirname(__DIR__, 2) . '/config/config.php')['db'];

        $host    = (string) ($config['host'] ?? '127.0.0.1');
        $port    = (int)    ($config['port'] ?? 3306);
        $dbName  = (string) ($config['name'] ?? 'sync_guard');
        $user    = (string) ($config['user'] ?? 'sync_user');
        $pass    = (string) ($config['pass'] ?? 'sync_pass');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        // Build DSN string. Specifying charset=utf8mb4 in the DSN ensures the connection
        // handshake uses 4-byte UTF-8 immediately, supporting Japanese characters & emojis.
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);

        $options = [
            /**
             * WHY: Throw exceptions on database errors.
             * By default, PDO might fail silently or issue warnings. Setting ERRMODE_EXCEPTION
             * ensures errors (syntax errors, constraint failures, network drops) are immediately
             * caught and cannot be ignored, preventing corrupted application state.
             */
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

            /**
             * WHY: Default fetch mode as associative array.
             * Returns clean column-keyed arrays (e.g. ['room_id' => 1]) rather than duplicating
             * memory with both numeric indices and associative keys (PDO::FETCH_BOTH).
             */
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            /**
             * WHY: Disable prepared statement emulation.
             * Forces MySQL server to natively prepare statements. This ensures:
             * 1. True parameter binding preventing SQL injection edge cases.
             * 2. Native type returns (e.g. INT columns returned as PHP int, not strings).
             */
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, $user, $pass, $options);

        /**
         * WHY: Enforce UTC session timezone.
         * In hotel channel managers, synchronization jobs, retry intervals, and logs
         * coordinate across international time zones (e.g., booking sites in different regions).
         * Standardizing all database timestamps in UTC prevents daylight-saving confusion.
         * Display conversions (e.g. JST for hotel front desk) happen at the view layer.
         */
        $pdo->exec("SET time_zone = '+00:00'");

        if ($customConfig === null) {
            self::$instance = $pdo;
        }

        return $pdo;
    }

    /**
     * Alias for get() to support standard getInstance naming conventions.
     */
    public static function getInstance(): PDO
    {
        return self::get();
    }

    /**
     * Reset the cached instance (useful in automated tests or reconnections).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
