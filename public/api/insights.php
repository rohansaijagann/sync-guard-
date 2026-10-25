<?php
declare(strict_types=1);

/**
 * Sync Guard - Operational Insights API Endpoint
 *
 * Provides Time to Consistency statistics, 24-hour delivery volume & failure breakdown,
 * and recent delivery activity feed.
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
use SyncGuard\Services\InsightsService;
use SyncGuard\Support\JsonResponse;

header('Cache-Control: no-store');

// 1. Restrict strictly to HTTP GET
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    JsonResponse::error('Method Not Allowed. Only GET is accepted.', 405);
}

try {
    $config = require dirname(__DIR__, 2) . '/config/config.php';
    $windowHours = (int) ($config['insights']['window_hours'] ?? 24);

    $pdo = Connection::get();
    $service = new InsightsService($pdo, $windowHours);

    $data = $service->getInsightsData();

    JsonResponse::success($data);

} catch (\Throwable $e) {
    error_log('Unexpected error in insights.php: ' . $e->getMessage());
    JsonResponse::error('An unexpected server error occurred.', 500);
}
