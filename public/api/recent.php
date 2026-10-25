<?php
declare(strict_types=1);

/**
 * Sync Guard - Recent Inventory Updates API Endpoint
 *
 * Returns the 10 most recent updates with their associated room names and queued job counts.
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
use SyncGuard\Support\Time;
use SyncGuard\Validation\InventoryUpdateValidator;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    JsonResponse::error('Method Not Allowed. Only GET is accepted.', 405);
}

try {
    $pdo = Connection::get();

    $service = new InventoryService(
        $pdo,
        new RoomRepository($pdo),
        new ChannelRepository($pdo),
        new UpdateRepository($pdo),
        new JobRepository($pdo),
        new InventoryUpdateValidator()
    );

    $rawUpdates = $service->getRecentUpdates(10);

    // Format timestamps to JST for client presentation
    $formatted = array_map(function (array $u) {
        return [
            'id'              => (int) $u['id'],
            'room_id'         => (int) $u['room_id'],
            'room_name'       => (string) $u['room_name'],
            'date_from'       => (string) $u['date_from'],
            'date_to'         => (string) $u['date_to'],
            'available_units' => $u['available_units'] !== null ? (int) $u['available_units'] : null,
            'price'           => $u['price'] !== null ? (float) $u['price'] : null,
            'idempotency_key' => (string) $u['idempotency_key'],
            'correlation_id'  => (string) $u['correlation_id'],
            'created_at_utc'  => (string) $u['created_at'],
            'created_at_jst'  => Time::utcToJst((string) $u['created_at']),
            'total_jobs'      => (int) $u['total_jobs'],
            'pending_jobs'    => (int) $u['pending_jobs'],
            // Additive granular delivery state counters for recent updates table badge
            'jobs_total'      => (int) ($u['jobs_total'] ?? $u['total_jobs']),
            'jobs_success'    => (int) ($u['jobs_success'] ?? 0),
            'jobs_pending'    => (int) ($u['jobs_pending'] ?? 0),
            'jobs_failed'     => (int) ($u['jobs_failed'] ?? 0),
            'jobs_dead'       => (int) ($u['jobs_dead'] ?? 0),
        ];
    }, $rawUpdates);

    JsonResponse::success($formatted, 200);

} catch (Throwable $e) {
    error_log('Recent updates API error: ' . $e->getMessage());
    JsonResponse::error('Failed to load recent updates.', 500);
}
