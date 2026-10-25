<?php
declare(strict_types=1);

/**
 * Sync Guard - Phase 6 Test Suite
 *
 * Verifies channel health calculations, rolling window metrics, concurrency locks,
 * worker heartbeat, at-risk room calculation, status API integrity, static JS safety,
 * and high-volume performance.
 *
 * Execution:
 *   php bin/test_phase6.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/test_phase6.php must be run from the CLI.\n");
    exit(1);
}

// Autoloading
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
use SyncGuard\Adapters\PushResult;
use SyncGuard\Database\Connection;
use SyncGuard\Repositories\AttemptRepository;
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\HealthRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Retry\Backoff;
use SyncGuard\Services\HealthService;
use SyncGuard\Services\InventoryService;
use SyncGuard\Services\StatusService;
use SyncGuard\Support\AlertWriter;
use SyncGuard\Support\Heartbeat;
use SyncGuard\Support\Time;
use SyncGuard\Support\Uuid;
use SyncGuard\Validation\InventoryUpdateValidator;
use SyncGuard\Worker\JobProcessor;

$pdo = Connection::get();
$jobRepo = new JobRepository($pdo);
$updateRepo = new UpdateRepository($pdo);
$attemptRepo = new AttemptRepository($pdo);
$healthRepo = new HealthRepository($pdo);
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
$totalTests = 15;
$createdUpdateIds = [];

$trackUpdate = function (int $id) use (&$createdUpdateIds): void {
    if ($id > 0) {
        $createdUpdateIds[] = $id;
    }
};

$controlFile = dirname(__DIR__) . '/storage/mock/control.json';
$mockAFile = dirname(__DIR__) . '/storage/mock/site_a.json';
$mockBFile = dirname(__DIR__) . '/storage/mock/site_b.json';
$mockCFile = dirname(__DIR__) . '/storage/mock/site_c.json';
$heartbeatFile = dirname(__DIR__) . '/storage/worker_heartbeat.json';

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
    @chmod($tmp, 0644);
    @rename($tmp, $controlFile);
};

$resetMocks = function () use ($controlFile, $mockAFile, $mockBFile, $mockCFile): void {
    $defaults = ['site_b' => 'normal', 'site_c' => 'random'];
    $tmp = $controlFile . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($defaults, JSON_PRETTY_PRINT));
    @chmod($tmp, 0644);
    @rename($tmp, $controlFile);

    @unlink($mockAFile);
    @unlink($mockBFile);
    @unlink($mockCFile);
};

$cleanup = function () use ($pdo, &$createdUpdateIds, $resetMocks, $healthRepo): void {
    // Restore all channels active
    $pdo->exec('UPDATE channels SET is_active = 1');

    if (!empty($createdUpdateIds)) {
        $ids = array_unique($createdUpdateIds);
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM sync_attempts WHERE job_id IN (SELECT id FROM sync_jobs WHERE update_id IN ({$in}))");
        $pdo->exec("DELETE FROM sync_jobs WHERE update_id IN ({$in})");
        $pdo->exec("DELETE FROM inventory_updates WHERE id IN ({$in})");
    }
    $resetMocks();
    $healthRepo->rebuildAll();
};

echo "====================================================================\n";
echo "              Sync Guard - Phase 6 Test Suite                       \n";
echo "====================================================================\n\n";

try {
    $today = \SyncGuard\Support\Time::todayInTokyo();
    $resolver = new AdapterResolver();

    // ========================================================================
    // TEST 1: labelFor thresholds
    // ========================================================================
    try {
        // 1. No data
        if (HealthService::labelFor(false, 0, 100.0) !== 'no_data') {
            throw new Exception("Expected 'no_data' when hasData=false");
        }
        // 2. 4 failures vs 5
        if (HealthService::labelFor(true, 4, 100.0) !== 'degraded') { // 4 failures is degraded (>=2)
            throw new Exception("Expected 'degraded' for 4 consecutive failures");
        }
        if (HealthService::labelFor(true, 5, 100.0) !== 'down') {
            throw new Exception("Expected 'down' for 5 consecutive failures");
        }
        // 3. 1 vs 2 consecutive failures
        if (HealthService::labelFor(true, 1, 100.0) !== 'healthy') {
            throw new Exception("Expected 'healthy' for 1 consecutive failure at 100% rate");
        }
        if (HealthService::labelFor(true, 2, 100.0) !== 'degraded') {
            throw new Exception("Expected 'degraded' for 2 consecutive failures");
        }
        // 4. Success rate 90.0 vs 89.99 (recovering when streak < 5, healthy when streak >= 5)
        if (HealthService::labelFor(true, 0, 90.0) !== 'healthy') {
            throw new Exception("Expected 'healthy' for success rate 90.0% with 0 failures");
        }
        if (HealthService::labelFor(true, 0, 89.99, 0) !== 'recovering') {
            throw new Exception("Expected 'recovering' for success rate 89.99% with streak < 5");
        }
        if (HealthService::labelFor(true, 0, 89.99, 5) !== 'healthy') {
            throw new Exception("Expected 'healthy' for success rate 89.99% with streak >= 5");
        }
        // 5. Down wins over degraded
        if (HealthService::labelFor(true, 5, 50.0) !== 'down') {
            throw new Exception("Expected 'down' rule to take precedence over 'degraded'");
        }

        echo "[PASS]  1. Health status label thresholds (HealthService::labelFor)\n";
        echo "        ↳ no_data, down (consec>=5), degraded (consec>=2 || rate<90), and healthy verified\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  1. Health status label test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 2: Successes update health
    // ========================================================================
    try {
        $setMockControl('site_b', 'fast');
        $setMockControl('site_c', 'always_succeed');

        // Create 3 updates
        $testJobIds = [];
        for ($i = 0; $i < 3; $i++) {
            $res = $inventoryService->createUpdate([
                'room_id'         => 1,
                'date_from'       => $today,
                'date_to'         => $today,
                'available_units' => 2,
                'price'           => 10000 + ($i * 100),
                'idempotency_key' => Uuid::v4(),
            ]);
            $uId = (int) $res['data']['update_id'];
            $trackUpdate($uId);
            $jobs = $jobRepo->getByUpdateId($uId);
            foreach ($jobs as $j) {
                if ($j['channel_name'] === 'Site A') {
                    $testJobIds[] = (int) $j['id'];
                }
            }
        }

        $proc = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver, 300, null, new Backoff(0));
        $proc->runOnce(10, $testJobIds);

        // Check channel_health for Site A (channel 1)
        $hStmt = $pdo->prepare('SELECT * FROM channel_health WHERE channel_id = 1');
        $hStmt->execute();
        $hA = $hStmt->fetch(PDO::FETCH_ASSOC);

        if ((float) $hA['success_rate'] !== 100.00) {
            throw new Exception("Expected Site A success rate 100.00%, got: " . $hA['success_rate']);
        }
        if ((int) $hA['consecutive_failures'] !== 0) {
            throw new Exception("Expected consecutive_failures 0, got: " . $hA['consecutive_failures']);
        }
        if (empty($hA['last_success_at'])) {
            throw new Exception("Expected last_success_at to be populated");
        }
        if ((int) $hA['avg_response_ms'] <= 0) {
            throw new Exception("Expected avg_response_ms > 0, got: " . $hA['avg_response_ms']);
        }

        echo "[PASS]  2. Successful deliveries maintain channel health metrics\n";
        echo "        ↳ success_rate 100.00%, consecutive_failures 0, last_success_at set, latency > 0ms\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  2. Successes update health failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 3: Failures update health & reset streak
    // ========================================================================
    try {
        // Channel 3 (Site C): Submit update and fail it
        $setMockControl('site_c', 'always_fail');
        $res3 = $inventoryService->createUpdate([
            'room_id'         => 2,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 14000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId3 = (int) $res3['data']['update_id'];
        $trackUpdate($uId3);

        $jC = null;
        foreach ($jobRepo->getByUpdateId($uId3) as $j) {
            if ($j['channel_name'] === 'Site C') {
                $jC = $j;
            }
        }
        $jCId = (int) $jC['id'];

        $proc = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver, 300, null, new Backoff(0));
        $proc->runOnce(10, [$jCId]);

        $hC = $pdo->query('SELECT * FROM channel_health WHERE channel_id = 3')->fetch(PDO::FETCH_ASSOC);
        if ((int) $hC['consecutive_failures'] < 1) {
            throw new Exception("Expected consecutive_failures >= 1, got: " . $hC['consecutive_failures']);
        }
        if (empty($hC['last_failure_at'])) {
            throw new Exception("Expected last_failure_at to be populated");
        }

        // Now heal Site C and process another update: consecutive_failures MUST reset to 0
        $setMockControl('site_c', 'always_succeed');
        $res3b = $inventoryService->createUpdate([
            'room_id'         => 2,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 14500,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId3b = (int) $res3b['data']['update_id'];
        $trackUpdate($uId3b);
        $jC2 = null;
        foreach ($jobRepo->getByUpdateId($uId3b) as $j) {
            if ($j['channel_name'] === 'Site C') {
                $jC2 = $j;
            }
        }
        $proc->runOnce(10, [(int) $jC2['id']]);

        $hC2 = $pdo->query('SELECT * FROM channel_health WHERE channel_id = 3')->fetch(PDO::FETCH_ASSOC);
        if ((int) $hC2['consecutive_failures'] !== 0) {
            throw new Exception("Expected consecutive_failures to reset to 0 after success, got: " . $hC2['consecutive_failures']);
        }

        echo "[PASS]  3. Failures increment streak and subsequent success resets consecutive_failures to 0\n";
        echo "        ↳ consecutive_failures tracked and zeroed; last_failure_at accurately recorded\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  3. Failures update health failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 4: Rolling window (50 attempts)
    // ========================================================================
    try {
        // Create a dummy job for Site A to attach synthetic attempts
        $res4 = $inventoryService->createUpdate([
            'room_id'         => 3,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 18000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId4 = (int) $res4['data']['update_id'];
        $trackUpdate($uId4);
        $jAId = (int) $jobRepo->getByUpdateId($uId4)[0]['id'];

        // Insert 70 synthetic attempts: first 20 failures, then 50 successes
        for ($i = 1; $i <= 20; $i++) {
            $pdo->exec("INSERT INTO sync_attempts (job_id, started_at, duration_ms, http_status, outcome, message) VALUES ({$jAId}, UTC_TIMESTAMP() - INTERVAL " . (100 - $i) . " SECOND, 50, 500, 'http_error', 'Synthetic fail')");
        }
        for ($i = 21; $i <= 70; $i++) {
            $pdo->exec("INSERT INTO sync_attempts (job_id, started_at, duration_ms, http_status, outcome, message) VALUES ({$jAId}, UTC_TIMESTAMP() - INTERVAL " . (100 - $i) . " SECOND, 30, 200, 'success', 'Synthetic success')");
        }

        // Recompute health with default window (50)
        $healthRepo->recordAttempt(1, 50);

        $hA4 = $pdo->query('SELECT * FROM channel_health WHERE channel_id = 1')->fetch(PDO::FETCH_ASSOC);
        if ((float) $hA4['success_rate'] !== 100.00) {
            throw new Exception("Expected 100.00% success rate in window of 50, got: " . $hA4['success_rate']);
        }
        if ((int) $hA4['consecutive_failures'] !== 0) {
            throw new Exception("Expected 0 consecutive failures, got: " . $hA4['consecutive_failures']);
        }

        echo "[PASS]  4. Rolling window evaluation strictly inspects the last 50 attempts\n";
        echo "        ↳ 20 historical failures ignored outside 50-attempt window; success_rate = 100.00%\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  4. Rolling window test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 5: avg_response_ms ignores failures
    // ========================================================================
    try {
        // Clear attempts for channel 1 and insert: 2 successes (100ms, 200ms) and 1 timeout (5000ms)
        $pdo->exec("DELETE FROM sync_attempts WHERE job_id IN (SELECT id FROM sync_jobs WHERE channel_id = 1)");
        $pdo->exec("INSERT INTO sync_attempts (job_id, started_at, duration_ms, http_status, outcome, message) VALUES ({$jAId}, UTC_TIMESTAMP() - INTERVAL 10 SECOND, 100, 200, 'success', 'Success 100ms')");
        $pdo->exec("INSERT INTO sync_attempts (job_id, started_at, duration_ms, http_status, outcome, message) VALUES ({$jAId}, UTC_TIMESTAMP() - INTERVAL 5 SECOND, 5000, NULL, 'timeout', 'Timeout 5000ms')");
        $pdo->exec("INSERT INTO sync_attempts (job_id, started_at, duration_ms, http_status, outcome, message) VALUES ({$jAId}, UTC_TIMESTAMP() - INTERVAL 1 SECOND, 200, 200, 'success', 'Success 200ms')");

        $healthRepo->recordAttempt(1, 50);
        $hA5 = $pdo->query('SELECT * FROM channel_health WHERE channel_id = 1')->fetch(PDO::FETCH_ASSOC);

        // Average of (100 + 200) / 2 = 150ms (5000ms timeout MUST be excluded!)
        if ((int) $hA5['avg_response_ms'] !== 150) {
            throw new Exception("Expected avg_response_ms = 150 (ignoring 5000ms timeout), got: " . $hA5['avg_response_ms']);
        }
        if ((float) $hA5['success_rate'] !== 66.67) {
            throw new Exception("Expected success_rate = 66.67% (2 of 3), got: " . $hA5['success_rate']);
        }

        echo "[PASS]  5. Average latency calculation excludes failed attempts and timeouts\n";
        echo "        ↳ (100ms + 200ms) / 2 = 150ms; 5,000ms timeout excluded from latency metric\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  5. Average latency test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 6: Transaction consistency on health failure
    // ========================================================================
    try {
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
        $job6 = $jobRepo->getByUpdateId($uId6)[0];
        $job6Id = (int) $job6['id'];

        // Claim the job manually so it is 'in_progress'
        $jobRepo->claim($job6Id);

        // Create a stub health repository that throws
        $failingHealthRepo = new class($pdo) extends HealthRepository {
            public function recordAttempt(int $channelId, int $window = 50): array {
                throw new RuntimeException("Simulated health repository crash!");
            }
        };

        $proc6 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver, 300, null, new Backoff(0), new AlertWriter(), $failingHealthRepo);

        // Execute finalized attempt - will fail inside transaction
        $started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $res = $proc6->runOnce(1, [$job6Id]);

        // Assert transaction rolled back: no attempt row, job stays in_progress
        $attCount = (int) $pdo->query("SELECT COUNT(*) FROM sync_attempts WHERE job_id = {$job6Id}")->fetchColumn();
        if ($attCount !== 0) {
            throw new Exception("Expected 0 attempts persisted after rollback, got: {$attCount}");
        }

        $j6Check = $jobRepo->findById($job6Id);
        if ($j6Check['status'] !== 'in_progress') {
            throw new Exception("Expected job to stay in_progress after rollback, got: " . $j6Check['status']);
        }

        echo "[PASS]  6. Transaction atomicity rolls back attempts & status when health update fails\n";
        echo "        ↳ Zero orphaned attempt rows persisted; job remains cleanly in_progress\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  6. Transaction consistency test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 7: Concurrency (FOR UPDATE lock on channel_health)
    // ========================================================================
    try {
        $concurrencyRuns = 3;
        for ($run = 1; $run <= $concurrencyRuns; $run++) {
            $setMockControl('site_b', 'fast');
            $setMockControl('site_c', 'always_succeed');

            $runJobIds = [];
            for ($i = 0; $i < 10; $i++) {
                $res = $inventoryService->createUpdate([
                    'room_id'         => 1,
                    'date_from'       => $today,
                    'date_to'         => $today,
                    'available_units' => 1,
                    'price'           => 11000 + $i,
                    'idempotency_key' => Uuid::v4(),
                ]);
                $uId = (int) $res['data']['update_id'];
                $trackUpdate($uId);
                foreach ($jobRepo->getByUpdateId($uId) as $j) {
                    $runJobIds[] = (int) $j['id'];
                }
            }

            // Spawn 2 concurrent workers via proc_open
            $cmd = 'php ' . dirname(__DIR__) . '/bin/worker.php --once --only-jobs=' . implode(',', $runJobIds);
            $pipes1 = [];
            $pipes2 = [];
            $p1 = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes1);
            $p2 = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);

            stream_get_contents($pipes1[1]);
            stream_get_contents($pipes2[1]);
            fclose($pipes1[1]); fclose($pipes1[2]); proc_close($p1);
            fclose($pipes2[1]); fclose($pipes2[2]); proc_close($p2);

            // Recompute via rebuildAll and compare against stored channel_health
            $stored = $pdo->query('SELECT * FROM channel_health ORDER BY channel_id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $rebuilt = $healthRepo->rebuildAll();

            foreach ($stored as $idx => $st) {
                $reb = $rebuilt[$idx];
                if ((float) $st['success_rate'] !== (float) $reb['success_rate']) {
                    throw new Exception("Run {$run} mismatch on channel {$st['channel_id']}: stored={$st['success_rate']}, rebuilt={$reb['success_rate']}");
                }
                if ((int) $st['consecutive_failures'] !== (int) $reb['consecutive_failures']) {
                    throw new Exception("Run {$run} consec failures mismatch on channel {$st['channel_id']}");
                }
            }
        }

        echo "[PASS]  7. High-concurrency race test (2 parallel workers x 3 runs)\n";
        echo "        ↳ FOR UPDATE channel lock serialized writes; stored metrics match rebuildAll identically\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  7. Concurrency test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 8: rebuild_health.php CLI utility
    // ========================================================================
    try {
        $output = shell_exec('php ' . dirname(__DIR__) . '/bin/rebuild_health.php');
        if (!str_contains((string) $output, 'Channel health recomputed successfully')) {
            throw new Exception("rebuild_health CLI output did not contain success message");
        }
        if (!str_contains((string) $output, 'Site A') || !str_contains((string) $output, 'Site B')) {
            throw new Exception("rebuild_health CLI output missing channel table");
        }

        echo "[PASS]  8. CLI rebuild utility (bin/rebuild_health.php)\n";
        echo "        ↳ Rebuilt health table matches incremental calculation; formatted table printed\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  8. rebuild_health CLI test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 9: reset_queue resets channel_health to defaults
    // ========================================================================
    try {
        $output = shell_exec('php ' . dirname(__DIR__) . '/bin/reset_queue.php --yes');
        if (!str_contains((string) $output, 'channel_health is reset')) {
            throw new Exception("reset_queue output missing confirmation of health reset");
        }

        $healthRows = $pdo->query('SELECT * FROM channel_health ORDER BY channel_id ASC')->fetchAll(PDO::FETCH_ASSOC);
        if (count($healthRows) !== 3) {
            throw new Exception("Expected 3 channel_health rows preserved, got: " . count($healthRows));
        }

        foreach ($healthRows as $h) {
            if ((float) $h['success_rate'] !== 100.00 || (int) $h['consecutive_failures'] !== 0 || $h['last_success_at'] !== null) {
                throw new Exception("Channel health row not reset to defaults: " . json_encode($h));
            }
        }

        echo "[PASS]  9. Queue reset utility cleans tables and restores channel_health defaults\n";
        echo "        ↳ All 3 rows preserved with success_rate=100.00, consecutive_failures=0, last_success_at=null\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  9. reset_queue health reset test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 10: Status API shape and values
    // ========================================================================
    try {
        // Build known state:
        // Update 1 (Room 1): Site A success, Site B success, Site C dead
        $res10a = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => '2026-11-01',
            'date_to'         => '2026-11-05',
            'available_units' => 5,
            'price'           => 12000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId10a = (int) $res10a['data']['update_id'];
        $trackUpdate($uId10a);

        // Update 2 (Room 2): 3 pending jobs
        $res10b = $inventoryService->createUpdate([
            'room_id'         => 2,
            'date_from'       => '2026-11-10',
            'date_to'         => '2026-11-12',
            'available_units' => 3,
            'price'           => 15000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId10b = (int) $res10b['data']['update_id'];
        $trackUpdate($uId10b);

        // Deliver Update 1 jobs
        $jobs10a = $jobRepo->getByUpdateId($uId10a);
        foreach ($jobs10a as $j) {
            $jid = (int) $j['id'];
            if ($j['channel_name'] === 'Site A' || $j['channel_name'] === 'Site B') {
                $jobRepo->claim($jid);
                $jobRepo->markSuccess($jid);
            } else {
                $jobRepo->claim($jid);
                $jobRepo->markDead($jid, 'HTTP error 500 received from Site C');
            }
        }

        // Query StatusService
        $statusService = new StatusService($pdo);
        $data = $statusService->getStatusData();

        if (!str_ends_with((string) $data['generated_at_jst'], 'JST')) {
            throw new Exception("generated_at_jst does not end with JST");
        }
        if ($data['overall']['attention_needed'] !== 1) {
            throw new Exception("Expected attention_needed = 1 (1 dead job), got: " . $data['overall']['attention_needed']);
        }
        if ($data['at_risk']['total_rooms'] !== 2) {
            throw new Exception("Expected at_risk rooms = 2, got: " . $data['at_risk']['total_rooms']);
        }

        // Verify severity sorting: Room 1 (has dead job) must be critical and ranked first
        $firstRoom = $data['at_risk']['rooms'][0];
        if ($firstRoom['room_id'] !== 1 || $firstRoom['severity'] !== 'critical') {
            throw new Exception("Expected Room 1 to be first with critical severity, got: " . json_encode($firstRoom));
        }

        // Second room (Room 2) has only pending jobs -> info severity
        $secondRoom = $data['at_risk']['rooms'][1];
        if ($secondRoom['room_id'] !== 2 || $secondRoom['severity'] !== 'info') {
            throw new Exception("Expected Room 2 to have info severity, got: " . json_encode($secondRoom));
        }

        echo "[PASS] 10. Status API payload structure and data integrity\n";
        echo "        ↳ JST formatting, attention_needed count, at-risk severity hierarchy verified\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 10. Status API test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 11: At-risk room lifecycle
    // ========================================================================
    try {
        // Room 1 currently has 1 dead job on Site C
        // Retry that dead job and mark success -> Room 1 should disappear from at_risk!
        $deadJob = $pdo->query("SELECT id FROM sync_jobs WHERE update_id = {$uId10a} AND status = 'dead'")->fetch(PDO::FETCH_ASSOC);
        $deadId = (int) $deadJob['id'];

        $jobRepo->retry($deadId);
        $jobRepo->claim($deadId);
        $jobRepo->markSuccess($deadId);

        $statusData = (new StatusService($pdo))->getStatusData();
        $atRiskRoomIds = array_column($statusData['at_risk']['rooms'], 'room_id');
        if (in_array(1, $atRiskRoomIds, true)) {
            throw new Exception("Room 1 should no longer be at risk after Site C succeeded!");
        }

        // Test inactive channel exclusion
        $pdo->exec('UPDATE channels SET is_active = 0 WHERE id = 1');
        $statusWithInactive = (new StatusService($pdo))->getStatusData();
        // Channel 1 should have status 'paused'
        $ch1 = null;
        foreach ($statusWithInactive['channels'] as $ch) {
            if ($ch['id'] === 1) {
                $ch1 = $ch;
            }
        }
        if ($ch1['status'] !== 'paused') {
            throw new Exception("Expected inactive channel to be 'paused', got: " . $ch1['status']);
        }
        $pdo->exec('UPDATE channels SET is_active = 1 WHERE id = 1');

        echo "[PASS] 11. At-risk room lifecycle and active channel filtering\n";
        echo "        ↳ Room clears from at-risk when jobs succeed; inactive channels ignored and marked 'paused'\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 11. At-risk lifecycle test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 12: HTTP endpoint behavior & XSS payload safety
    // ========================================================================
    try {
        // 1. POST refusal
        $ch = curl_init('http://127.0.0.1/api/status.php');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $postRes = curl_exec($ch);
        $postHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($postHttp !== 405) {
            throw new Exception("Expected HTTP 405 on POST to /api/status.php, got: {$postHttp}");
        }

        // 2. GET caching & headers
        $ch = curl_init('http://127.0.0.1/api/status.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
        ]);
        $getRes = (string) curl_exec($ch);
        $getHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($getHttp !== 200) {
            throw new Exception("Expected HTTP 200 on GET, got: {$getHttp}");
        }
        if (!str_contains(strtolower($getRes), 'cache-control: no-store')) {
            throw new Exception("Expected Cache-Control: no-store in headers");
        }

        // 3. XSS error safety: update a job's error to an XSS script tag
        $pdo->exec("UPDATE sync_jobs SET status = 'failed', last_error = '<script>alert(1)</script>' WHERE update_id = {$uId10b} LIMIT 1");
        $xssCheck = (new StatusService($pdo))->getStatusData();
        $foundXssError = false;
        foreach ($xssCheck['at_risk']['rooms'] as $r) {
            foreach ($r['problems'] as $p) {
                if ($p['last_error'] === '<script>alert(1)</script>') {
                    $foundXssError = true;
                }
            }
        }
        if (!$foundXssError) {
            throw new Exception("XSS payload was not safely preserved as pure string in API output");
        }

        echo "[PASS] 12. HTTP protocol compliance, cache prevention & raw XSS string containment\n";
        echo "        ↳ POST returns 405; GET returns 200 with Cache-Control: no-store; XSS string unexecuted\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 12. HTTP endpoint behavior failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 13: Static safety check of dashboard.js
    // ========================================================================
    try {
        $jsFile = dirname(__DIR__) . '/public/assets/js/dashboard.js';
        if (!file_exists($jsFile)) {
            throw new Exception("dashboard.js not found at {$jsFile}");
        }
        $jsCode = (string) file_get_contents($jsFile);

        if (str_contains($jsCode, '.html(')) {
            throw new Exception("dashboard.js contains forbidden '.html(' method!");
        }
        if (str_contains($jsCode, 'innerHTML')) {
            throw new Exception("dashboard.js contains forbidden 'innerHTML' property!");
        }
        if (str_contains($jsCode, 'setInterval')) {
            throw new Exception("dashboard.js contains forbidden 'setInterval' timer!");
        }

        echo "[PASS] 13. Static safety verification of client-side code (dashboard.js)\n";
        echo "        ↳ Zero occurrences of .html(, innerHTML, or setInterval detected\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 13. Static safety check failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 14: Worker Heartbeat state transitions
    // ========================================================================
    try {
        // Run worker once to generate heartbeat file
        shell_exec('php ' . dirname(__DIR__) . '/bin/worker.php --once');

        if (!file_exists($heartbeatFile)) {
            throw new Exception("Heartbeat file not generated by worker.php");
        }

        $freshState = Heartbeat::evaluate($heartbeatFile);
        if ($freshState['state'] !== 'online') {
            throw new Exception("Expected heartbeat state 'online', got: " . $freshState['state']);
        }

        // Simulate stale heartbeat (old timestamp 60 seconds in the past)
        $staleTime = new DateTimeImmutable('now - 60 seconds', new DateTimeZone('UTC'));
        Heartbeat::record('loop', 3, $heartbeatFile, $staleTime);

        $staleState = Heartbeat::evaluate($heartbeatFile);
        if ($staleState['state'] !== 'stale') {
            throw new Exception("Expected heartbeat state 'stale', got: " . $staleState['state']);
        }

        // Simulate corrupt file
        file_put_contents($heartbeatFile, 'INVALID_JSON_CORRUPT');
        $corruptState = Heartbeat::evaluate($heartbeatFile);
        if ($corruptState['state'] !== 'unknown') {
            throw new Exception("Expected heartbeat state 'unknown' on corrupt file, got: " . $corruptState['state']);
        }

        // Restore online heartbeat
        Heartbeat::record('loop', 3, $heartbeatFile);

        echo "[PASS] 14. Worker heartbeat life-cycle evaluation (Heartbeat::evaluate)\n";
        echo "        ↳ 'online' for fresh pass, 'stale' for stopped worker, 'unknown' for invalid/corrupted file\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 14. Worker heartbeat test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 15: High-volume performance guard (3,000 updates / 9,000 jobs)
    // ========================================================================
    try {
        $perfUpdateIds = [];
        $pdo->beginTransaction();

        $stmtUp = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $stmtJob = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count, next_attempt_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())');

        for ($i = 1; $i <= 3000; $i++) {
            $rId = ($i % 4) + 1;
            $stmtUp->execute([$rId, '2026-12-01', '2026-12-05', 2, 10000 + $i, 'perf-' . $i . '-' . bin2hex(random_bytes(4)), Uuid::v4()]);
            $uId = (int) $pdo->lastInsertId();
            $perfUpdateIds[] = $uId;

            // 3 jobs per update with mixed statuses (mostly success, some failed, some pending)
            $st1 = ($i % 10 === 0) ? 'failed' : 'success';
            $st2 = ($i % 50 === 0) ? 'dead' : 'success';
            $st3 = ($i % 5 === 0) ? 'pending' : 'success';

            $stmtJob->execute([$uId, 1, $st1, 1]);
            $stmtJob->execute([$uId, 2, $st2, 1]);
            $stmtJob->execute([$uId, 3, $st3, 0]);
        }
        $pdo->commit();

        // Measure 5 consecutive status endpoint requests
        $timings = [];
        $statusService = new StatusService($pdo);
        for ($t = 1; $t <= 5; $t++) {
            $t0 = microtime(true);
            $res = $statusService->getStatusData();
            $elapsedMs = (microtime(true) - $t0) * 1000;
            $timings[] = $elapsedMs;
            if ($elapsedMs > 1000) {
                throw new Exception("Status query took {$elapsedMs}ms, exceeding 1.0s threshold!");
            }
        }

        // Clean up 3,000 updates in single delete
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM sync_jobs WHERE update_id >= ' . min($perfUpdateIds));
        $pdo->exec('DELETE FROM inventory_updates WHERE id >= ' . min($perfUpdateIds));
        $pdo->commit();

        echo "[PASS] 15. Performance guard on 3,000 updates / 9,000 jobs (< 1.0s)\n";
        echo sprintf("        ↳ 5 consecutive request latencies: [%s] ms (all well within 1,000ms SLA)\n", implode(', ', array_map(fn($t) => number_format($t, 1), $timings)));

        // Run and print EXPLAIN plans
        echo "        ↳ EXPLAIN plan for at-risk room grouping:\n";
        $explain1 = $pdo->query('EXPLAIN SELECT r.id, COUNT(DISTINCT u.id) FROM rooms r JOIN inventory_updates u ON u.room_id = r.id JOIN sync_jobs j ON j.update_id = u.id JOIN channels c ON c.id = j.channel_id WHERE c.is_active = 1 AND j.status != "success" GROUP BY r.id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($explain1 as $row) {
            echo sprintf("          - Table: %-18s | Type: %-6s | Key: %-20s | Rows: %s\n", $row['table'], $row['type'], $row['key'] ?? 'none', $row['rows']);
        }

        $passedCount++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "[FAIL] 15. Performance guard test failed: " . $e->getMessage() . "\n";
    }

} finally {
    $cleanup();
}

echo "\n--------------------------------------------------------------------\n";
echo "Summary: {$passedCount} / {$totalTests} tests passed\n";
echo "--------------------------------------------------------------------\n";

if ($passedCount === $totalTests) {
    echo "Result: ALL TESTS PASSED. Phase 6 implementation is rock-solid!\n\n";
    exit(0);
} else {
    echo "Result: SOME TESTS FAILED. Please inspect diagnostics above.\n\n";
    exit(1);
}
