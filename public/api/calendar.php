<?php
declare(strict_types=1);

/**
 * Sync Guard - Inventory & Rate Calendar API Endpoint
 *
 * Compiles a room-by-room, date-by-date matrix of availability, rates, and operational sync states.
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
use SyncGuard\Services\CalendarService;
use SyncGuard\Support\JsonResponse;

header('Cache-Control: no-store');

// 1. Restrict strictly to HTTP GET
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    JsonResponse::error('Method Not Allowed. Only GET is accepted.', 405);
}

// 2. Validate 'days' parameter: 7, 14 (default), or 28
$rawDays = $_GET['days'] ?? '14';
if (!is_string($rawDays) || !in_array($rawDays, ['7', '14', '28'], true)) {
    JsonResponse::error('Validation failed. Days parameter must be 7, 14, or 28.', 422, [
        'days' => 'Days parameter must be 7, 14, or 28.',
    ]);
}

$days = (int) $rawDays;

try {
    $pdo = Connection::get();
    $service = new CalendarService($pdo);

    $data = $service->getCalendarData($days);

    JsonResponse::success($data);

} catch (\Throwable $e) {
    error_log('Unexpected error in calendar.php: ' . $e->getMessage());
    JsonResponse::error('An unexpected server error occurred.', 500);
}
