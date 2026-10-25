<?php
declare(strict_types=1);

/**
 * Sync Guard - Mock OTA Site A Endpoint
 *
 * Simulates an external booking site receiving availability and rate updates.
 * Demonstrates cryptographic payload verification (HMAC-SHA256) and partner-side idempotency.
 *
 * NOTE ON PERMISSIONS:
 * The mock endpoint creates its own storage directory and JSON file at runtime so that the web
 * server process (www-data under Apache) maintains write ownership. Tests and CLI scripts may
 * read or delete the file during teardown, but should avoid pre-creating it to prevent root-owned
 * permission locks on Docker mounts.
 */

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/config/config.php';
$sharedSecret = (string) ($config['mock']['shared_secret'] ?? 'dev_mock_shared_secret_7c9f8a2b4e6d1c3a');

$storageDir = dirname(__DIR__, 2) . '/storage/mock';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
    @chmod($storageDir, 0777);
}

$storageFile = $storageDir . '/site_a.json';

// Open storage file with exclusive advisory locking (flock) to handle concurrent requests safely
$fp = fopen($storageFile, 'c+');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Storage file unavailable'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

flock($fp, LOCK_EX);

// Read current storage state
clearstatcache(true, $storageFile);
rewind($fp);
$rawContent = (string) stream_get_contents($fp);
$state = json_decode($rawContent, true);

if (!is_array($state)) {
    $state = [
        'received_count' => 0,
        'duplicate_hits' => 0,
        'updates'        => [],
    ];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ----------------------------------------------------------------------------
// GET: Diagnostics and inspection endpoint
// ----------------------------------------------------------------------------
if ($method === 'GET') {
    header('Vary: Accept');
    header('Cache-Control: no-store');

    $allUpdates = array_values($state['updates'] ?? []);
    $recent = array_slice(array_reverse($allUpdates), 0, 10);

    flock($fp, LOCK_UN);
    fclose($fp);

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

        $siteId = 'site_a';
        $siteName = 'Site A';
        $siteLabel = 'Fast and reliable';
        $accentClass = 'site-theme-a';
        $currentMode = 'normal';
        $isFailingMode = false;
        $modeExplanation = 'normal: synchronous processing with minimal latency (~100ms) and 100% acceptance.';
        $receivedCount = (int) ($state['received_count'] ?? 0);
        $duplicateCount = (int) ($state['duplicate_hits'] ?? 0);
        $forgottenCount = null;
        $recentUpdates = array_slice(array_reverse($allUpdates), 0, 20);
        $behaviorBullets = [
            'Payload: Modern JSON over HTTPS with HMAC-SHA256 signature verification',
            'Speed: Sub-100ms processing with zero artificial delays',
            'Resilience: Partner-side idempotency cache rejects duplicates cleanly',
        ];

        require __DIR__ . '/partials/console.php';
        exit(0);
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode([
        'ok'             => true,
        'received_count' => (int) ($state['received_count'] ?? 0),
        'duplicate_hits' => (int) ($state['duplicate_hits'] ?? 0),
        'recent_updates' => $recent,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

// ----------------------------------------------------------------------------
// POST: Incoming OTA update dispatch
// ----------------------------------------------------------------------------
if ($method !== 'POST') {
    flock($fp, LOCK_UN);
    fclose($fp);

    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed. Only POST is accepted for updates.'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

// 1. Verify HMAC-SHA256 signature
$rawBody = file_get_contents('php://input') ?: '';
$providedHeader = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

if ($providedHeader === '' || !hash_equals($expectedSignature, $providedHeader)) {
    flock($fp, LOCK_UN);
    fclose($fp);

    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized: Invalid or missing X-Signature HMAC'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

// 2. Parse and validate JSON structure
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    flock($fp, LOCK_UN);
    fclose($fp);

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad Request: Malformed JSON payload'], JSON_UNESCAPED_SLASHES);
    exit(0);
}

// Required fields validation
$requiredFields = ['idempotency_key', 'room_id', 'date_from', 'date_to'];
foreach ($requiredFields as $field) {
    if (empty($payload[$field])) {
        flock($fp, LOCK_UN);
        fclose($fp);

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Bad Request: Missing required field '{$field}'"], JSON_UNESCAPED_SLASHES);
        exit(0);
    }
}

$idempotencyKey = (string) $payload['idempotency_key'];

// 3. Partner-side Idempotency Check
if (isset($state['updates'][$idempotencyKey])) {
    // Record duplicate arrival and return 200 without creating a second entry
    $state['duplicate_hits'] = ((int) ($state['duplicate_hits'] ?? 0)) + 1;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    http_response_code(200);
    echo json_encode([
        'ok'      => true,
        'status'  => 'duplicate',
        'message' => 'Idempotent replay: update already accepted',
    ], JSON_UNESCAPED_SLASHES);
    exit(0);
}

// 4. First-time acceptance
$state['received_count'] = ((int) ($state['received_count'] ?? 0)) + 1;
$payload['received_at'] = gmdate('Y-m-d H:i:s');
$state['updates'][$idempotencyKey] = $payload;

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
    'message' => 'Room inventory update accepted by Site A',
], JSON_UNESCAPED_SLASHES);
exit(0);
