<?php
declare(strict_types=1);

/**
 * Sync Guard - Bulk Retry Dead Jobs API Endpoint
 *
 * Re-queues all dead jobs back to 'pending' with attempt_count = 0 for immediate execution.
 *
 * SECURITY NOTE:
 * Login and CSRF protection are intentionally NOT built yet in this learning project.
 * Until full authentication is implemented, this endpoint has the same exposure as /api/retry.php,
 * so this application must strictly be run in local/demo environments only.
 */

// PSR-4 Autoloading
$vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'SyncGuard\\';
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

use SyncGuard\Database\Connection;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Support\JsonResponse;

header('Cache-Control: no-store');

// 1. Restrict strictly to HTTP POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    JsonResponse::error('Method Not Allowed. Only POST is accepted.', 405);
}

try {
    $pdo = Connection::get();
    $jobRepo = new JobRepository($pdo);

    $retriedCount = $jobRepo->retryAllDead();

    error_log(sprintf('[API] Bulk retry dead jobs: %d job(s) reset to pending.', $retriedCount));

    $message = $retriedCount > 0
        ? sprintf('Queued %d dead job%s for immediate retry.', $retriedCount, $retriedCount === 1 ? '' : 's')
        : 'No dead jobs to retry.';

    JsonResponse::success([
        'retried' => $retriedCount,
        'message' => $message,
    ]);

} catch (\Throwable $e) {
    error_log('Unexpected error in retry_dead.php: ' . $e->getMessage());
    JsonResponse::error('An unexpected server error occurred.', 500);
}
