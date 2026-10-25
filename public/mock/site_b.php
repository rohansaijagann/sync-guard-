<?php
declare(strict_types=1);

/**
 * Sync Guard - Mock OTA Site B Endpoint (XML & Latency Simulation)
 *
 * Simulates a legacy enterprise OTA partner requiring XML payloads with variable response latencies.
 * Supports operational modes controlled via storage/mock/control.json.
 */

@set_time_limit(60);

$config = require dirname(__DIR__, 2) . '/config/config.php';
$sharedSecret = (string) ($config['mock']['shared_secret'] ?? 'dev_mock_shared_secret_7c9f8a2b4e6d1c3a');

$storageDir = dirname(__DIR__, 2) . '/storage/mock';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0777, true);
    @chmod($storageDir, 0777);
}

$storageFile = $storageDir . '/site_b.json';
$controlFile = $storageDir . '/control.json';

// Read operational mode from control file (defaults to 'normal')
$mode = 'normal';
if (file_exists($controlFile)) {
    $ctrl = json_decode((string) @file_get_contents($controlFile), true);
    if (is_array($ctrl) && isset($ctrl['site_b']) && is_string($ctrl['site_b'])) {
        $allowed = ['normal', 'fast', 'slow', 'down', 'reject'];
        if (in_array($ctrl['site_b'], $allowed, true)) {
            $mode = $ctrl['site_b'];
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
        'received_count' => 0,
        'duplicate_hits' => 0,
        'updates'        => [],
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

        $siteId = 'site_b';
        $siteName = 'Site B';
        $siteLabel = 'Slow on purpose';
        $accentClass = 'site-theme-b';
        $currentMode = $mode;
        $isFailingMode = in_array($mode, ['down', 'slow', 'reject'], true);

        $modeExplanations = [
            'normal' => 'normal: variable legacy latency (2.0s – 6.0s) simulating enterprise backend.',
            'slow'   => 'slow: always answers after 8 seconds and the sender gives up.',
            'fast'   => 'fast: minimal latency (~50ms) for high-speed legacy processing.',
            'down'   => 'down: returns HTTP 503 Service Unavailable (database maintenance).',
            'reject' => 'reject: returns HTTP 200 with XML rejection (<ok>false</ok>).',
        ];
        $modeExplanation = $modeExplanations[$mode] ?? "mode: {$mode}";

        $receivedCount = (int) ($state['received_count'] ?? 0);
        $duplicateCount = (int) ($state['duplicate_hits'] ?? 0);
        $forgottenCount = null;
        $recentUpdates = array_slice(array_reverse($all), 0, 20);
        $behaviorBullets = [
            'Format: Legacy XML with SimpleXMLElement parsing and XXE defense',
            'Speed: High artificial latency (sleep 2s–6s normal, 8s in slow mode)',
            'Failure: Simulates downstream enterprise timeouts and HTTP 503 outages',
        ];

        require __DIR__ . '/partials/console.php';
        exit(0);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'             => true,
        'mode'           => $mode,
        'received_count' => (int) ($state['received_count'] ?? 0),
        'duplicate_hits' => (int) ($state['duplicate_hits'] ?? 0),
        'recent_updates' => $recent,
        'last_10'        => $recent,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

header('Content-Type: application/xml; charset=utf-8');

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Method Not Allowed</error>";
    exit(0);
}

// 1. Verify HMAC-SHA256 signature
$rawBody = file_get_contents('php://input') ?: '';
$providedHeader = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $sharedSecret);

if ($providedHeader === '' || !hash_equals($expectedSignature, $providedHeader)) {
    http_response_code(401);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Unauthorized: Invalid or missing X-Signature HMAC</error>";
    exit(0);
}

// 2. Parse XML safely with XXE protection
$xml = @simplexml_load_string($rawBody, 'SimpleXMLElement', LIBXML_NONET);
if ($xml === false) {
    http_response_code(400);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Bad Request: Malformed XML payload</error>";
    exit(0);
}

$idempotencyKey = (string) ($xml->idempotency_key ?? '');
$roomId = (string) ($xml->room_id ?? '');
$dateFrom = (string) ($xml->date_from ?? '');
$dateTo = (string) ($xml->date_to ?? '');
$roomName = (string) ($xml->room_name ?? '');

if ($idempotencyKey === '' || $roomId === '' || $dateFrom === '' || $dateTo === '') {
    http_response_code(400);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Bad Request: Missing required XML elements</error>";
    exit(0);
}

// 3. Apply Simulated Latency or Outage according to mode
switch ($mode) {
    case 'down':
        http_response_code(503);
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Service Unavailable: Site B database maintenance in progress</error>";
        exit(0);

    case 'reject':
        http_response_code(200);
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<response><ok>false</ok><status>rejected</status><message>Rate below contract floor</message></response>";
        exit(0);

    case 'slow':
        // Send headers first and detect client disconnects without writing spaces into the body
        ignore_user_abort(false);
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
        for ($s = 0; $s < 80; $s++) {
            usleep(100 * 1000);
            @flush();
            if (connection_aborted() !== 0) {
                exit(0);
            }
            if ($s % 2 === 0 && file_exists($controlFile)) {
                clearstatcache(true, $controlFile);
                $cData = json_decode((string) @file_get_contents($controlFile), true);
                if (isset($cData['site_b']) && $cData['site_b'] !== 'slow') {
                    exit(0);
                }
            }
        }
        break;

    case 'fast':
        // No latency
        break;

    case 'normal':
    default:
        // Normal legacy latency: sleep between 2.0s and 6.0s
        $delayMs = random_int(2000, 6000);
        usleep($delayMs * 1000);
        break;
}

// 4. Idempotent Storage persistence using flock
$fp = fopen($storageFile, 'c+');
if (!$fp) {
    http_response_code(500);
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error>Storage unavailable</error>";
    exit(0);
}

flock($fp, LOCK_EX);

$fileSize = filesize($storageFile);
$rawContent = $fileSize > 0 ? (string) fread($fp, $fileSize) : '';
$state = json_decode($rawContent, true);
if (!is_array($state)) {
    $state = [
        'received_count' => 0,
        'duplicate_hits' => 0,
        'updates'        => [],
    ];
}

$isDuplicate = isset($state['updates'][$idempotencyKey]);

if ($isDuplicate) {
    $state['duplicate_hits'] = ((int) ($state['duplicate_hits'] ?? 0)) + 1;
} else {
    $state['received_count'] = ((int) ($state['received_count'] ?? 0)) + 1;
    $state['updates'][$idempotencyKey] = [
        'idempotency_key' => $idempotencyKey,
        'correlation_id'  => (string) ($xml->correlation_id ?? ''),
        'room_id'         => (int) $roomId,
        'room_name'       => $roomName,
        'date_from'       => $dateFrom,
        'date_to'         => $dateTo,
        'available_units' => isset($xml->available_units) && (string)$xml->available_units !== '' ? (int)$xml->available_units : null,
        'price'           => isset($xml->price) && (string)$xml->price !== '' ? (float)$xml->price : null,
        'raw_xml'         => $rawBody,
        'received_at'     => gmdate('Y-m-d H:i:s'),
    ];
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

http_response_code(200);
$xmlDecl = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
if ($isDuplicate) {
    echo $xmlDecl . "<response><ok>true</ok><status>duplicate</status><message>Idempotent replay: update already accepted</message></response>";
} else {
    echo $xmlDecl . "<response><ok>true</ok><status>accepted</status><message>Room inventory update accepted by Site B</message></response>";
}
exit(0);
