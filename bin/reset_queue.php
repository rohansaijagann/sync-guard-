<?php
declare(strict_types=1);

/**
 * Sync Guard - Outbox Queue Reset Utility (CLI)
 *
 * Safely purges test data from `sync_attempts`, `sync_jobs`, and `inventory_updates`
 * and resets their AUTO_INCREMENT counters to prepare for clean demonstrations.
 *
 * WHY REFUSE OUTSIDE LOCAL ENVIRONMENT:
 * Purging queue tables in staging or production would wipe operational audit trails
 * and abandon active OTA sync jobs in flight. Restricting this operation strictly to
 * APP_ENV=local prevents accidental destruction of customer or property data.
 *
 * INVARIANTS:
 * - NEVER deletes from `rooms`, `channels`, or `channel_health`.
 * - Executes deletes in an atomic database transaction.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/reset_queue.php must be run from the CLI.\n");
    exit(1);
}

// Autoloading
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
use SyncGuard\Repositories\HealthRepository;

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');

if (strtolower($appEnv) !== 'local') {
    fwrite(STDERR, sprintf("\n[ERROR] APP_ENV is set to '%s'. bin/reset_queue.php is strictly forbidden in non-local environments.\n", $appEnv));
    exit(1);
}

$pdo = Connection::get();

// Count existing records
$countAttempts = (int) $pdo->query('SELECT COUNT(*) FROM sync_attempts')->fetchColumn();
$countJobs     = (int) $pdo->query('SELECT COUNT(*) FROM sync_jobs')->fetchColumn();
$countUpdates  = (int) $pdo->query('SELECT COUNT(*) FROM inventory_updates')->fetchColumn();

echo "====================================================\n";
echo "           Sync Guard - Queue Reset Tool            \n";
echo "====================================================\n";
echo "Current queue records to be deleted:\n";
echo sprintf("  - sync_attempts     : %d rows\n", $countAttempts);
echo sprintf("  - sync_jobs         : %d rows\n", $countJobs);
echo sprintf("  - inventory_updates : %d rows\n", $countUpdates);
echo "  * channel_health rows will be reset to default (100% success rate, 0 failures).\n";
echo "  * Static tables (rooms, channels) will NOT be touched.\n\n";

$isConfirmed = in_array('-y', $argv, true) || in_array('--yes', $argv, true);

if (!$isConfirmed) {
    echo "Type 'yes' to proceed with queue reset: ";
    $handle = fopen('php://stdin', 'r');
    $input = trim((string) fgets($handle));
    fclose($handle);

    if (strtolower($input) !== 'yes') {
        echo "Aborted by user. No records were modified.\n";
        exit(0);
    }
}

try {
    $pdo->beginTransaction();

    // Delete in reverse foreign key dependency order
    $pdo->exec('DELETE FROM sync_attempts');
    $pdo->exec('DELETE FROM sync_jobs');
    $pdo->exec('DELETE FROM inventory_updates');

    // Reset channel_health metrics to default state (never delete rows)
    $healthRepo = new HealthRepository($pdo);
    $healthRepo->resetAllToDefaults();

    $pdo->commit();

    // Reset AUTO_INCREMENT counters
    $pdo->exec('ALTER TABLE sync_attempts AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE sync_jobs AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE inventory_updates AUTO_INCREMENT = 1');

    echo "\n[OK] Queue reset successfully! Tables are clean, channel_health is reset, and auto-increments reset to 1.\n";
    exit(0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\n[ERROR] Queue reset failed: " . $e->getMessage() . "\n");
    exit(1);
}
