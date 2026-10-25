<?php
declare(strict_types=1);

/**
 * Sync Guard - Inventory Update API Endpoint
 *
 * Handles staff room availability and rate modification submissions.
 * Only HTTP POST is supported.
 *
 * Note: Authentication, authorization, and CSRF protection are scheduled for production hardening.
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
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Services\InventoryService;
use SyncGuard\Support\JsonResponse;
use SyncGuard\Validation\InventoryUpdateValidator;

// Restrict strictly to HTTP POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::error('Method Not Allowed. Only POST is accepted.', 405);
}

// Parse request payload: support both standard form-encoded POST and JSON request bodies
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$input = [];

if (str_contains(strtolower($contentType), 'application/json')) {
    $rawBody = file_get_contents('php://input');
    if ($rawBody !== false && trim($rawBody) !== '') {
        $parsed = json_decode($rawBody, true);
        if (is_array($parsed)) {
            $input = $parsed;
        } else {
            JsonResponse::error('Invalid JSON payload provided.', 400);
        }
    }
} else {
    $input = $_POST;
}

try {
    $pdo = Connection::get();

    // Dependency Injection: assemble service dependencies
    $roomRepo = new RoomRepository($pdo);
    $channelRepo = new ChannelRepository($pdo);
    $updateRepo = new UpdateRepository($pdo);
    $jobRepo = new JobRepository($pdo);
    $validator = new InventoryUpdateValidator();

    $service = new InventoryService(
        $pdo,
        $roomRepo,
        $channelRepo,
        $updateRepo,
        $jobRepo,
        $validator
    );

    $result = $service->createUpdate($input);

    if ($result['ok']) {
        JsonResponse::success($result['data'] ?? [], $result['statusCode']);
    } else {
        JsonResponse::error($result['error'] ?? 'An error occurred.', $result['statusCode'], $result['fields'] ?? []);
    }

} catch (Throwable $e) {
    error_log('Unexpected API failure in inventory.php: ' . $e->getMessage());
    JsonResponse::error('An unexpected server error occurred.', 500);
}
