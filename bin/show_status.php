<?php
declare(strict_types=1);

/**
 * Sync Guard - Diagnostic Database Status Viewer (CLI)
 *
 * Displays job status distribution, per-job retry progress, and attempt telemetry.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/show_status.php must be run from the CLI.\n");
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

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');
if (strtolower($appEnv) !== 'local') {
    fwrite(STDERR, sprintf("[ERROR] show_status is only allowed in 'local' environment (current: '%s').\n", $appEnv));
    exit(1);
}

$pdo = Connection::get();

echo "====================================================\n";
echo "           Sync Guard - Database Status             \n";
echo "====================================================\n\n";

// Status Counts Summary
$statusRows = $pdo->query('
    SELECT status, COUNT(*) AS cnt 
    FROM sync_jobs 
    GROUP BY status
')->fetchAll(PDO::FETCH_KEY_PAIR);

$totalJobs = array_sum($statusRows);
echo "--- JOB STATUS SUMMARY ---\n";
echo sprintf(
    "Total: %d | Pending: %d | In Progress: %d | Failed: %d | Dead: %d | Success: %d\n\n",
    $totalJobs,
    $statusRows['pending'] ?? 0,
    $statusRows['in_progress'] ?? 0,
    $statusRows['failed'] ?? 0,
    $statusRows['dead'] ?? 0,
    $statusRows['success'] ?? 0
);

echo "--- SYNC JOBS ---\n";
$jobs = $pdo->query('
    SELECT j.id, c.name AS channel, j.status, j.attempt_count, j.last_error, j.next_attempt_at 
    FROM sync_jobs j 
    JOIN channels c ON c.id = j.channel_id
    ORDER BY j.id ASC
')->fetchAll(PDO::FETCH_ASSOC);

if (empty($jobs)) {
    echo "No sync jobs found in queue.\n";
} else {
    foreach ($jobs as $j) {
        echo sprintf(
            "Job #%d | %-8s | Status: %-11s | Attempts: %d | Next Due: %s | Error: %s\n",
            $j['id'],
            $j['channel'],
            strtoupper($j['status']),
            $j['attempt_count'],
            $j['next_attempt_at'] ?? 'null',
            $j['last_error'] ?? 'none'
        );
    }
}

echo "\n--- SYNC ATTEMPTS (Last 15) ---\n";
$attempts = $pdo->query('
    SELECT a.id, a.job_id, c.name AS channel, a.outcome, a.http_status, a.duration_ms, a.message, a.started_at
    FROM sync_attempts a
    JOIN sync_jobs j ON j.id = a.job_id
    JOIN channels c ON c.id = j.channel_id
    ORDER BY a.id DESC
    LIMIT 15
')->fetchAll(PDO::FETCH_ASSOC);

if (empty($attempts)) {
    echo "No attempt records logged yet.\n";
} else {
    foreach (array_reverse($attempts) as $a) {
        echo sprintf(
            "Attempt #%d | Job #%d (%-8s) | Outcome: %-10s | HTTP: %-4s | Latency: %4dms | Started: %s | Msg: %s\n",
            $a['id'],
            $a['job_id'],
            $a['channel'],
            $a['outcome'],
            $a['http_status'] ?? 'null',
            $a['duration_ms'],
            $a['started_at'],
            $a['message']
        );
    }
}
echo "\n";
