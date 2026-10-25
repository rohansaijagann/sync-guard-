<?php
declare(strict_types=1);

/**
 * Sync Guard - Channel Health Rebuild CLI Utility
 *
 * Recomputes channel_health table rows for all booking channels
 * based on the most recent delivery attempts in sync_attempts.
 *
 * Usage:
 *   php bin/rebuild_health.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/rebuild_health.php must be run from the CLI.\n");
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
use SyncGuard\Services\HealthService;

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');
if (strtolower($appEnv) !== 'local') {
    fwrite(STDERR, sprintf("[ERROR] rebuild_health is only allowed in 'local' environment (current: '%s').\n", $appEnv));
    exit(1);
}

$window = (int) ($config['health']['window_attempts'] ?? HealthService::DEFAULT_WINDOW_ATTEMPTS);

$pdo = Connection::get();
$healthRepo = new HealthRepository($pdo);

echo "====================================================\n";
echo "           Sync Guard - Rebuild Channel Health      \n";
echo "====================================================\n";
echo sprintf("Evaluation Window: Last %d attempts per channel\n\n", $window);

$results = $healthRepo->rebuildAll($window);

echo str_repeat('-', 90) . "\n";
printf(
    "%-4s | %-10s | %-12s | %-11s | %-12s | %-10s | %-19s\n",
    'ID', 'Channel', 'Success Rate', 'Avg Latency', 'Consec Fail', 'Status', 'Last Success'
);
echo str_repeat('-', 90) . "\n";

foreach ($results as $r) {
    $hasData = ($r['last_success_at'] !== null || $r['last_failure_at'] !== null);
    $status = HealthService::labelFor($hasData, (int) $r['consecutive_failures'], (float) $r['success_rate']);

    printf(
        "%-4d | %-10s | %10.2f%% | %8d ms | %11d | %-10s | %-19s\n",
        $r['channel_id'],
        $r['channel_name'] ?? ('Channel #' . $r['channel_id']),
        $r['success_rate'],
        $r['avg_response_ms'],
        $r['consecutive_failures'],
        strtoupper($status),
        $r['last_success_at'] ?? 'Never'
    );
}
echo str_repeat('-', 90) . "\n";
echo "[OK] Channel health recomputed successfully.\n\n";
