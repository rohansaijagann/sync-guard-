<?php
declare(strict_types=1);

/**
 * Sync Guard - Sync Jobs Query & Pagination API Endpoint
 *
 * Provides a paginated, filterable list of sync jobs for operational monitoring.
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
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Services\JobLogService;
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
    $channelRepo = new ChannelRepository($pdo);
    $roomRepo = new RoomRepository($pdo);

    $fields = [];

    // 3. Validation: status
    $allowedStatuses = ['all', 'unsynced', 'pending', 'in_progress', 'failed', 'dead', 'success'];
    $rawStatus = $_GET['status'] ?? 'all';
    if (!is_string($rawStatus) || !in_array($rawStatus, $allowedStatuses, true)) {
        $fields['status'] = 'Status must be one of: ' . implode(', ', $allowedStatuses) . '.';
    }
    $status = is_string($rawStatus) && in_array($rawStatus, $allowedStatuses, true) ? $rawStatus : 'all';

    // 4. Validation: channel_id
    $channelId = null;
    if (isset($_GET['channel_id']) && trim((string) $_GET['channel_id']) !== '') {
        $rawChannelId = (string) $_GET['channel_id'];
        if (!ctype_digit($rawChannelId) || (int) $rawChannelId <= 0) {
            $fields['channel_id'] = 'Channel ID must be a positive integer.';
        } else {
            $cId = (int) $rawChannelId;
            if ($channelRepo->findById($cId) === null) {
                $fields['channel_id'] = 'Channel not found.';
            } else {
                $channelId = $cId;
            }
        }
    }

    // 5. Validation: room_id
    $roomId = null;
    if (isset($_GET['room_id']) && trim((string) $_GET['room_id']) !== '') {
        $rawRoomId = (string) $_GET['room_id'];
        if (!ctype_digit($rawRoomId) || (int) $rawRoomId <= 0) {
            $fields['room_id'] = 'Room ID must be a positive integer.';
        } else {
            $rId = (int) $rawRoomId;
            if ($roomRepo->findById($rId) === null) {
                $fields['room_id'] = 'Room not found.';
            } else {
                $roomId = $rId;
            }
        }
    }

    // 6. Validation: page
    $page = 1;
    if (isset($_GET['page']) && trim((string) $_GET['page']) !== '') {
        $rawPage = (string) $_GET['page'];
        if (!ctype_digit($rawPage) || (int) $rawPage < 1 || (int) $rawPage > 1000000) {
            $fields['page'] = 'Page must be an integer between 1 and 1,000,000.';
        } else {
            $page = (int) $rawPage;
        }
    }

    // 7. Validation: per_page
    $perPage = 25;
    if (isset($_GET['per_page']) && trim((string) $_GET['per_page']) !== '') {
        $rawPerPage = (string) $_GET['per_page'];
        if (!ctype_digit($rawPerPage) || !in_array((int) $rawPerPage, [10, 25, 50], true)) {
            $fields['per_page'] = 'Rows per page must be one of: 10, 25, 50.';
        } else {
            $perPage = (int) $rawPerPage;
        }
    }

    // If any validation failed, return 422 with detailed field errors
    if (!empty($fields)) {
        JsonResponse::error('Validation failed.', 422, $fields);
    }

    // 8. Execute query service
    $service = new JobLogService($pdo, $channelRepo, $roomRepo);
    $data = $service->getJobsData($status, $channelId, $roomId, $page, $perPage);

    JsonResponse::success($data, 200);

} catch (Throwable $e) {
    // Never expose database schema, credentials, or stack traces
    error_log(sprintf('[API Jobs Error] %s at %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    JsonResponse::error('Failed to retrieve sync jobs.', 500);
}
