<?php
declare(strict_types=1);

/**
 * Sync Guard - Real-Time System Status API Endpoint
 *
 * Provides live telemetry on channel health, worker heartbeat,
 * queue status, and at-risk room inventory.
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
use SyncGuard\Services\StatusService;
use SyncGuard\Support\JsonResponse;

// 1. Prevent client-side or proxy caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 2. Restrict strictly to HTTP GET
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    JsonResponse::error('Method Not Allowed. Only GET is accepted.', 405);
}

try {
    $pdo = Connection::get();
    $statusService = new StatusService($pdo);
    $data = $statusService->getStatusData();

    JsonResponse::success($data, 200);

} catch (Throwable $e) {
    // Never leak stack traces, database schema, or internal credentials to the client
    error_log(sprintf('[API Status Error] %s at %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    JsonResponse::error('Failed to retrieve system status.', 500);
}
