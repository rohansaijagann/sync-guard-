<?php
declare(strict_types=1);

/**
 * Sync Guard - Database Setup Script
 *
 * Usage:
 *   php bin/setup_db.php          (Creates tables and inserts seed data idempotently)
 *   php bin/setup_db.php --fresh  (Drops existing tables, recreates schema, inserts seed data)
 */

// PSR-4 Autoloading with automatic fallback if vendor/ has not been generated yet
$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'SyncGuard\\';
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

use SyncGuard\Database\Connection;

echo "====================================================\n";
echo "           Sync Guard Database Setup                \n";
echo "====================================================\n\n";

$isFresh = in_array('--fresh', $argv, true);
$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = $config['app']['env'] ?? 'local';

// Guard against accidental wipes in non-local environments
if ($isFresh && strtolower($appEnv) !== 'local') {
    echo "WARNING: APP_ENV is set to '{$appEnv}'.\n";
    echo "Running with --fresh will DROP ALL TABLES and ERASE DATA.\n";
    echo "Type 'yes' to proceed: ";
    $handle = fopen('php://stdin', 'r');
    $confirmation = trim((string) fgets($handle));
    fclose($handle);

    if (strtolower($confirmation) !== 'yes') {
        echo "Aborted by user.\n";
        exit(1);
    }
}

try {
    $pdo = Connection::get();
    echo "[OK] Connected to MySQL ({$config['db']['name']} on {$config['db']['host']}:{$config['db']['port']})\n";

    if ($isFresh) {
        echo "[INFO] --fresh flag detected. Dropping existing tables...\n";
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $tables = [
            'sync_attempts',
            'sync_jobs',
            'inventory_updates',
            'channel_health',
            'channels',
            'rooms',
        ];
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`;");
            echo "  - Dropped table `{$table}` (if existed)\n";
        }
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        echo "[OK] All tables dropped successfully.\n\n";
    }

    // 1. Execute schema.sql
    $schemaPath = dirname(__DIR__) . '/db/schema.sql';
    if (!file_exists($schemaPath)) {
        throw new RuntimeException("Schema file not found at: {$schemaPath}");
    }
    echo "[INFO] Executing schema.sql...\n";
    $schemaSql = file_get_contents($schemaPath);
    if ($schemaSql === false) {
        throw new RuntimeException("Failed to read schema file: {$schemaPath}");
    }
    $pdo->exec($schemaSql);
    echo "[OK] Database schema created successfully.\n\n";

    // 2. Execute seed.sql
    $seedPath = dirname(__DIR__) . '/db/seed.sql';
    if (!file_exists($seedPath)) {
        throw new RuntimeException("Seed file not found at: {$seedPath}");
    }
    echo "[INFO] Executing seed.sql...\n";
    $seedSql = file_get_contents($seedPath);
    if ($seedSql === false) {
        throw new RuntimeException("Failed to read seed file: {$seedPath}");
    }
    $pdo->exec($seedSql);
    echo "[OK] Seed data inserted successfully.\n\n";

    echo "====================================================\n";
    echo "Database setup completed successfully!\n";
    echo "Run `php bin/verify_setup.php` to verify the environment.\n";
    echo "====================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[ERROR] Setup failed: " . $e->getMessage() . "\n";
    if (!empty($config['app']['debug'])) {
        echo "Trace:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}
