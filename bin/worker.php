<?php
declare(strict_types=1);

/**
 * Sync Guard - Background Queue Worker Daemon
 *
 * Continuously polls the `sync_jobs` table, atomically claims due tasks,
 * and delivers them to external booking channels via channel adapters.
 *
 * Usage:
 *   php bin/worker.php                  (Runs continuous daemon loop)
 *   php bin/worker.php --once           (Runs a single polling pass and exits)
 *   php bin/worker.php --batch=20       (Claims up to 20 jobs per pass)
 *   php bin/worker.php --interval=5     (Sleeps 5 seconds between passes)
 *   php bin/worker.php --only-jobs=1,2  (Restricts processing to specific job IDs - TESTING ONLY)
 *
 * KNOWN LIMITATION (Phase 4):
 * The worker currently processes claimed jobs sequentially (one after another).
 * Consequently, a slow external channel (like Site B taking 2-6 seconds) delays any
 * subsequent jobs queued behind it by up to the HTTP timeout (5 seconds). The strict
 * timeout is what keeps this delay bounded and prevents worker stalls.
 * Future architectural improvements include:
 *   1. Parallel worker processes (or multi-threaded queues) partitioned by channel.
 *   2. Dedicated per-site/per-channel queues so slow channels don't block fast channels.
 *   3. Granular per-channel configurable timeouts (e.g., fast channels: 2s, slow channels: 10s).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/worker.php must be executed from the CLI.\n");
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

use SyncGuard\Adapters\AdapterResolver;
use SyncGuard\Database\Connection;
use SyncGuard\Repositories\AttemptRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Retry\Backoff;
use SyncGuard\Support\AlertWriter;
use SyncGuard\Support\Heartbeat;
use SyncGuard\Worker\JobProcessor;

$config = require dirname(__DIR__) . '/config/config.php';

// Parse CLI command-line arguments
$longOpts = [
    'once',
    'interval::',
    'batch::',
    'only-jobs::',
];
$options = getopt('', $longOpts);

$runOnce = isset($options['once']);
$intervalSeconds = isset($options['interval'])
    ? max(1, (int) $options['interval'])
    : max(1, (int) ($config['worker']['interval_seconds'] ?? 3));

$batchSize = isset($options['batch'])
    ? max(1, (int) $options['batch'])
    : max(1, (int) ($config['worker']['batch_size'] ?? 10));

$stuckThreshold = (int) ($config['worker']['stuck_after_seconds'] ?? 300);

$onlyJobIds = null;
if (isset($options['only-jobs']) && is_string($options['only-jobs'])) {
    $rawIds = array_filter(array_map('trim', explode(',', $options['only-jobs'])));
    $onlyJobIds = array_map('intval', $rawIds);
}

// Graceful signal handling via pcntl (if available)
$stopRequested = false;
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    $signalHandler = function (int $signal) use (&$stopRequested): void {
        echo sprintf("\n[%s UTC] Received stop signal (%d). Completing active cycle and shutting down...\n", gmdate('Y-m-d H:i:s'), $signal);
        $stopRequested = true;
    };
    pcntl_signal(SIGTERM, $signalHandler);
    pcntl_signal(SIGINT, $signalHandler);
} else {
    // Note: On Windows or environments without pcntl, signals terminate the process directly.
}

echo "====================================================\n";
echo "           Sync Guard - Background Worker           \n";
echo "====================================================\n";
echo sprintf("Mode: %s | Batch: %d | Interval: %ds | Stuck Threshold: %ds\n", $runOnce ? 'Single-Pass (--once)' : 'Continuous Daemon', $batchSize, $intervalSeconds, $stuckThreshold);
if ($onlyJobIds !== null) {
    echo sprintf("Restricted Job Filter: [%s] (Testing Mode)\n", implode(', ', $onlyJobIds));
}
echo "Starting queue listener...\n\n";

$workerMode = $runOnce ? 'once' : 'loop';

do {
    // 1. Record worker heartbeat at start of pass
    Heartbeat::record($workerMode, $intervalSeconds);

    try {
        $pdo = Connection::get();
        $jobRepo = new JobRepository($pdo);
        $updateRepo = new UpdateRepository($pdo);
        $attemptRepo = new AttemptRepository($pdo);
        $adapterResolver = new AdapterResolver();

        $processor = new JobProcessor(
            $pdo,
            $jobRepo,
            $updateRepo,
            $attemptRepo,
            $adapterResolver,
            $stuckThreshold,
            function (string $msg): void {
                echo sprintf("[%s UTC] %s\n", gmdate('Y-m-d H:i:s'), $msg);
            },
            new Backoff((int) ($config['retry']['jitter_percent'] ?? 10)),
            new AlertWriter()
        );

        $counts = $processor->runOnce($batchSize, $onlyJobIds);

        // Print concise pass summary with UTC timestamp
        $timestamp = gmdate('Y-m-d H:i:s');
        $claimed = $counts['succeeded'] + $counts['failed'] + ($counts['dead'] ?? 0);
        echo sprintf(
            "[%s UTC] Pass: found=%d | claimed=%d | succeeded=%d | failed=%d | dead=%d | skipped=%d | lost=%d | recovered=%d\n",
            $timestamp,
            $counts['found'],
            $claimed,
            $counts['succeeded'],
            $counts['failed'],
            $counts['dead'] ?? 0,
            $counts['skipped_no_adapter'],
            $counts['lost_race'],
            $counts['recovered']
        );

    } catch (PDOException $e) {
        // Connection drops: print diagnostic message, reset singleton, and sleep to prevent CPU spinning
        echo sprintf("[%s UTC] [DB_ERROR] Database connection lost: %s. Reconnecting in %ds...\n", gmdate('Y-m-d H:i:s'), $e->getMessage(), $intervalSeconds);
        Connection::reset();
    } catch (Throwable $e) {
        echo sprintf("[%s UTC] [FATAL] Worker unexpected failure: %s\n", gmdate('Y-m-d H:i:s'), $e->getMessage());
    }

    // 2. Record worker heartbeat at end of pass
    Heartbeat::record($workerMode, $intervalSeconds);

    if ($runOnce || $stopRequested) {
        break;
    }

    sleep($intervalSeconds);

} while (!$stopRequested);

echo sprintf("[%s UTC] Worker stopped cleanly.\n", gmdate('Y-m-d H:i:s'));
exit(0);
