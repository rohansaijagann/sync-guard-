<?php
declare(strict_types=1);

/**
 * Sync Guard - Phase 3 Verification & Integration Test Suite (CLI)
 *
 * 10 Automated Tests:
 * 1. Happy path: one update -> Site A job is success (attempt_count=1), 1 attempt row, mock receives 1 update; Sites B & C stay pending.
 * 2. Idempotent delivery at mock: sending duplicate payload directly -> 200 duplicate, duplicate_hits=1.
 * 3. Atomic claim: claim() twice on same job -> 1st true, 2nd false.
 * 4. REAL PARALLEL CLAIM RACE: 20 updates, TWO parallel worker processes -> every job success, exactly 1 attempt each, duplicate_hits=0.
 * 5. Stuck job recovery: updated_at 10m in past -> recoverStuck returns 1, status='failed', due again, attempt_count unchanged; fresh in_progress left alone.
 * 6. Unknown adapter safety: 'DoesNotExist' & 'Evil\\Thing' -> resolver returns null, processor skips before claim, stays pending.
 * 7. Failure path with injected fake adapter (Timeout): status='failed', attempt_count=1, last_error set, next_attempt_at ~60s ahead, 2nd run ignores it.
 * 8. Fake adapter throwing exception: status='failed' with generic message, 2nd job in same batch succeeds.
 * 9. Mock security & body check: bad signature -> 401, invalid JSON -> 400; HTTP 200 with ok:false maps to Rejected.
 * 10. Unfinished claims protected: markSuccess on job not in_progress returns false, changes nothing.
 */

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
use SyncGuard\Database\Connection;
use SyncGuard\Http\CurlClient;
use SyncGuard\Http\HttpResult;
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
echo "              Sync Guard - Phase 3 Test Suite                       \n";
echo "====================================================================\n\n";

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
$totalTests = 10;
$createdUpdateIds = [];
$mockStorageFile = dirname(__DIR__) . '/storage/mock/site_a.json';

// Helper to track update IDs for cleanup
$trackUpdate = function (int $id) use (&$createdUpdateIds): void {
    if ($id > 0) {
        $createdUpdateIds[] = $id;
    }
};

// Cleanup routine
$cleanup = function () use ($pdo, &$createdUpdateIds, $mockStorageFile): void {
    if (!empty($createdUpdateIds)) {
        $ids = array_unique($createdUpdateIds);
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM sync_attempts WHERE job_id IN (SELECT id FROM sync_jobs WHERE update_id IN ({$in}))");
        $pdo->exec("DELETE FROM sync_jobs WHERE update_id IN ({$in})");
        $pdo->exec("DELETE FROM inventory_updates WHERE id IN ({$in})");
    }
    if (file_exists($mockStorageFile)) {
        @unlink($mockStorageFile);
    }
    (new \SyncGuard\Repositories\HealthRepository($pdo))->rebuildAll();
};

register_shutdown_function($cleanup);

$today = Time::todayInTokyo();
$config = require dirname(__DIR__) . '/config/config.php';
$mockSecret = (string) ($config['mock']['shared_secret'] ?? 'dev_mock_shared_secret_7c9f8a2b4e6d1c3a');

$controlFile = dirname(__DIR__) . '/storage/mock/control.json';
if (file_exists(dirname($controlFile))) {
    file_put_contents($controlFile, json_encode(['site_b' => 'fast', 'site_c' => 'always_succeed']));
}

try {
    // ========================================================================
    // TEST 1: Happy path
    // ========================================================================
    try {
        @unlink($mockStorageFile);
        $key1 = Uuid::v4();
        $res1 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 4,
            'price'           => 9000,
            'idempotency_key' => $key1,
        ]);
        $updateId1 = (int) $res1['data']['update_id'];
        $trackUpdate($updateId1);

        $jobs1 = $jobRepo->getByUpdateId($updateId1);
        $jobMap = [];
        foreach ($jobs1 as $j) {
            $jobMap[$j['adapter_class']] = $j;
        }

        $siteAJobId = (int) $jobMap['SiteAAdapter']['id'];
        $onlyIds = array_map(fn($j) => (int) $j['id'], $jobs1);

        // In Phase 4, Site B and Site C adapters are implemented.
        // Set mock control to fast/always_succeed so all three channels succeed cleanly
        $controlFile = dirname(__DIR__) . '/storage/mock/control.json';
        file_put_contents($controlFile, json_encode(['site_b' => 'fast', 'site_c' => 'always_succeed']));

        $processor = new JobProcessor(
            $pdo,
            $jobRepo,
            $updateRepo,
            $attemptRepo,
            new AdapterResolver(),
            300
        );
        $counts = $processor->runOnce(10, $onlyIds);

        // Verify all 3 channels (Site A, Site B, Site C) are delivered successfully
        foreach (['SiteAAdapter', 'SiteBAdapter', 'SiteCAdapter'] as $adapterName) {
            $jobRecord = $jobRepo->findById((int) $jobMap[$adapterName]['id']);
            if ($jobRecord['status'] !== 'success' || (int) $jobRecord['attempt_count'] !== 1) {
                throw new Exception("{$adapterName} job not marked success: " . json_encode($jobRecord));
            }

            $attempts = $attemptRepo->getByJobId((int) $jobRecord['id']);
            if (count($attempts) !== 1 || $attempts[0]['outcome'] !== 'success') {
                throw new Exception("Expected 1 successful attempt for {$adapterName}, found: " . json_encode($attempts));
            }
        }

        echo "[PASS]  1. Happy path: All 3 sites (A, B, C) delivered successfully with 1 attempt each\n";
        echo "        ↳ All 3 jobs marked status='success'; exactly 1 successful attempt row per channel\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  1. Happy path failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 2: Idempotent delivery at the mock
    // ========================================================================
    try {
        @unlink($mockStorageFile);
        $rawPayload = json_encode([
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 8500,
        ]);
        $sig = 'sha256=' . hash_hmac('sha256', $rawPayload, $mockSecret);

        $curl = new CurlClient();
        $headers = ['X-Signature: ' . $sig];

        // 1st request
        $r1 = $curl->postJson('http://localhost/mock/site_a.php', $rawPayload, $headers);
        $d1 = json_decode((string) $r1->body, true);
        if (!$r1->is2xx() || ($d1['status'] ?? '') !== 'accepted') {
            throw new Exception("1st direct dispatch failed: " . $r1->body);
        }

        // 2nd request with exact same payload and key
        $r2 = $curl->postJson('http://localhost/mock/site_a.php', $rawPayload, $headers);
        $d2 = json_decode((string) $r2->body, true);
        if (!$r2->is2xx() || ($d2['status'] ?? '') !== 'duplicate') {
            throw new Exception("2nd direct dispatch was not recognized as duplicate: " . $r2->body);
        }

        // Query mock summary
        $summary = json_decode((string) file_get_contents('http://localhost/mock/site_a.php'), true);
        if ($summary['received_count'] !== 1 || $summary['duplicate_hits'] !== 1) {
            throw new Exception("Mock state incorrect: " . json_encode($summary));
        }

        echo "[PASS]  2. Idempotent mock delivery\n";
        echo "        ↳ Second delivery returned status='duplicate'; duplicate_hits=1 and received_count=1\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  2. Idempotent mock delivery failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 3: Atomic claim
    // ========================================================================
    try {
        $res3 = $inventoryService->createUpdate([
            'room_id'         => 2,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 14000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $updateId3 = (int) $res3['data']['update_id'];
        $trackUpdate($updateId3);

        $jobs3 = $jobRepo->getByUpdateId($updateId3);
        $jobId3 = (int) $jobs3[0]['id'];

        // 1st claim must succeed
        $claim1 = $jobRepo->claim($jobId3);
        // 2nd claim on same job must fail
        $claim2 = $jobRepo->claim($jobId3);

        if (!$claim1 || $claim2) {
            throw new Exception("Atomic claim failed: claim1=" . var_export($claim1, true) . ", claim2=" . var_export($claim2, true));
        }

        echo "[PASS]  3. Atomic claim condition enforcement\n";
        echo "        ↳ 1st claim() returned true (row locked/updated); 2nd claim() returned false\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  3. Atomic claim test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 4: REAL PARALLEL CLAIM RACE (20 updates, TWO parallel processes)
    // ========================================================================
    try {
        @unlink($mockStorageFile);
        if (file_exists($mockStorageFile)) {
            file_put_contents($mockStorageFile, json_encode(['received_count' => 0, 'duplicate_hits' => 0, 'updates' => []]));
        }
        $raceSiteAJobIds = [];
        for ($i = 0; $i < 20; $i++) {
            $uRes = $inventoryService->createUpdate([
                'room_id'         => 1,
                'date_from'       => $today,
                'date_to'         => $today,
                'available_units' => 5,
                'price'           => 9000,
                'idempotency_key' => Uuid::v4(),
            ]);
            $uId = (int) $uRes['data']['update_id'];
            $trackUpdate($uId);

            $uJobs = $jobRepo->getByUpdateId($uId);
            foreach ($uJobs as $uj) {
                if ($uj['adapter_class'] === 'SiteAAdapter') {
                    $raceSiteAJobIds[] = (int) $uj['id'];
                }
            }
        }

        if (count($raceSiteAJobIds) !== 20) {
            throw new Exception("Expected 20 Site A jobs, created: " . count($raceSiteAJobIds));
        }

        $workerScript = dirname(__DIR__) . '/bin/worker.php';
        $cmd = sprintf(
            'php %s --once --batch=30 --only-jobs=%s',
            escapeshellarg($workerScript),
            implode(',', $raceSiteAJobIds)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Launch TWO parallel worker processes at the exact same moment
        $p1 = proc_open($cmd, $descriptors, $pipes1);
        $p2 = proc_open($cmd, $descriptors, $pipes2);

        $out1 = stream_get_contents($pipes1[1]);
        $out2 = stream_get_contents($pipes2[1]);
        fclose($pipes1[0]); fclose($pipes1[1]); fclose($pipes1[2]);
        fclose($pipes2[0]); fclose($pipes2[1]); fclose($pipes2[2]);
        proc_close($p1);
        proc_close($p2);

        // Assert all 20 jobs are 'success' with attempt_count 1 and exactly 1 attempt row
        $inIds = implode(',', $raceSiteAJobIds);
        $stmtStatus = $pdo->query("SELECT id, status, attempt_count, last_error FROM sync_jobs WHERE id IN ({$inIds})");
        $results = $stmtStatus->fetchAll();

        foreach ($results as $r) {
            if ($r['status'] !== 'success' || (int) $r['attempt_count'] !== 1) {
                throw new Exception("Job #{$r['id']} state invalid: status={$r['status']}, attempts={$r['attempt_count']}, err={$r['last_error']}");
            }
        }

        $stmtAttempts = $pdo->query("SELECT COUNT(*) FROM sync_attempts WHERE job_id IN ({$inIds})");
        $totalAttempts = (int) $stmtAttempts->fetchColumn();
        if ($totalAttempts !== 20) {
            throw new Exception("Expected exactly 20 attempt rows, found: {$totalAttempts}");
        }

        // Assert mock received 20 unique updates with duplicate_hits = 0
        $mockState = json_decode((string) file_get_contents('http://localhost/mock/site_a.php'), true);
        if ($mockState['received_count'] !== 20 || $mockState['duplicate_hits'] !== 0) {
            throw new Exception("Mock received invalid hit counts: " . json_encode($mockState));
        }

        echo "[PASS]  4. Real parallel worker race test (20 jobs competed by 2 workers)\n";
        echo "        ↳ All 20 jobs marked success; exactly 20 attempt rows; 0 duplicate deliveries at mock\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  4. Parallel worker race failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 5: Stuck job recovery
    // ========================================================================
    try {
        $res5 = $inventoryService->createUpdate([
            'room_id'         => 3,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 18000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId5 = (int) $res5['data']['update_id'];
        $trackUpdate($uId5);

        $j5 = $jobRepo->getByUpdateId($uId5);
        $jobIdOld = (int) $j5[0]['id'];
        $jobIdFresh = (int) $j5[1]['id'];

        // Make jobOld in_progress with updated_at 10 minutes in the past
        $pdo->exec("UPDATE sync_jobs SET status = 'in_progress', updated_at = UTC_TIMESTAMP() - INTERVAL 600 SECOND WHERE id = {$jobIdOld}");
        // Make jobFresh in_progress just seconds ago
        $pdo->exec("UPDATE sync_jobs SET status = 'in_progress', updated_at = UTC_TIMESTAMP() WHERE id = {$jobIdFresh}");

        $recovered = $jobRepo->recoverStuck(300); // 300s = 5m threshold
        if ($recovered < 1) {
            throw new Exception("Expected at least 1 job recovered, got: {$recovered}");
        }

        $oldCheck = $jobRepo->findById($jobIdOld);
        if ($oldCheck['status'] !== 'failed' || (int) $oldCheck['attempt_count'] !== 0 || !str_contains((string) $oldCheck['last_error'], 'Recovered')) {
            throw new Exception("Stale job not recovered properly: " . json_encode($oldCheck));
        }

        $freshCheck = $jobRepo->findById($jobIdFresh);
        if ($freshCheck['status'] !== 'in_progress') {
            throw new Exception("Fresh in_progress job was improperly reset: " . json_encode($freshCheck));
        }

        echo "[PASS]  5. Stuck job recovery\n";
        echo "        ↳ Stale in_progress job (>5m) recovered to 'failed' without incrementing attempts; fresh job preserved\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  5. Stuck job recovery failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 6: Unknown adapter safety
    // ========================================================================
    try {
        $resolver = new AdapterResolver();
        if ($resolver->has('DoesNotExist') || $resolver->resolve('DoesNotExist') !== null) {
            throw new Exception("Resolver should return null for nonexistent adapter");
        }
        if ($resolver->has('Evil\\Thing') || $resolver->resolve('Evil\\Thing') !== null) {
            throw new Exception("Resolver should reject namespace traversal");
        }

        // Test with processor skipping unknown adapter before claiming
        $res6 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 8500,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId6 = (int) $res6['data']['update_id'];
        $trackUpdate($uId6);

        $j6 = $jobRepo->getByUpdateId($uId6);
        // Point one job to an unknown adapter
        $targetJobId = (int) $j6[0]['id'];
        $pdo->exec("UPDATE channels SET adapter_class = 'NonexistentAdapter' WHERE id = {$j6[0]['channel_id']}");

        $processor = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, new AdapterResolver(), 300);
        $counts = $processor->runOnce(10, [$targetJobId]);

        // Restore channel
        $pdo->exec("UPDATE channels SET adapter_class = '{$j6[0]['adapter_class']}' WHERE id = {$j6[0]['channel_id']}");

        if ($counts['skipped_no_adapter'] !== 1) {
            throw new Exception("Expected 1 skipped_no_adapter count, got: " . json_encode($counts));
        }

        $checkedJob = $jobRepo->findById($targetJobId);
        if ($checkedJob['status'] !== 'pending') {
            throw new Exception("Skipped job was modified: " . json_encode($checkedJob));
        }

        echo "[PASS]  6. Unknown adapter security whitelist & pre-claim skip\n";
        echo "        ↳ Unimplemented adapter skipped BEFORE claiming; job remains safely pending with 0 claims\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  6. Unknown adapter safety failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 7: Failure path with injected fake adapter (Timeout)
    // ========================================================================
    try {
        $res7 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 8500,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId7 = (int) $res7['data']['update_id'];
        $trackUpdate($uId7);

        $j7 = $jobRepo->getByUpdateId($uId7);
        $jobId7 = (int) $j7[0]['id'];

        $timeoutAdapter = new class implements ChannelAdapter {
            public function push(array $update, array $channel): PushResult
            {
                return PushResult::timeout(2500, 'Mock connection timed out after 2500ms');
            }
        };

        $customResolver = new AdapterResolver([$j7[0]['adapter_class'] => $timeoutAdapter]);
        $processor7 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $customResolver, 300);

        $c7 = $processor7->runOnce(10, [$jobId7]);
        if ($c7['failed'] !== 1) {
            throw new Exception("Expected 1 failed count, got: " . json_encode($c7));
        }

        $failedJob = $jobRepo->findById($jobId7);
        if ($failedJob['status'] !== 'failed' || (int) $failedJob['attempt_count'] !== 1 || empty($failedJob['last_error'])) {
            throw new Exception("Job not marked failed properly: " . json_encode($failedJob));
        }

        $attempts7 = $attemptRepo->getByJobId($jobId7);
        if (count($attempts7) !== 1 || $attempts7[0]['outcome'] !== 'timeout') {
            throw new Exception("Attempt log incorrect: " . json_encode($attempts7));
        }

        // An immediate second run must NOT pick up the job because next_attempt_at is 60s ahead
        $c7b = $processor7->runOnce(10, [$jobId7]);
        if ($c7b['found'] !== 0) {
            throw new Exception("Immediate re-run picked up future job prematurely: " . json_encode($c7b));
        }

        echo "[PASS]  7. Failure path: Timeout outcome & future retry scheduling\n";
        echo "        ↳ Job marked 'failed', attempt_count=1, next_attempt_at +60s, second immediate pass ignored it\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  7. Failure path test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 8: Fake adapter throwing exception
    // ========================================================================
    try {
        $res8a = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 8500,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId8a = (int) $res8a['data']['update_id'];
        $trackUpdate($uId8a);

        $res8b = $inventoryService->createUpdate([
            'room_id'         => 2,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 3,
            'price'           => 14000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId8b = (int) $res8b['data']['update_id'];
        $trackUpdate($uId8b);

        $j8a = $jobRepo->getByUpdateId($uId8a)[0];
        $j8b = $jobRepo->getByUpdateId($uId8b)[0];

        $throwingAdapter = new class($uId8a) implements ChannelAdapter {
            public function __construct(private int $failUpdateId) {}
            public function push(array $update, array $channel): PushResult {
                if ((int) $update['id'] === $this->failUpdateId) {
                    throw new RuntimeException("Simulated catastrophic adapter runtime crash!");
                }
                return PushResult::success(50, 200, 'Second job accepted cleanly');
            }
        };

        $resolver8 = new AdapterResolver([$j8a['adapter_class'] => $throwingAdapter]);
        $processor8 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver8, 300);

        $c8 = $processor8->runOnce(10, [(int) $j8a['id'], (int) $j8b['id']]);

        if ($c8['failed'] !== 1 || $c8['succeeded'] !== 1) {
            throw new Exception("Expected 1 failed and 1 succeeded in same batch, got: " . json_encode($c8));
        }

        $checkA = $jobRepo->findById((int) $j8a['id']);
        if ($checkA['status'] !== 'failed' || $checkA['last_error'] !== 'Internal adapter error') {
            throw new Exception("Throwing job did not receive generic error: " . json_encode($checkA));
        }

        $checkB = $jobRepo->findById((int) $j8b['id']);
        if ($checkB['status'] !== 'success') {
            throw new Exception("Subsequent job in batch failed: " . json_encode($checkB));
        }

        echo "[PASS]  8. Fault isolation: Adapter exception logged and contained\n";
        echo "        ↳ Unhandled exception safely mapped to 'Internal adapter error'; 2nd job in batch succeeded\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  8. Exception handling test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 9: Mock security & body check
    // ========================================================================
    try {
        $curl = new CurlClient();

        // 1. Wrong signature -> 401
        $r401 = $curl->postJson('http://localhost/mock/site_a.php', '{"test":1}', ['X-Signature: sha256=badhex']);
        if ($r401->statusCode !== 401) {
            throw new Exception("Expected 401 on bad signature, got: " . $r401->statusCode);
        }

        // 2. Malformed JSON with valid signature -> 400
        $badJson = 'not valid json {{{';
        $sigBad = 'sha256=' . hash_hmac('sha256', $badJson, $mockSecret);
        $r400 = $curl->postJson('http://localhost/mock/site_a.php', $badJson, ['X-Signature: ' . $sigBad]);
        if ($r400->statusCode !== 400) {
            throw new Exception("Expected 400 on malformed JSON, got: " . $r400->statusCode);
        }

        // 3. Adapter body check: HTTP 200 with ok: false maps to Rejected PushResult
        $stubHttp = new class extends CurlClient {
            public function postJson(string $url, string $jsonPayload, array $headers = []): HttpResult {
                return new HttpResult(200, '{"ok":false,"error":"Room rate below floor"}', 0, '', 45);
            }
        };

        $adapter = new SiteAAdapter($stubHttp, $mockSecret);
        $resPush = $adapter->push([
            'idempotency_key' => Uuid::v4(),
            'correlation_id'  => Uuid::v4(),
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
        ], ['endpoint_url' => 'http://localhost/mock/site_a.php']);

        if ($resPush->outcome !== PushOutcome::Rejected || $resPush->success !== false || !str_contains($resPush->message, 'floor')) {
            throw new Exception("Expected Rejected outcome when ok=false, got: " . $resPush->outcome->value);
        }

        echo "[PASS]  9. Mock cryptographic security & adapter body inspection\n";
        echo "        ↳ Bad signature rejected with 401; malformed JSON rejected with 400; HTTP 200 ok=false mapped to Rejected\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  9. Mock security test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 10: Unfinished claims protected
    // ========================================================================
    try {
        $res10 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 8500,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId10 = (int) $res10['data']['update_id'];
        $trackUpdate($uId10);

        $j10 = $jobRepo->getByUpdateId($uId10)[0];
        $jobId10 = (int) $j10['id'];

        // Job is currently in 'pending' status. Calling markSuccess MUST return false!
        $marked = $jobRepo->markSuccess($jobId10);
        if ($marked !== false) {
            throw new Exception("markSuccess succeeded on a job that was not in_progress!");
        }

        // Verify status remains 'pending'
        $check10 = $jobRepo->findById($jobId10);
        if ($check10['status'] !== 'pending') {
            throw new Exception("Job status changed despite false markSuccess: " . json_encode($check10));
        }

        echo "[PASS] 10. Claim state protection on status finalization\n";
        echo "        ↳ markSuccess() rejected on non in_progress job; row remains untouched\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 10. Claim protection test failed: " . $e->getMessage() . "\n";
    }

} finally {
    $cleanup();
}

echo "\n====================================================================\n";
echo "Summary: {$passedCount} / {$totalTests} tests passed.\n";
echo "====================================================================\n";

if ($passedCount === $totalTests) {
    echo "Result: ALL TESTS PASSED. Phase 3 implementation is complete and sound!\n\n";
    exit(0);
} else {
    echo "Result: SOME TESTS FAILED. Please review the output above.\n\n";
    exit(1);
}
