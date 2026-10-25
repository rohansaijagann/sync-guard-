<?php
declare(strict_types=1);

/**
 * Sync Guard - Mock OTA Site C Endpoint (Flaky Partner & Silent Drop Simulation)
 *
 * Simulates a modern JSON API partner suffering from intermittent flakiness,
 * application-level rejections hidden inside HTTP 200 responses, and silent data drops.
 */

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/config/config.php';
$sharedSecret = (string) ($config['mock']['shared_secret'] ?? 'dev_mock_shared_secret_7c9f8a2b4e6d1c3a');

$storageDir = dirname(__DIR__, 2) . '/storage/mock';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
    @chmod($storageDir, 0777);
}

$storageFile = $storageDir . '/site_c.json';
$controlFile = $storageDir . '/control.json';

// Read operational mode from control file (defaults to 'random')
$mode = 'random';
if (file_exists($controlFile)) {
    $ctrl = json_decode((string) @file_get_contents($controlFile), true);
    if (is_array($ctrl) && isset($ctrl['site_c']) && is_string($ctrl['site_c'])) {
        $allowed = ['random', 'always_succeed', 'always_fail', 'always_reject', 'forget'];
        if (in_array($ctrl['site_c'], $allowed, true)) {
            $mode = $ctrl['site_c'];
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ----------------------------------------------------------------------------
// GET: Diagnostics and inspection endpoint
// ----------------------------------------------------------------------------
if ($method === 'GET') {
    header('Vary: Accept');
    header('Cache-Control: no-store');

    $state = [
        'received_count'  => 0,
        'duplicate_hits'  => 0,
        'forgotten_count' => 0,
        'updates'         => [],
    ];
    if (file_exists($storageFile)) {
        $raw = @file_get_contents($storageFile);
        if ($raw !== false) {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                $state = $parsed;
            }
        }
    }

    $all = array_values($state['updates'] ?? []);
    $recent = array_slice(array_reverse($all), 0, 10);

    /**
     * WHY CONTENT NEGOTIATION IS STRICTLY ENFORCED:
     * GET returns the rich HTML operational console ONLY if the HTTP Accept header contains
     * 'text/html' AND the query string does not explicitly request 'format=json'.
     * Every other GET request (curl default wildcard Accept, automated test suites expecting JSON,
     * polling scripts with ?format=json, or explicit Accept: application/json) receives
     * the exact original JSON structure to preserve API backward compatibility.
     */
    $acceptHeader = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $formatParam = (string) ($_GET['format'] ?? '');
    $isHtmlRequest = str_contains($acceptHeader, 'text/html') && ($formatParam !== 'json');

    if ($isHtmlRequest) {
        header('Content-Type: text/html; charset=utf-8');

        $siteId = 'site_c';
        $siteName = 'Site C';
        $siteLabel = 'Unreliable on purpose';
        $accentClass = 'site-theme-c';
        $currentMode = $mode;
        $isFailingMode = in_array($mode, ['random', 'always_fail', 'always_reject', 'forget'], true);

        $modeExplanations = [
            'random'         => 'random: about 4 in 10 requests fail on purpose (HTTP 500, 503, or 200 rejections).',
            'always_succeed' => 'always_succeed: 100% acceptance without simulated errors.',
            'always_fail'    => 'always_fail: returns HTTP 500 Internal Server Error on every request.',
            'always_reject'  => 'always_reject: returns HTTP 200 with ok: false business rejection.',
            'forget'         => 'forget: returns HTTP 200 OK acknowledging update, but silently drops the data.',
        ];
        $modeExplanation = $modeExplanations[$mode] ?? "mode: {$mode}";

        $receivedCount = (int) ($state['received_count'] ?? 0);
        $duplicateCount = (int) ($state['duplicate_hits'] ?? 0);
        $forgottenCount = (int) ($state['forgotten_count'] ?? 0);
        $recentUpdates = array_slice(array_reverse($all), 0, 20);
        $behaviorBullets = [
            'Format: Modern JSON over HTTPS with HMAC-SHA256 signature verification',
            'Speed: Fast execution with intentional non-deterministic chaos simulation',
            'Failure: Simulates gateway errors (500), service drops (503), rate rejections, and silent drops',
        ];

        require __DIR__ . '/partials/console.php';
        exit(0);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'              => true,
        'mode'            => $mode,
        'received_count'  => (int) ($state['received_count'] ?? 0),
        'duplicate_hits'  => (int) ($state['duplicate_hits'] ?? 0),
        'forgotten_count' => (int) ($state['forgotten_count'] ?? 0),
        'recent_updates'  => $recent,
        'last_10'         => $recent,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

// ----------------------------------------------------------------------------
// POST: Incoming OTA update dispatch
// ----------------------------------------------------------------------------
if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

// 1. Verify HMAC-SHA256 signature
$rawBody = file_get_contents('php://input') ?: '';
$providedHeader = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

if ($providedHeader === '' || !hash_equals($expectedSignature, $providedHeader)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized: Invalid or missing X-Signature HMAC'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

// 2. Parse and validate JSON structure
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad Request: Malformed JSON payload'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

$requiredFields = ['idempotency_key', 'room_id', 'date_from', 'date_to'];
foreach ($requiredFields as $field) {
    if (empty($payload[$field])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Bad Request: Missing required field '{$field}'"], JSON_UNESCAPED_SLASHES);
        exit(0);
    }
}

$idempotencyKey = (string) $payload['idempotency_key'];

// 3. Evaluate Flakiness & Failure Modes
/**
 * WHY RANDOM FAILURES NEED FORCED MODES FOR DETERMINISTIC TESTING:
 * Random chaos simulation is ideal for local manual testing and resilience drills, but
 * causes non-deterministic flakiness in automated CI/CD test suites. Providing forced modes
 * (always_succeed, always_fail, always_reject, forget) enables deterministic assertions in unit tests.
 */
if ($mode === 'always_fail') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Site C internal database error (always_fail mode)'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

if ($mode === 'always_reject') {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'Rate below contracted minimum'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

$isSilentForget = ($mode === 'forget');

if ($mode === 'random') {
    // 1 to 100 dice roll
    $roll = random_int(1, 100);

    if ($roll <= 22) {
        // 22% chance: HTTP 500 Internal Error
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Site C gateway timeout or unhandled 500 error'], JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    if ($roll <= 30) {
        // 8% chance (23-30): HTTP 503 Service Unavailable
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Site C upstream service unavailable (503)'], JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    if ($roll <= 40) {
        // 10% chance (31-40): HTTP 200 with ok: false rejection
        http_response_code(200);
        echo json_encode(['ok' => false, 'error' => 'Rate below contracted minimum'], JSON_UNESCAPED_SLASHES);
        exit(0);
    }

    // Remaining 60% succeed, but ~5% of those are silently forgotten
    if ($roll <= 43) {
        $isSilentForget = true;
    }
}

// 4. Persistence with File Locking (flock)
$fp = fopen($storageFile, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Storage file unavailable'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

flock($fp, LOCK_EX);

$fileSize = filesize($storageFile);
$rawContent = $fileSize > 0 ? (string) fread($fp, $fileSize) : '';
$state = json_decode($rawContent, true);

if (!is_array($state)) {
    $state = [
        'received_count'  => 0,
        'duplicate_hits'  => 0,
        'forgotten_count' => 0,
        'updates'         => [],
    ];
}

/**
 * WHY THE 'FORGET' MODE EXISTS:
 * In real-world OTA synchronization, a booking engine occasionally returns HTTP 200 {"ok": true}
 * acknowledging receipt, but subsequently suffers an uncommitted transaction or silent drop in its
 * internal ingest pipeline. The sender assumes the room availability was applied, causing silent
 * room inventory divergence until nightly inventory audit reconciliation detects it.
 */
if ($isSilentForget) {
    $state['forgotten_count'] = ((int) ($state['forgotten_count'] ?? 0)) + 1;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    http_response_code(200);
    echo json_encode([
        'ok'      => true,
        'status'  => 'accepted',
        'message' => 'Room inventory update accepted by Site C',
    ], JSON_UNESCAPED_SLASHES);
    exit(0);
}

// 5. Idempotent Storage Check
$isDuplicate = isset($state['updates'][$idempotencyKey]);

if ($isDuplicate) {
    $state['duplicate_hits'] = ((int) ($state['duplicate_hits'] ?? 0)) + 1;
} else {
    $state['received_count'] = ((int) ($state['received_count'] ?? 0)) + 1;
    $payload['received_at'] = gmdate('Y-m-d H:i:s');
    $state['updates'][$idempotencyKey] = $payload;
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

http_response_code(200);
if ($isDuplicate) {
    echo json_encode([
        'ok'      => true,
        'status'  => 'duplicate',
        'message' => 'Idempotent replay: update already accepted',
    ], JSON_UNESCAPED_SLASHES);
} else {
    echo json_encode([
        'ok'      => true,
        'status'  => 'accepted',
        'message' => 'Room inventory update accepted by Site C',
    ], JSON_UNESCAPED_SLASHES);
}
exit(0);
