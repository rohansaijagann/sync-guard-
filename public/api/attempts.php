<?php
declare(strict_types=1);

/**
 * Sync Guard - Job Attempt History API Endpoint
 *
 * Retrieves chronological delivery attempts and technical telemetry for a specific sync job.
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
use SyncGuard\Support\JsonResponse;
use SyncGuard\Support\Time;

// 1. Prevent client-side or proxy caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 2. Restrict strictly to HTTP GET
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    JsonResponse::error('Method Not Allowed. Only GET is accepted.', 405);
}

// 3. Validation: job_id
$rawJobId = $_GET['job_id'] ?? null;
if ($rawJobId === null || trim((string) $rawJobId) === '' || !ctype_digit((string) $rawJobId) || (int) $rawJobId <= 0) {
    JsonResponse::error('Invalid job ID provided. Must be a positive integer.', 422, [
        'job_id' => 'Job ID must be a positive integer.',
    ]);
}

$jobId = (int) $rawJobId;

try {
    $pdo = Connection::get();

    // 4. Fetch job summary metadata
    $stmtJob = $pdo->prepare('
        SELECT 
            j.id AS job_id,
            j.update_id,
            j.status,
            j.attempt_count,
            u.correlation_id,
            c.name AS channel_name,
            r.name AS room_name
        FROM sync_jobs j
        INNER JOIN inventory_updates u ON u.id = j.update_id
        INNER JOIN channels c ON c.id = j.channel_id
        INNER JOIN rooms r ON r.id = u.room_id
        WHERE j.id = :job_id
        LIMIT 1
    ');
    $stmtJob->bindValue(':job_id', $jobId, PDO::PARAM_INT);
    $stmtJob->execute();
    $job = $stmtJob->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        JsonResponse::error("Job #{$jobId} not found.", 404);
    }

    // 5. Check total attempt count for truncation handling
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM sync_attempts WHERE job_id = :job_id');
    $stmtCount->bindValue(':job_id', $jobId, PDO::PARAM_INT);
    $stmtCount->execute();
    $totalAttempts = (int) $stmtCount->fetchColumn();

    $truncated = $totalAttempts > 100;

    // Fetch attempts: oldest to newest (if >100, select the 100 most recent, ordered ascending)
    if ($truncated) {
        $stmtAttempts = $pdo->prepare('
            SELECT id, started_at, duration_ms, http_status, outcome, message
            FROM (
                SELECT id, started_at, duration_ms, http_status, outcome, message
                FROM sync_attempts
                WHERE job_id = :job_id
                ORDER BY id DESC
                LIMIT 100
            ) sub
            ORDER BY id ASC
        ');
    } else {
        $stmtAttempts = $pdo->prepare('
            SELECT id, started_at, duration_ms, http_status, outcome, message
            FROM sync_attempts
            WHERE job_id = :job_id
            ORDER BY id ASC
        ');
    }

    $stmtAttempts->bindValue(':job_id', $jobId, PDO::PARAM_INT);
    $stmtAttempts->execute();
    $attemptRows = $stmtAttempts->fetchAll(PDO::FETCH_ASSOC);

    $outcomeLabels = [
        'success'    => 'Delivered',
        'timeout'    => 'Timed out',
        'http_error' => 'Server error',
        'rejected'   => 'Refused',
    ];

    $attempts = [];
    foreach ($attemptRows as $row) {
        $outcome = (string) $row['outcome'];
        $attempts[] = [
            'id'             => (int) $row['id'],
            'started_at_jst' => Time::utcToJst((string) $row['started_at'], 'Y-m-d H:i:s \J\S\T'),
            'duration_ms'    => (int) $row['duration_ms'],
            'http_status'    => $row['http_status'] !== null ? (int) $row['http_status'] : null,
            'outcome'        => $outcome,
            'outcome_label'  => $outcomeLabels[$outcome] ?? 'Failed',
            'message'        => (string) ($row['message'] ?? ''),
        ];
    }

    $data = [
        'job'       => [
            'job_id'         => (int) $job['job_id'],
            'update_id'      => (int) $job['update_id'],
            'correlation_id' => (string) $job['correlation_id'],
            'channel_name'   => (string) $job['channel_name'],
            'room_name'      => (string) $job['room_name'],
            'status'         => (string) $job['status'],
            'attempt_count'  => (int) $job['attempt_count'],
        ],
        'total'     => $totalAttempts,
        'truncated' => $truncated,
        'attempts'  => $attempts,
    ];

    JsonResponse::success($data, 200);

} catch (Throwable $e) {
    error_log(sprintf('[API Attempts Error] %s at %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    JsonResponse::error('Failed to retrieve attempt history.', 500);
}
