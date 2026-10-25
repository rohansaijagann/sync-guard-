<?php
declare(strict_types=1);

/**
 * Sync Guard - Phase 4 Verification & Integration Test Suite (CLI)
 *
 * Tests:
 *  1. Site B happy path: mode 'fast' -> job success, 1 attempt, mock B received 1, well-formed XML values.
 *  2. Site B XML escaping: room name with special & Japanese characters parses back identically.
 *  3. Site B timeout: mode 'slow' (8s) with 1s injected timeout -> PushResult Timeout, duration < 2.5s, job failed.
 *  4. Site B HTTP 503 ('down') -> HttpError; 'reject' (200 + rejected body) -> Rejected; recorded correctly.
 *  5. Site B invalid signature -> 401; malformed XML -> 400.
 *  6. Site C forced modes: 'always_succeed' -> Success; 'always_fail' -> HttpError; 'always_reject' -> Rejected.
 *  7. Site C random mode statistical: 200 direct calls -> 25%-55% failure rate, all 3 failure kinds occur.
 *  8. Site C 'forget': reports Success, but received_count stays 0 and forgotten_count increments.
 *  9. Site C idempotency: same key twice in 'always_succeed' -> stored once with duplicate_hits 1.
 * 10. Full pipeline: 1 update with B 'fast' & C 'always_succeed' -> all 3 jobs success, 3 attempt rows.
 * 11. Mixed run in single pass: A normal, B slow (1s timeout), C always_fail -> A success, B timeout, C http_error.
 * 12. Parallel race, all 3 sites: 10 updates (30 jobs), 2 parallel workers -> all 30 success, 1 attempt each, 0 duplicates.
 * 13. mock_control validation: invalid modes/sites rejected with error code; reset restores defaults.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/test_phase4.php must be run from the CLI.\n");
    exit(1);
}

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    spl_autoload_register(function (string $class): void {
        $prefix = 'SyncGuard\\';
        if (str_starts_with($class, $prefix)) {
            $relativeClass = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    });
}

use SyncGuard\Adapters\AdapterResolver;
use SyncGuard\Adapters\ChannelAdapter;
use SyncGuard\Adapters\PushOutcome;
use SyncGuard\Adapters\PushResult;
use SyncGuard\Adapters\SiteAAdapter;
use SyncGuard\Adapters\SiteBAdapter;
use SyncGuard\Adapters\SiteCAdapter;
use SyncGuard\Database\Connection;
use SyncGuard\Http\CurlClient;
use SyncGuard\Repositories\AttemptRepository;
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Services\InventoryService;
use SyncGuard\Support\Time;
use SyncGuard\Support\Uuid;
use SyncGuard\Validation\InventoryUpdateValidator;
use SyncGuard\Worker\JobProcessor;

echo "====================================================================\n";
echo "              Sync Guard - Phase 4 Test Suite                       \n";
echo "====================================================================\n\n";

$config = require dirname(__DIR__) . '/config/config.php';
$pdo = Connection::get();
$jobRepo = new JobRepository($pdo);
$updateRepo = new UpdateRepository($pdo);
$attemptRepo = new AttemptRepository($pdo);
$roomRepo = new RoomRepository($pdo);
$channelRepo = new ChannelRepository($pdo);
$validator = new InventoryUpdateValidator();

$inventoryService = new InventoryService(
    $pdo,
    $roomRepo,
    $channelRepo,
    $updateRepo,
    $jobRepo,
    $validator
);

$passedCount = 0;
$totalTests = 13;
$createdUpdateIds = [];

$controlFile = dirname(__DIR__) . '/storage/mock/control.json';
$mockAFile = dirname(__DIR__) . '/storage/mock/site_a.json';
$mockBFile = dirname(__DIR__) . '/storage/mock/site_b.json';
$mockCFile = dirname(__DIR__) . '/storage/mock/site_c.json';

$channelBDef = ['endpoint_url' => 'http://127.0.0.1/mock/site_b.php'];
$channelCDef = ['endpoint_url' => 'http://127.0.0.1/mock/site_c.php'];

$trackUpdate = function (int $id) use (&$createdUpdateIds): void {
    if ($id > 0) {
        $createdUpdateIds[] = $id;
    }
};

$setMockControl = function (string $site, string $mode) use ($controlFile): void {
    $current = ['site_b' => 'normal', 'site_c' => 'random'];
    if (file_exists($controlFile)) {
        $decoded = json_decode((string) file_get_contents($controlFile), true);
        if (is_array($decoded)) {
            $current = array_merge($current, $decoded);
        }
    }
    $current[$site] = $mode;
    $tmp = $controlFile . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($current, JSON_PRETTY_PRINT));
    chmod($tmp, 0644);
    rename($tmp, $controlFile);
};

$resetMocks = function () use ($controlFile, $mockAFile, $mockBFile, $mockCFile): void {
    $defaults = ['site_b' => 'normal', 'site_c' => 'random'];
    $tmp = $controlFile . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($defaults, JSON_PRETTY_PRINT));
    chmod($tmp, 0644);
    rename($tmp, $controlFile);

    @unlink($mockAFile);
    @unlink($mockBFile);
    @unlink($mockCFile);
};

$cleanup = function () use ($pdo, &$createdUpdateIds, $resetMocks): void {
    if (!empty($createdUpdateIds)) {
        $ids = array_unique($createdUpdateIds);
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM sync_attempts WHERE job_id IN (SELECT id FROM sync_jobs WHERE update_id IN ({$in}))");
        $pdo->exec("DELETE FROM sync_jobs WHERE update_id IN ({$in})");
        $pdo->exec("DELETE FROM inventory_updates WHERE id IN ({$in})");
    }
    $resetMocks();
    (new \SyncGuard\Repositories\HealthRepository($pdo))->rebuildAll();
};

try {
    $resetMocks();
    $today = \SyncGuard\Support\Time::todayInTokyo();
    $resolver = new AdapterResolver();

    // ========================================================================
    // TEST 1: Site B Happy Path (mode 'fast')
    // ========================================================================
    try {
        $setMockControl('site_b', 'fast');
        @unlink($mockBFile);

        $res1 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 4,
            'price'           => 11500,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId1 = (int) $res1['data']['update_id'];
        $trackUpdate($uId1);

        $jobs1 = $jobRepo->getByUpdateId($uId1);
        $siteBJob = null;
        foreach ($jobs1 as $j) {
            if ($j['adapter_class'] === 'SiteBAdapter') {
                $siteBJob = $j;
                break;
            }
        }
        if (!$siteBJob) {
            throw new Exception("SiteBAdapter job not found for update {$uId1}");
        }

        $processor = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver);
        $processor->runOnce(10, [(int) $siteBJob['id']]);

        $updatedJob = $jobRepo->findById((int) $siteBJob['id']);
        if ($updatedJob['status'] !== 'success' || (int) $updatedJob['attempt_count'] !== 1) {
            throw new Exception("Expected job status 'success' and attempt_count 1, got status '{$updatedJob['status']}' and count {$updatedJob['attempt_count']}");
        }

        $attempts = $attemptRepo->getByJobId((int) $siteBJob['id']);
        if (count($attempts) !== 1 || $attempts[0]['outcome'] !== 'success') {
            throw new Exception("Expected 1 'success' attempt row, got " . count($attempts) . " with outcome " . ($attempts[0]['outcome'] ?? 'none'));
        }

        if (!file_exists($mockBFile)) {
            throw new Exception("Mock B storage file was not created");
        }
        $mockBData = json_decode((string) file_get_contents($mockBFile), true);
        if (($mockBData['received_count'] ?? 0) !== 1) {
            throw new Exception("Expected mock B received_count = 1, got " . ($mockBData['received_count'] ?? 0));
        }

        $lastRecord = end($mockBData['updates']) ?: null;
        if (!$lastRecord || empty($lastRecord['raw_xml'])) {
            throw new Exception("Mock B did not record received XML payload");
        }

        // Verify XML parses safely and contains room and price
        $xml = simplexml_load_string($lastRecord['raw_xml'], 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false || (int) $xml->room_id !== 1 || (int) $xml->price !== 11500) {
            throw new Exception("Mock B received malformed or mismatched XML: " . $lastRecord['raw_xml']);
        }

        echo "[PASS]  1. Site B happy path (mode 'fast')\n";
        echo "        ↳ Job succeeded with 1 attempt; mock B stored valid XML with room_id=1, price=11500\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  1. Site B happy path failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 2: Site B XML escaping (DOMDocument preserves special & Japanese characters)
    // ========================================================================
    try {
        $setMockControl('site_b', 'fast');
        $specialName = 'Tom & Jerry <Suite> 和室 "A"';
        $adapterB = new SiteBAdapter();

        $payload = [
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 2,
            'room_name'       => $specialName,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 25000,
        ];

        $result = $adapterB->push($payload, $channelBDef);
        if ($result->outcome !== PushOutcome::Success) {
            throw new Exception("Push update returned " . $result->outcome->value . ": " . $result->errorMessage);
        }

        $mockBData = json_decode((string) file_get_contents($mockBFile), true);
        $lastRecord = end($mockBData['updates']) ?: null;
        $xml = simplexml_load_string($lastRecord['raw_xml'], 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            throw new Exception("Failed to parse received XML from mock B");
        }

        $receivedRoomName = (string) $xml->room_name;
        if ($receivedRoomName !== $specialName) {
            throw new Exception("Expected room name '{$specialName}', parsed back as '{$receivedRoomName}'");
        }

        echo "[PASS]  2. Site B XML escaping & character encoding\n";
        echo "        ↳ Room name 'Tom & Jerry <Suite> 和室 \"A\"' safely encoded and parsed back identically\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  2. Site B XML escaping failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 3: Site B Timeout (mode 'slow' 8s with 1s timeout injected)
    // ========================================================================
    try {
        $setMockControl('site_b', 'slow');

        $res3 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 10000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId3 = (int) $res3['data']['update_id'];
        $trackUpdate($uId3);

        $jobs3 = $jobRepo->getByUpdateId($uId3);
        $siteBJob3 = null;
        foreach ($jobs3 as $j) {
            if ($j['adapter_class'] === 'SiteBAdapter') {
                $siteBJob3 = $j;
                break;
            }
        }

        // Custom resolver with 1s short timeout injected
        $shortTimeoutResolver = new AdapterResolver([
            'SiteBAdapter' => new SiteBAdapter(new CurlClient(connectTimeout: 1, timeout: 1)),
        ]);

        $processor3 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $shortTimeoutResolver);

        $startT = microtime(true);
        $processor3->runOnce(10, [(int) $siteBJob3['id']]);
        $elapsed = microtime(true) - $startT;

        if ($elapsed >= 2.5) {
            throw new Exception(sprintf("Worker was blocked for %.2fs, expected < 2.5s", $elapsed));
        }

        $jobAfter = $jobRepo->findById((int) $siteBJob3['id']);
        if ($jobAfter['status'] !== 'failed') {
            throw new Exception("Expected job status 'failed', got '{$jobAfter['status']}'");
        }

        $attempts3 = $attemptRepo->getByJobId((int) $siteBJob3['id']);
        if (count($attempts3) !== 1 || $attempts3[0]['outcome'] !== 'timeout') {
            throw new Exception("Expected attempt outcome 'timeout', got: " . ($attempts3[0]['outcome'] ?? 'none'));
        }

        echo "[PASS]  3. Site B bounded timeout (mode 'slow' 8s vs 1s timeout)\n";
        echo sprintf("        ↳ Execution bounded to %.2fs (< 2.5s); outcome logged as 'timeout' and job marked 'failed'\n", $elapsed);
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  3. Site B timeout failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 4: Site B HTTP 503 ('down') and 'reject' (200 + rejected XML)
    // ========================================================================
    try {
        // Subtest 4a: Mode 'down' -> HttpError
        $setMockControl('site_b', 'down');
        $adapterNormal = new SiteBAdapter();
        $payload4a = [
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 1,
            'room_name'       => 'Standard Single',
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 8000,
        ];
        $resDown = $adapterNormal->push($payload4a, $channelBDef);
        if ($resDown->outcome !== PushOutcome::HttpError || $resDown->httpStatus !== 503) {
            throw new Exception("Expected HttpError 503, got {$resDown->outcome->value} HTTP {$resDown->httpStatus}");
        }

        // Subtest 4b: Mode 'reject' -> Rejected
        $setMockControl('site_b', 'reject');
        $payload4b = [
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 1,
            'room_name'       => 'Standard Single',
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 8000,
        ];
        $resReject = $adapterNormal->push($payload4b, $channelBDef);
        if ($resReject->outcome !== PushOutcome::Rejected || $resReject->httpStatus !== 200) {
            throw new Exception("Expected Rejected 200, got {$resReject->outcome->value} HTTP {$resReject->httpStatus}");
        }

        echo "[PASS]  4. Site B error mapping (503 'down' and 200 'reject')\n";
        echo "        ↳ HTTP 503 mapped to HttpError; HTTP 200 with rejection XML body mapped to Rejected\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  4. Site B error mapping failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 5: Site B Security & Validation (401 Bad HMAC & 400 Malformed XML)
    // ========================================================================
    try {
        $setMockControl('site_b', 'fast');
        @unlink($mockBFile);
        $urlB = 'http://127.0.0.1/mock/site_b.php';
        $curl = new CurlClient();

        // Subtest 5a: Invalid HMAC signature -> 401
        $validXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><inventory_update><idempotency_key>test</idempotency_key></inventory_update>";
        $res5a = $curl->post($urlB, $validXml, [
            'Content-Type: application/xml',
            'X-Idempotency-Key: test-bad-sig',
            'X-Correlation-Id: ' . Uuid::v4(),
            'X-Signature: sha256=invalidsignature000000000000000000000000000000000000000000000000',
        ]);

        if ($res5a->statusCode !== 401) {
            throw new Exception("Expected 401 Unauthorized for bad HMAC, got HTTP {$res5a->statusCode}");
        }

        if (file_exists($mockBFile)) {
            $data5a = json_decode((string) file_get_contents($mockBFile), true);
            if (($data5a['received_count'] ?? 0) > 0) {
                throw new Exception("Mock B stored data despite 401 Unauthorized signature!");
            }
        }

        // Subtest 5b: Malformed XML with valid HMAC -> 400
        $malformedXml = "<broken><unclosed>";
        $secret = $config['mock']['shared_secret'] ?? 'dev_mock_shared_secret_7c9f8a2b4e6d1c3a';
        $sig5b = 'sha256=' . hash_hmac('sha256', $malformedXml, $secret);
        $res5b = $curl->post($urlB, $malformedXml, [
            'Content-Type: application/xml',
            'X-Idempotency-Key: test-malformed',
            'X-Correlation-Id: ' . Uuid::v4(),
            'X-Signature: ' . $sig5b,
        ]);

        if ($res5b->statusCode !== 400) {
            throw new Exception("Expected 400 Bad Request for malformed XML, got HTTP {$res5b->statusCode}");
        }

        echo "[PASS]  5. Site B security & XML schema validation\n";
        echo "        ↳ Bad HMAC correctly returned 401 (nothing stored); malformed XML returned 400\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  5. Site B security/validation failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 6: Site C Forced Modes
    // ========================================================================
    try {
        $adapterC = new SiteCAdapter();

        // 6a: always_succeed -> Success
        $setMockControl('site_c', 'always_succeed');
        $p6a = [
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 1,
            'room_name'       => 'Single',
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 3,
            'price'           => 9000,
        ];
        $res6a = $adapterC->push($p6a, $channelCDef);
        if ($res6a->outcome !== PushOutcome::Success || $res6a->httpStatus !== 200) {
            throw new Exception("always_succeed failed: got {$res6a->outcome->value} HTTP {$res6a->httpStatus}");
        }

        // 6b: always_fail -> HttpError 500
        $setMockControl('site_c', 'always_fail');
        $p6b = [
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 1,
            'room_name'       => 'Single',
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 3,
            'price'           => 9000,
        ];
        $res6b = $adapterC->push($p6b, $channelCDef);
        if ($res6b->outcome !== PushOutcome::HttpError || $res6b->httpStatus !== 500) {
            throw new Exception("always_fail failed: got {$res6b->outcome->value} HTTP {$res6b->httpStatus}");
        }

        // 6c: always_reject -> Rejected 200
        $setMockControl('site_c', 'always_reject');
        $p6c = [
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 1,
            'room_name'       => 'Single',
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 3,
            'price'           => 9000,
        ];
        $res6c = $adapterC->push($p6c, $channelCDef);
        if ($res6c->outcome !== PushOutcome::Rejected || $res6c->httpStatus !== 200) {
            throw new Exception("always_reject failed: got {$res6c->outcome->value} HTTP {$res6c->httpStatus}");
        }

        echo "[PASS]  6. Site C forced control modes\n";
        echo "        ↳ 'always_succeed' -> Success, 'always_fail' -> HttpError (500), 'always_reject' -> Rejected (200 with ok:false)\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  6. Site C forced modes failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 7: Site C Random Mode Statistical Verification (200 direct calls)
    // ========================================================================
    try {
        $setMockControl('site_c', 'random');
        $adapterC = new SiteCAdapter();

        $totalCalls = 200;
        $successCount = 0;
        $http500Count = 0;
        $http503Count = 0;
        $rejectedCount = 0;

        for ($i = 0; $i < $totalCalls; $i++) {
            $res = $adapterC->push([
                'idempotency_key' => Uuid::v4(),
                'correlation_id'  => Uuid::v4(),
                'room_id'         => 1,
                'room_name'       => 'Single',
                'date_from'       => $today,
                'date_to'         => $today,
                'available_units' => 2,
                'price'           => 9500,
            ], $channelCDef);

            if ($res->outcome === PushOutcome::Success) {
                $successCount++;
            } elseif ($res->outcome === PushOutcome::HttpError && $res->httpStatus === 500) {
                $http500Count++;
            } elseif ($res->outcome === PushOutcome::HttpError && $res->httpStatus === 503) {
                $http503Count++;
            } elseif ($res->outcome === PushOutcome::Rejected && $res->httpStatus === 200) {
                $rejectedCount++;
            }
        }

        $failCount = $http500Count + $http503Count + $rejectedCount;
        $failRate = $failCount / $totalCalls;

        if ($failRate < 0.25 || $failRate > 0.55) {
            throw new Exception(sprintf("Failure rate %.1f%% outside expected 25%%-55%% range (fails=%d / %d)", $failRate * 100, $failCount, $totalCalls));
        }

        if ($http500Count === 0 || $http503Count === 0 || $rejectedCount === 0) {
            throw new Exception(sprintf(
                "Not all 3 failure kinds occurred in 200 runs: 500=%d, 503=%d, rejected=%d",
                $http500Count,
                $http503Count,
                $rejectedCount
            ));
        }

        echo "[PASS]  7. Site C random mode statistical distribution (200 calls)\n";
        echo sprintf(
            "        ↳ Failure rate: %.1f%% (HTTP 500: %d, HTTP 503: %d, Rejected: %d, Success: %d)\n",
            $failRate * 100,
            $http500Count,
            $http503Count,
            $rejectedCount,
            $successCount
        );
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  7. Site C statistical test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 8: Site C 'forget' Mode (Silent Loss Simulation)
    // ========================================================================
    try {
        $setMockControl('site_c', 'forget');
        @unlink($mockCFile);
        $adapterC = new SiteCAdapter();

        $res8 = $adapterC->push([
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 1,
            'room_name'       => 'Single',
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 9000,
        ], $channelCDef);

        if ($res8->outcome !== PushOutcome::Success) {
            throw new Exception("Expected PushOutcome::Success from 'forget' mode, got " . $res8->outcome->value);
        }

        $mockCData = json_decode((string) file_get_contents($mockCFile), true);
        if (($mockCData['received_count'] ?? -1) !== 0) {
            throw new Exception("Expected received_count = 0 in 'forget' mode, got " . ($mockCData['received_count'] ?? 0));
        }
        if (($mockCData['forgotten_count'] ?? 0) !== 1) {
            throw new Exception("Expected forgotten_count = 1 in 'forget' mode, got " . ($mockCData['forgotten_count'] ?? 0));
        }

        echo "[PASS]  8. Site C 'forget' mode (silent loss simulation)\n";
        echo "        ↳ Adapter reported Success; mock storage received_count=0 and forgotten_count=1\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  8. Site C 'forget' mode failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 9: Site C Idempotency
    // ========================================================================
    try {
        $setMockControl('site_c', 'always_succeed');
        @unlink($mockCFile);
        $adapterC = new SiteCAdapter();
        $dupKey = Uuid::v4();

        $payload9 = [
            'idempotency_key' => $dupKey,
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 2,
            'room_name'       => 'Twin',
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 14000,
        ];

        $call1 = $adapterC->push($payload9, $channelCDef);
        $call2 = $adapterC->push($payload9, $channelCDef);

        if ($call1->outcome !== PushOutcome::Success || $call2->outcome !== PushOutcome::Success) {
            throw new Exception("Both idempotent calls must succeed");
        }

        $mockCData = json_decode((string) file_get_contents($mockCFile), true);
        if (($mockCData['received_count'] ?? 0) !== 1) {
            throw new Exception("Expected received_count = 1, got " . ($mockCData['received_count'] ?? 0));
        }
        if (($mockCData['duplicate_hits'] ?? 0) !== 1) {
            throw new Exception("Expected duplicate_hits = 1, got " . ($mockCData['duplicate_hits'] ?? 0));
        }

        echo "[PASS]  9. Site C idempotency enforcement\n";
        echo "        ↳ Same idempotency key delivered twice: stored once, duplicate_hits=1\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  9. Site C idempotency failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 10: Full Pipeline (Sites A, B, and C all succeed)
    // ========================================================================
    try {
        $setMockControl('site_b', 'fast');
        $setMockControl('site_c', 'always_succeed');

        $res10 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 3,
            'price'           => 10500,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId10 = (int) $res10['data']['update_id'];
        $trackUpdate($uId10);

        $jobs10 = $jobRepo->getByUpdateId($uId10);
        $jobIds10 = array_map(fn($j) => (int) $j['id'], $jobs10);

        $processor10 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver);
        $counts10 = $processor10->runOnce(10, $jobIds10);

        if ($counts10['succeeded'] !== 3 || $counts10['failed'] !== 0) {
            throw new Exception("Expected 3 succeeded jobs, got succeeded={$counts10['succeeded']}, failed={$counts10['failed']}");
        }

        foreach ($jobs10 as $j) {
            $refreshed = $jobRepo->findById((int) $j['id']);
            if ($refreshed['status'] !== 'success') {
                throw new Exception("Job {$j['id']} status is '{$refreshed['status']}', expected 'success'");
            }
            $attempts = $attemptRepo->getByJobId((int) $j['id']);
            if (count($attempts) !== 1 || $attempts[0]['outcome'] !== 'success') {
                throw new Exception("Job {$j['id']} attempt count or outcome invalid");
            }
        }

        echo "[PASS] 10. Full multi-site pipeline (Sites A, B, C)\n";
        echo "        ↳ Single update produced 3 jobs; all 3 successfully delivered with exactly 1 attempt row each\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 10. Full pipeline test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 11: Mixed Run in Single Worker Pass (A normal, B slow+short timeout, C always_fail)
    // ========================================================================
    try {
        $setMockControl('site_b', 'slow');
        $setMockControl('site_c', 'always_fail');

        $res11 = $inventoryService->createUpdate([
            'room_id'         => 2,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 15000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId11 = (int) $res11['data']['update_id'];
        $trackUpdate($uId11);

        $jobs11 = $jobRepo->getByUpdateId($uId11);
        $jobIds11 = array_map(fn($j) => (int) $j['id'], $jobs11);

        $mixedResolver = new AdapterResolver([
            'SiteBAdapter' => new SiteBAdapter(new CurlClient(connectTimeout: 1, timeout: 1)),
        ]);

        $processor11 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $mixedResolver);

        $start11 = microtime(true);
        $counts11 = $processor11->runOnce(10, $jobIds11);
        $elapsed11 = microtime(true) - $start11;

        if ($elapsed11 >= 4.0) {
            throw new Exception(sprintf("Mixed pass took %.2fs, expected < 4.0s", $elapsed11));
        }

        $siteAJob = null;
        $siteBJob = null;
        $siteCJob = null;
        foreach ($jobs11 as $j) {
            $row = $jobRepo->findById((int) $j['id']);
            if ($j['adapter_class'] === 'SiteAAdapter') $siteAJob = $row;
            if ($j['adapter_class'] === 'SiteBAdapter') $siteBJob = $row;
            if ($j['adapter_class'] === 'SiteCAdapter') $siteCJob = $row;
        }

        if ($siteAJob['status'] !== 'success' || (int) $siteAJob['attempt_count'] !== 1) {
            throw new Exception("Site A expected success, got " . $siteAJob['status']);
        }
        if ($siteBJob['status'] !== 'failed' || (int) $siteBJob['attempt_count'] !== 1) {
            throw new Exception("Site B expected failed, got " . $siteBJob['status']);
        }
        if ($siteCJob['status'] !== 'failed' || (int) $siteCJob['attempt_count'] !== 1) {
            throw new Exception("Site C expected failed, got " . $siteCJob['status']);
        }

        $attB = $attemptRepo->getByJobId((int) $siteBJob['id'])[0] ?? null;
        $attC = $attemptRepo->getByJobId((int) $siteCJob['id'])[0] ?? null;

        if (!$attB || $attB['outcome'] !== 'timeout') {
            throw new Exception("Site B attempt expected 'timeout', got: " . ($attB['outcome'] ?? 'none'));
        }
        if (!$attC || $attC['outcome'] !== 'http_error') {
            throw new Exception("Site C attempt expected 'http_error', got: " . ($attC['outcome'] ?? 'none'));
        }

        echo "[PASS] 11. Mixed failure modes in single worker pass\n";
        echo sprintf("        ↳ Site A=success, Site B=timeout, Site C=http_error; bounded duration %.2fs\n", $elapsed11);
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 11. Mixed run test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 12: Parallel Race, All 3 Sites (10 updates = 30 jobs, 2 worker processes)
    // ========================================================================
    try {
        $setMockControl('site_b', 'fast');
        $setMockControl('site_c', 'always_succeed');
        // Allow in-flight slow request from Test 11 to conclude before wiping mock storage
        usleep(1500000);
        @unlink($mockAFile);
        @unlink($mockBFile);
        @unlink($mockCFile);

        $raceJobIds = [];
        for ($i = 0; $i < 10; $i++) {
            $r = $inventoryService->createUpdate([
                'room_id'         => 1,
                'date_from'       => $today,
                'date_to'         => $today,
                'available_units' => 5,
                'price'           => 12000,
                'idempotency_key' => Uuid::v4(),
            ]);
            $uId = (int) $r['data']['update_id'];
            $trackUpdate($uId);

            foreach ($jobRepo->getByUpdateId($uId) as $j) {
                $raceJobIds[] = (int) $j['id'];
            }
        }

        if (count($raceJobIds) !== 30) {
            throw new Exception("Expected 30 jobs for 10 updates, got " . count($raceJobIds));
        }

        $workerScript = dirname(__DIR__) . '/bin/worker.php';
        $cmd = sprintf(
            'php %s --once --batch=30 --only-jobs=%s',
            escapeshellarg($workerScript),
            implode(',', $raceJobIds)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $p1 = proc_open($cmd, $descriptors, $pipes1);
        $p2 = proc_open($cmd, $descriptors, $pipes2);

        $out1 = stream_get_contents($pipes1[1]);
        $out2 = stream_get_contents($pipes2[1]);
        fclose($pipes1[0]); fclose($pipes1[1]); fclose($pipes1[2]);
        fclose($pipes2[0]); fclose($pipes2[1]); fclose($pipes2[2]);
        proc_close($p1);
        proc_close($p2);

        // Verify all 30 jobs succeeded with 1 attempt
        foreach ($raceJobIds as $jId) {
            $row = $jobRepo->findById($jId);
            if ($row['status'] !== 'success' || (int) $row['attempt_count'] !== 1) {
                throw new Exception("Race job {$jId} status is '{$row['status']}' and attempt_count is {$row['attempt_count']}");
            }
            $attempts = $attemptRepo->getByJobId($jId);
            if (count($attempts) !== 1) {
                throw new Exception("Race job {$jId} has " . count($attempts) . " attempt rows, expected exactly 1");
            }
        }

        // Verify mocks received counts
        $mA = json_decode((string) @file_get_contents($mockAFile), true);
        $mB = json_decode((string) @file_get_contents($mockBFile), true);
        $mC = json_decode((string) @file_get_contents($mockCFile), true);

        if (($mA['received_count'] ?? 0) !== 10 || ($mA['duplicate_hits'] ?? 0) !== 0) {
            throw new Exception("Mock A count invalid: received=" . ($mA['received_count'] ?? 0) . ", dups=" . ($mA['duplicate_hits'] ?? 0));
        }
        if (($mB['received_count'] ?? 0) !== 10 || ($mB['duplicate_hits'] ?? 0) !== 0) {
            throw new Exception("Mock B count invalid: received=" . ($mB['received_count'] ?? 0) . ", dups=" . ($mB['duplicate_hits'] ?? 0));
        }
        if (($mC['received_count'] ?? 0) !== 10 || ($mC['duplicate_hits'] ?? 0) !== 0) {
            throw new Exception("Mock C count invalid: received=" . ($mC['received_count'] ?? 0) . ", dups=" . ($mC['duplicate_hits'] ?? 0));
        }

        echo "[PASS] 12. Real parallel race across all 3 sites (30 jobs, 2 workers)\n";
        echo "        ↳ All 30 jobs succeeded with exactly 1 attempt; Mocks A, B, C each received 10 updates (0 dups)\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 12. Parallel race failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 13: mock_control CLI Validation
    // ========================================================================
    try {
        $controlScript = dirname(__DIR__) . '/bin/mock_control.php';

        // 13a: Unknown site rejected
        $cmdBadSite = sprintf('php %s set site_unknown fast 2>&1', escapeshellarg($controlScript));
        exec($cmdBadSite, $outBadSite, $codeBadSite);
        if ($codeBadSite === 0) {
            throw new Exception("Expected non-zero exit code for unknown site, got 0");
        }

        // 13b: Invalid mode rejected
        $cmdBadMode = sprintf('php %s set site_b invalid_mode 2>&1', escapeshellarg($controlScript));
        exec($cmdBadMode, $outBadMode, $codeBadMode);
        if ($codeBadMode === 0) {
            throw new Exception("Expected non-zero exit code for invalid mode, got 0");
        }

        // 13c: Reset restores defaults
        $cmdReset = sprintf('php %s reset 2>&1', escapeshellarg($controlScript));
        exec($cmdReset, $outReset, $codeReset);
        if ($codeReset !== 0) {
            throw new Exception("Reset command failed with code {$codeReset}");
        }

        $decodedControl = json_decode((string) file_get_contents($controlFile), true);
        if (($decodedControl['site_b'] ?? '') !== 'normal' || ($decodedControl['site_c'] ?? '') !== 'random') {
            throw new Exception("Control file after reset does not have default values: " . json_encode($decodedControl));
        }

        echo "[PASS] 13. Mock control CLI validation & defaults reset\n";
        echo "        ↳ Rejected invalid site & mode with error code; 'reset' restored defaults (B=normal, C=random)\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 13. Mock control validation failed: " . $e->getMessage() . "\n";
    }

} finally {
    $cleanup();
}

echo "\n--------------------------------------------------------------------\n";
echo sprintf("Summary: %d / %d tests passed\n", $passedCount, $totalTests);
echo "--------------------------------------------------------------------\n";

if ($passedCount !== $totalTests) {
    exit(1);
}

exit(0);
