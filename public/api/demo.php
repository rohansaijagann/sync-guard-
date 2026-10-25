<?php
declare(strict_types=1);

/**
 * Sync Guard - Demo & Chaos Control API Endpoint
 *
 * Provides gated controls to simulate channel outages, submit synthetic demo updates,
 * fast-forward retry backoff waits, and trigger worker execution passes.
 *
 * GATE POLICY:
 * Enabled strictly when APP_ENV === 'local' AND config DEMO_CONTROLS_ENABLED is true.
 * When disabled, every request returns HTTP 404 (pretending the endpoint does not exist)
 * to ensure chaos and demo buttons cannot be triggered in production or staging environments.
 *
 * SECURITY & CSRF NOTE:
 * Real session cookies and CSRF tokens are intentionally not built into this learning project.
 * As light defense-in-depth, this endpoint enforces application/json Content-Type,
 * requires an X-Requested-With header, and validates the Origin header against the Host.
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

use SyncGuard\Adapters\AdapterResolver;
use SyncGuard\Database\Connection;
use SyncGuard\Repositories\AttemptRepository;
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\HealthRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Services\InventoryService;
use SyncGuard\Support\Heartbeat;
use SyncGuard\Support\JsonResponse;
use SyncGuard\Support\MockControl;
use SyncGuard\Support\Time;
use SyncGuard\Support\Uuid;
use SyncGuard\Validation\InventoryUpdateValidator;
use SyncGuard\Worker\JobProcessor;

header('Cache-Control: no-store');

$config = require dirname(__DIR__, 2) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');
$demoEnabled = filter_var($config['demo']['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

// 1. Strict Gate Check: pretend endpoint does not exist if not local or disabled
if ($appEnv !== 'local' || !$demoEnabled) {
    JsonResponse::error('Not found.', 404);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// 2. GET Method: Return current simulation configuration
if ($method === 'GET') {
    $currentModes = MockControl::read();
    JsonResponse::success([
        'enabled' => true,
        'modes'   => $currentModes,
        'allowed' => MockControl::ALLOWED_MODES,
    ]);
}

// 3. Only GET and POST are supported
if ($method !== 'POST') {
    header('Allow: GET, POST');
    JsonResponse::error('Method Not Allowed. Only GET and POST are accepted.', 405);
}

// 4. Request Header & Origin Validation (Light Anti-Tampering Checks)
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
if (!str_contains(strtolower($contentType), 'application/json')) {
    JsonResponse::error('Unsupported Media Type. Content-Type must be application/json.', 415);
}

$requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
if (strtolower($requestedWith) !== 'xmlhttprequest') {
    JsonResponse::error('Forbidden: Missing or invalid X-Requested-With header.', 403);
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = parse_url($origin, PHP_URL_HOST);
    $originPort = parse_url($origin, PHP_URL_PORT);
    $originHostPort = $originHost . ($originPort ? ":{$originPort}" : '');

    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    if (strtolower($originHostPort) !== strtolower($requestHost)) {
        JsonResponse::error('Forbidden: Cross-origin request refused.', 403);
    }
}

// 5. Body Size Limit: Max 4 KB (4096 bytes)
$rawBody = file_get_contents('php://input');
if ($rawBody === false || strlen($rawBody) > 4096) {
    JsonResponse::error('Payload Too Large: Request body exceeds 4 KB limit.', 413);
}

$input = json_decode($rawBody, true);
if (!is_array($input)) {
    JsonResponse::error('Invalid JSON payload provided.', 400);
}

$action = (string) ($input['action'] ?? '');
$allowedActions = ['set_mode', 'reset_modes', 'submit_demo_update', 'skip_wait', 'run_worker', 'reset_queue'];
if (!in_array($action, $allowedActions, true)) {
    JsonResponse::error("Unprocessable Entity: Unknown action '{$action}'.", 422);
}

try {
    $pdo = Connection::get();

    switch ($action) {
        // ACTION 1: Set Mock Partner Mode
        case 'set_mode':
            $site = strtolower((string) ($input['site'] ?? ''));
            $mode = strtolower((string) ($input['mode'] ?? ''));

            if (!isset(MockControl::ALLOWED_MODES[$site]) || !in_array($mode, MockControl::ALLOWED_MODES[$site], true)) {
                JsonResponse::error("Invalid site or mode specified for 'set_mode'.", 422);
            }

            if (!MockControl::setMode($site, $mode)) {
                JsonResponse::error('Failed to update mock control configuration.', 500);
            }

            JsonResponse::success([
                'action'  => 'set_mode',
                'site'    => $site,
                'mode'    => $mode,
                'message' => sprintf('Set %s mode to %s.', strtoupper(str_replace('_', ' ', $site)), $mode),
            ]);

        // ACTION 2: Reset Mock Partner Modes to Defaults
        case 'reset_modes':
            if (!MockControl::reset()) {
                JsonResponse::error('Failed to reset mock control configuration.', 500);
            }

            JsonResponse::success([
                'action'  => 'reset_modes',
                'modes'   => MockControl::DEFAULT_STATE,
                'message' => 'Reset mock partner modes to defaults (Site B: normal, Site C: random).',
            ]);

        // ACTION 3: Submit Synthetic Demo Inventory Update
        case 'submit_demo_update':
            $roomRepo = new RoomRepository($pdo);
            $rooms = $roomRepo->getAll();
            if (empty($rooms)) {
                JsonResponse::error('No rooms configured in database.', 422);
            }

            $chosenRoom = $rooms[array_rand($rooms)];
            $roomId = (int) $chosenRoom['id'];
            $roomName = (string) $chosenRoom['name'];
            $maxUnits = max(1, (int) $chosenRoom['total_units']);

            $todayTokyo = Time::todayInTokyo();
            $dateFrom = date('Y-m-d', strtotime('+1 day', strtotime($todayTokyo)));
            $dateTo   = date('Y-m-d', strtotime('+3 days', strtotime($todayTokyo)));
            $units    = random_int(0, $maxUnits);
            $price    = random_int(50, 500) * 100; // Multiples of 100 between 5,000 and 50,000

            $invService = new InventoryService(
                pdo: $pdo,
                validator: new InventoryUpdateValidator(),
                updateRepo: new UpdateRepository($pdo),
                jobRepo: new JobRepository($pdo),
                channelRepo: new ChannelRepository($pdo),
                roomRepo: $roomRepo
            );

            $createResult = $invService->createUpdate([
                'room_id'         => $roomId,
                'date_from'       => $dateFrom,
                'date_to'         => $dateTo,
                'available_units' => $units,
                'price'           => $price,
                'idempotency_key' => Uuid::v4(),
            ]);

            $updateId = (int) $createResult['data']['update_id'];
            $jobsQueued = (int) $createResult['data']['jobs_queued'];

            JsonResponse::success([
                'action'      => 'submit_demo_update',
                'update_id'   => $updateId,
                'jobs_queued' => $jobsQueued,
                'room_id'     => $roomId,
                'room_name'   => $roomName,
                'date_from'   => $dateFrom,
                'date_to'     => $dateTo,
                'units'       => $units,
                'price'       => $price,
                'message'     => sprintf('Submitted demo update for %s (%d jobs queued).', $roomName, $jobsQueued),
            ]);

        // ACTION 4: Skip Retry Backoff Waits
        case 'skip_wait':
            $jobRepo = new JobRepository($pdo);
            $affected = $jobRepo->skipWait();

            JsonResponse::success([
                'action'   => 'skip_wait',
                'affected' => $affected,
                'message'  => sprintf('Fast-forwarded %d failed job%s to due now.', $affected, $affected === 1 ? '' : 's'),
            ]);

        // ACTION 5: Run In-Process Worker Pass
        case 'run_worker':
            $lockDir = dirname(__DIR__, 2) . '/storage';
            if (!is_dir($lockDir)) {
                mkdir($lockDir, 0777, true);
            }
            $lockFile = $lockDir . '/demo_worker.lock';
            $lockFp = @fopen($lockFile, 'c+');
            if ($lockFp) {
                @chmod($lockFile, 0666);
            }

            if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
                if ($lockFp) {
                    fclose($lockFp);
                }
                JsonResponse::error('A worker pass is already running.', 409);
            }

            @set_time_limit(60);

            try {
                // Note: Does NOT write to storage/worker_heartbeat.json.
                // The "Worker" pill on the dashboard reflects the real continuous daemon.
                // A one-off manual test pass should never fake worker daemon liveness.
                $jobRepo = new JobRepository($pdo);
                $updateRepo = new UpdateRepository($pdo);
                $attemptRepo = new AttemptRepository($pdo);
                $healthRepo = new HealthRepository($pdo);
                $adapterResolver = new AdapterResolver();

                $batchSize = (int) ($config['worker']['batch_size'] ?? 10);
                $stuckThreshold = (int) ($config['worker']['stuck_after_seconds'] ?? 300);

                $processor = new JobProcessor(
                    pdo: $pdo,
                    jobRepo: $jobRepo,
                    updateRepo: $updateRepo,
                    attemptRepo: $attemptRepo,
                    adapterResolver: $adapterResolver,
                    stuckThresholdSeconds: $stuckThreshold,
                    healthRepo: $healthRepo
                );

                $passCounters = $processor->runOnce($batchSize);

                JsonResponse::success([
                    'action'   => 'run_worker',
                    'counters' => $passCounters,
                    'message'  => sprintf(
                        'Worker pass complete: %d found, %d succeeded, %d failed, %d dead.',
                        $passCounters['found'],
                        $passCounters['succeeded'],
                        $passCounters['failed'],
                        $passCounters['dead']
                    ),
                ]);

            } finally {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }

        // ACTION 6: Reset Queue
        case 'reset_queue':
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM sync_attempts');
            $pdo->exec('DELETE FROM sync_jobs');
            $pdo->exec('DELETE FROM inventory_updates');
            $healthRepo = new HealthRepository($pdo);
            $healthRepo->resetAllToDefaults();
            $pdo->commit();

            $pdo->exec('ALTER TABLE sync_attempts AUTO_INCREMENT = 1');
            $pdo->exec('ALTER TABLE sync_jobs AUTO_INCREMENT = 1');
            $pdo->exec('ALTER TABLE inventory_updates AUTO_INCREMENT = 1');

            JsonResponse::success([
                'action'  => 'reset_queue',
                'message' => 'Queue reset successfully and channel health restored.',
            ]);
    }

} catch (\Throwable $e) {
    error_log('Unexpected error in demo.php: ' . $e->getMessage());
    JsonResponse::error('An unexpected server error occurred.', 500);
}
