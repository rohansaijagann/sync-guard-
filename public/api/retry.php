<?php
declare(strict_types=1);

/**
 * Sync Guard - Manual Job Retry API Endpoint
 *
 * Resets a 'failed' or 'dead' sync job back to 'pending' with attempt_count = 0.
 *
 * NOTE: Authentication, user sessions, and CSRF protection are scheduled for production hardening.
 * Front-end manual retry controls are provided in the Sync Log.
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

// 1. Restrict strictly to HTTP POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    JsonResponse::error('Method Not Allowed. Only POST is accepted.', 405);
}

// 2. Parse request input (supports both standard form-encoded POST and JSON bodies)
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

$rawJobId = $input['job_id'] ?? null;

// 3. Validation: job_id must be integer greater than 0
if ($rawJobId === null || !is_numeric($rawJobId) || (int) $rawJobId <= 0 || (string)(int)$rawJobId !== (string)$rawJobId) {
    JsonResponse::error('Invalid job ID provided. Must be an integer greater than 0.', 422);
}

$jobId = (int) $rawJobId;
$pdo = Connection::get();
$jobRepo = new JobRepository($pdo);

// 4. Look up existing job state before atomic retry (to capture channel and status for logging)
$stmt = $pdo->prepare('
    SELECT j.id, j.status, j.attempt_count, c.name AS channel_name 
    FROM sync_jobs j
    LEFT JOIN channels c ON c.id = j.channel_id
    WHERE j.id = :id
    LIMIT 1
');
$stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
$stmt->execute();
$existingJob = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$existingJob) {
    JsonResponse::error("Job #{$jobId} not found.", 404);
}

$previousStatus = (string) $existingJob['status'];
$previousAttempts = (int) $existingJob['attempt_count'];
$channelName = (string) ($existingJob['channel_name'] ?? 'Unknown Channel');

// 5. Execute atomic single conditional retry
$result = $jobRepo->retry($jobId);

if ($result === 'retried') {
    // Log manual retry telemetry to PHP error_log (Audit table planned for production)
    error_log(sprintf(
        '[MANUAL RETRY] Job #%d (Channel: %s) queued for retry. Previous Status: %s, Previous Attempts: %d',
        $jobId,
        $channelName,
        $previousStatus,
        $previousAttempts
    ));

    JsonResponse::success([
        'job_id'  => $jobId,
        'channel' => $channelName,
        'status'  => 'pending',
        'message' => 'Job queued for retry.',
    ], 200);
}

// If retry returned not_retryable, provide clear plain-language description based on current state
$message = match ($previousStatus) {
    'success'     => 'This job already succeeded.',
    'pending'     => 'This job is already waiting to be sent.',
    'in_progress' => 'This job is currently being processed by a worker.',
    default       => "Job cannot be retried in '{$previousStatus}' state.",
};

JsonResponse::error($message, 409);
