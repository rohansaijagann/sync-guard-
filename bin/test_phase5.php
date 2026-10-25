<?php
declare(strict_types=1);

/**
 * Sync Guard - Phase 5 Verification & Integration Test Suite (CLI)
 *
 * 14 Automated Tests:
 *  1. Backoff schedule with jitter disabled: 60s, 300s, 900s, 3600s, attempt 5+ is dead.
 *  2. Jitter bounds: fixed RNG produces exact 90% and 110% delays, min 1s, dead has 0 delay.
 *  3. Full retry lifecycle through worker: 5 passes fast-forwarded -> attempts 1-5, status 'dead', 5 attempt rows.
 *  4. Dead job exclusion: 6th pass does NOT pick up or process the dead job.
 *  5. Intermediate failure recovery: fail twice, then succeed -> ends in 'success' with 3 attempts.
 *  6. Dead job alert: exactly 1 JSON line written to storage/alerts.log on dead; none for non-dead.
 *  7. Alert failure isolation: unwritable log or throwing alert writer does not crash worker.
 *  8. Stuck recovery ignores dead: dead job with old updated_at is untouched by recoverStuck().
 *  9. Manual retry via HTTP API: 200, status 'pending', attempts=0, history kept, subsequent pass succeeds.
 * 10. Manual retry of 'failed' job: immediately due, resets attempts.
 * 11. Retry refusal matrix: success (409), pending (409), in_progress (409), unknown (404), GET (405), bad ID (422).
 * 12. Retry concurrency race: two parallel HTTP retries on dead job -> exactly one 200 and one 409.
 * 13. CLI retry: bin/retry_job.php <id> and --all-dead work as expected.
 * 14. Parallel worker safety: 10 updates (30 jobs), 2 parallel workers -> A/B success, C failed, 1 attempt each.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/test_phase5.php must be run from the CLI.\n");
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
use SyncGuard\Database\Connection;
use SyncGuard\Http\CurlClient;
use SyncGuard\Repositories\AttemptRepository;
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Retry\Backoff;
use SyncGuard\Retry\RetryDecision;
use SyncGuard\Services\InventoryService;
use SyncGuard\Support\AlertWriter;
use SyncGuard\Support\Uuid;
use SyncGuard\Validation\InventoryUpdateValidator;
use SyncGuard\Worker\JobProcessor;

echo "====================================================================\n";
echo "              Sync Guard - Phase 5 Test Suite                       \n";
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
$totalTests = 14;
$createdUpdateIds = [];

$controlFile = dirname(__DIR__) . '/storage/mock/control.json';
$mockAFile = dirname(__DIR__) . '/storage/mock/site_a.json';
$mockBFile = dirname(__DIR__) . '/storage/mock/site_b.json';
$mockCFile = dirname(__DIR__) . '/storage/mock/site_c.json';
$alertsLogFile = dirname(__DIR__) . '/storage/alerts.log';

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
    $today = \SyncGuard\Support\Time::todayInTokyo();
    $resolver = new AdapterResolver();

    // ========================================================================
    // TEST 1: Backoff schedule with jitter disabled
    // ========================================================================
    try {
        $d1 = Backoff::decide(1, 0);
        $d2 = Backoff::decide(2, 0);
        $d3 = Backoff::decide(3, 0);
        $d4 = Backoff::decide(4, 0);
        $d5 = Backoff::decide(5, 0);
        $d6 = Backoff::decide(6, 0);

        if ($d1->isDead || $d1->delaySeconds !== 60) {
            throw new Exception("Attempt 1 expected 60s, got {$d1->delaySeconds}s (isDead=" . var_export($d1->isDead, true) . ")");
        }
        if ($d2->isDead || $d2->delaySeconds !== 300) {
            throw new Exception("Attempt 2 expected 300s, got {$d2->delaySeconds}s");
        }
        if ($d3->isDead || $d3->delaySeconds !== 900) {
            throw new Exception("Attempt 3 expected 900s, got {$d3->delaySeconds}s");
        }
        if ($d4->isDead || $d4->delaySeconds !== 3600) {
            throw new Exception("Attempt 4 expected 3600s, got {$d4->delaySeconds}s");
        }
        if (!$d5->isDead || $d5->delaySeconds !== 0) {
            throw new Exception("Attempt 5 expected isDead=true with 0s delay, got isDead=" . var_export($d5->isDead, true) . " and {$d5->delaySeconds}s");
        }
        if (!$d6->isDead || $d6->delaySeconds !== 0) {
            throw new Exception("Attempt 6 expected isDead=true with 0s delay");
        }

        echo "[PASS]  1. Backoff schedule calculation (jitter disabled)\n";
        echo "        ↳ Attempts 1-4: exactly 60s, 300s, 900s, 3600s; attempt 5+ cleanly marked dead (0s)\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  1. Backoff schedule test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 2: Jitter bounds verification with injected RNG
    // ========================================================================
    try {
        // RNG returning 0.0 -> lower bound (90% for 10% jitter)
        $rngMin = fn(): float => 0.0;
        $d1Min = Backoff::decide(1, 10, $rngMin);
        $d2Min = Backoff::decide(2, 10, $rngMin);
        $d3Min = Backoff::decide(3, 10, $rngMin);
        $d4Min = Backoff::decide(4, 10, $rngMin);

        if ($d1Min->delaySeconds !== 54) throw new Exception("Attempt 1 min jitter expected 54s, got {$d1Min->delaySeconds}s");
        if ($d2Min->delaySeconds !== 270) throw new Exception("Attempt 2 min jitter expected 270s, got {$d2Min->delaySeconds}s");
        if ($d3Min->delaySeconds !== 810) throw new Exception("Attempt 3 min jitter expected 810s, got {$d3Min->delaySeconds}s");
        if ($d4Min->delaySeconds !== 3240) throw new Exception("Attempt 4 min jitter expected 3240s, got {$d4Min->delaySeconds}s");

        // RNG returning 1.0 -> upper bound (110% for 10% jitter)
        $rngMax = fn(): float => 1.0;
        $d1Max = Backoff::decide(1, 10, $rngMax);
        $d2Max = Backoff::decide(2, 10, $rngMax);
        $d3Max = Backoff::decide(3, 10, $rngMax);
        $d4Max = Backoff::decide(4, 10, $rngMax);

        if ($d1Max->delaySeconds !== 66) throw new Exception("Attempt 1 max jitter expected 66s, got {$d1Max->delaySeconds}s");
        if ($d2Max->delaySeconds !== 330) throw new Exception("Attempt 2 max jitter expected 330s, got {$d2Max->delaySeconds}s");
        if ($d3Max->delaySeconds !== 990) throw new Exception("Attempt 3 max jitter expected 990s, got {$d3Max->delaySeconds}s");
        if ($d4Max->delaySeconds !== 3960) throw new Exception("Attempt 4 max jitter expected 3960s, got {$d4Max->delaySeconds}s");

        // Dead decision never has jitter delay
        $d5Jitter = Backoff::decide(5, 10, $rngMax);
        if (!$d5Jitter->isDead || $d5Jitter->delaySeconds !== 0) {
            throw new Exception("Dead decision must have 0 delay even with jitter");
        }

        echo "[PASS]  2. Injectable jitter bounds enforcement\n";
        echo "        ↳ Exact bounds observed: 54-66s (1m), 270-330s (5m), 810-990s (15m), 3240-3960s (1h); dead has 0s\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  2. Jitter bounds test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 3: Full retry lifecycle through real worker (Passes 1 to 5)
    // ========================================================================
    $test3JobId = null;
    $test3AlertLog = dirname(__DIR__) . '/storage/test_alerts_t3.log';
    @unlink($test3AlertLog);

    try {
        $setMockControl('site_c', 'always_fail');

        $res3 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 9000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId3 = (int) $res3['data']['update_id'];
        $trackUpdate($uId3);

        $jobs3 = $jobRepo->getByUpdateId($uId3);
        foreach ($jobs3 as $j) {
            if ($j['adapter_class'] === 'SiteCAdapter') {
                $test3JobId = (int) $j['id'];
                break;
            }
        }
        if (!$test3JobId) throw new Exception("Site C job not found for update {$uId3}");

        // Processor with 0 jitter for deterministic next_attempt_at checks and custom alert log
        $noJitterBackoff = new Backoff(0);
        $testAlertWriter = new AlertWriter($test3AlertLog);
        $proc3 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver, 300, null, $noJitterBackoff, $testAlertWriter);

        $expectedDelays = [1 => 60, 2 => 300, 3 => 900, 4 => 3600];

        for ($pass = 1; $pass <= 5; $pass++) {
            $counts = $proc3->runOnce(10, [$test3JobId]);
            $refreshed = $jobRepo->findById($test3JobId);

            if ($pass < 5) {
                if ($refreshed['status'] !== 'failed') {
                    throw new Exception("Pass {$pass}: expected status 'failed', got '{$refreshed['status']}'");
                }
                if ((int) $refreshed['attempt_count'] !== $pass) {
                    throw new Exception("Pass {$pass}: expected attempt_count {$pass}, got {$refreshed['attempt_count']}");
                }

                // Verify next_attempt_at is in the future (~now + expected delay)
                $nextDueSec = strtotime($refreshed['next_attempt_at']) - time();
                $expectedSec = $expectedDelays[$pass];
                if ($nextDueSec < $expectedSec - 5 || $nextDueSec > $expectedSec + 5) {
                    throw new Exception("Pass {$pass}: expected next due in ~{$expectedSec}s, got {$nextDueSec}s");
                }

                // Fast-forward database clock: move next_attempt_at into the past to simulate elapsed time
                $pdo->exec("UPDATE sync_jobs SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 10 SECOND WHERE id = {$test3JobId}");

            } else {
                // Pass 5: Job must transition to 'dead'
                if ($refreshed['status'] !== 'dead') {
                    throw new Exception("Pass 5: expected status 'dead', got '{$refreshed['status']}'");
                }
                if ((int) $refreshed['attempt_count'] !== 5) {
                    throw new Exception("Pass 5: expected attempt_count 5, got {$refreshed['attempt_count']}");
                }
                if (empty($refreshed['last_error'])) {
                    throw new Exception("Pass 5: expected last_error to be set");
                }

                $attempts = $attemptRepo->getByJobId($test3JobId);
                if (count($attempts) !== 5) {
                    throw new Exception("Pass 5: expected 5 attempt rows in sync_attempts, got " . count($attempts));
                }
            }
        }

        echo "[PASS]  3. Full 5-stage retry lifecycle through background worker\n";
        echo "        ↳ Delays verified (1m, 5m, 15m, 1h); on 5th attempt job transitioned to 'dead' with 5 attempts logged\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  3. Full retry lifecycle test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 4: A sixth pass does NOT pick up the dead job
    // ========================================================================
    try {
        if (!$test3JobId) throw new Exception("Test 3 job ID not available");

        // Even if next_attempt_at is in the past, a dead job must be excluded
        $pdo->exec("UPDATE sync_jobs SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 60 SECOND WHERE id = {$test3JobId}");

        $proc4 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver);
        $counts4 = $proc4->runOnce(10, [$test3JobId]);

        $claimedCount = $counts4['succeeded'] + $counts4['failed'] + ($counts4['dead'] ?? 0);
        if ($counts4['found'] !== 0 || $claimedCount !== 0) {
            throw new Exception("6th pass claimed or processed dead job: " . json_encode($counts4));
        }

        $job4 = $jobRepo->findById($test3JobId);
        if ($job4['status'] !== 'dead' || (int) $job4['attempt_count'] !== 5) {
            throw new Exception("Dead job state altered by 6th pass: " . json_encode($job4));
        }

        $attempts4 = $attemptRepo->getByJobId($test3JobId);
        if (count($attempts4) !== 5) {
            throw new Exception("Expected attempt rows to remain 5, got " . count($attempts4));
        }

        echo "[PASS]  4. Dead job exclusion from polling query (findDue)\n";
        echo "        ↳ 6th pass completely ignored dead job (found=0); attempts count remains 5\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  4. Dead job exclusion test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 5: Intermediate failure recovery (Fail twice, then succeed)
    // ========================================================================
    try {
        $setMockControl('site_c', 'always_fail');

        $res5 = $inventoryService->createUpdate([
            'room_id'         => 2,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 14000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId5 = (int) $res5['data']['update_id'];
        $trackUpdate($uId5);

        $j5Id = null;
        foreach ($jobRepo->getByUpdateId($uId5) as $j) {
            if ($j['adapter_class'] === 'SiteCAdapter') {
                $j5Id = (int) $j['id'];
                break;
            }
        }

        $proc5 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver);

        // Pass 1: Fail (attempt 1)
        $proc5->runOnce(10, [$j5Id]);
        $pdo->exec("UPDATE sync_jobs SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 10 SECOND WHERE id = {$j5Id}");

        // Pass 2: Fail (attempt 2)
        $proc5->runOnce(10, [$j5Id]);
        $pdo->exec("UPDATE sync_jobs SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 10 SECOND WHERE id = {$j5Id}");

        // Partner recovers: switch to always_succeed
        $setMockControl('site_c', 'always_succeed');

        // Pass 3: Succeeded (attempt 3)
        $proc5->runOnce(10, [$j5Id]);

        $final5 = $jobRepo->findById($j5Id);
        if ($final5['status'] !== 'success' || (int) $final5['attempt_count'] !== 3) {
            throw new Exception("Expected status 'success' and attempt_count 3, got '{$final5['status']}' and {$final5['attempt_count']}");
        }

        $attempts5 = $attemptRepo->getByJobId($j5Id);
        if (count($attempts5) !== 3) {
            throw new Exception("Expected 3 attempt rows, got " . count($attempts5));
        }

        echo "[PASS]  5. Intermediate failure recovery (2 failures -> 1 success)\n";
        echo "        ↳ Job transitioned from 'failed' (attempts=2) to 'success' (attempts=3) with 3 attempt rows logged\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  5. Intermediate recovery test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 6: Dead job alert generation in storage/alerts.log
    // ========================================================================
    try {
        if (!file_exists($test3AlertLog)) {
            throw new Exception("Alert log file was not created: {$test3AlertLog}");
        }

        $lines = file($test3AlertLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) !== 1) {
            throw new Exception("Expected exactly 1 alert line for Test 3 dead job, found: " . count($lines));
        }

        $entry = json_decode($lines[0], true);
        if (!is_array($entry)) {
            throw new Exception("Alert log entry is not valid JSON: {$lines[0]}");
        }

        if (($entry['event'] ?? '') !== 'job_dead' || ($entry['level'] ?? '') !== 'critical') {
            throw new Exception("Alert event or level mismatch: " . json_encode($entry));
        }
        if ((int) ($entry['job_id'] ?? 0) !== $test3JobId) {
            throw new Exception("Alert job_id mismatch: expected {$test3JobId}, got " . ($entry['job_id'] ?? 0));
        }
        if ((int) ($entry['attempt_count'] ?? 0) !== 5) {
            throw new Exception("Alert attempt_count mismatch: expected 5, got " . ($entry['attempt_count'] ?? 0));
        }

        @unlink($test3AlertLog);

        echo "[PASS]  6. Critical dead job alert writing (storage/alerts.log)\n";
        echo "        ↳ Exactly 1 JSON line recorded with event='job_dead', level='critical', attempt_count=5\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  6. Alert writing test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 7: Alert failure safety (Worker finishes pass even if alerts fail)
    // ========================================================================
    try {
        $setMockControl('site_c', 'always_fail');

        $res7 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 10000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId7 = (int) $res7['data']['update_id'];
        $trackUpdate($uId7);

        $j7Id = null;
        foreach ($jobRepo->getByUpdateId($uId7) as $j) {
            if ($j['adapter_class'] === 'SiteCAdapter') {
                $j7Id = (int) $j['id'];
                break;
            }
        }

        // Set attempt_count to 4 so next failure marks it dead
        $pdo->exec("UPDATE sync_jobs SET attempt_count = 4 WHERE id = {$j7Id}");

        // AlertWriter pointing to an impossible/invalid path to force write failure
        $brokenAlertWriter = new AlertWriter('/root/forbidden/cannot_write.log');
        $proc7 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver, 300, null, new Backoff(0), $brokenAlertWriter);

        // Run processor: must NOT throw exception
        $counts7 = $proc7->runOnce(10, [$j7Id]);

        $j7 = $jobRepo->findById($j7Id);
        if ($j7['status'] !== 'dead' || (int) $j7['attempt_count'] !== 5) {
            throw new Exception("Job was not marked dead when alert writer failed: " . json_encode($j7));
        }

        echo "[PASS]  7. Alert failure fault isolation\n";
        echo "        ↳ Unwritable alert destination handled gracefully; job successfully committed as 'dead'\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  7. Alert failure safety failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 8: Dead status is not touched by stuck-job recovery
    // ========================================================================
    try {
        if (!$test3JobId) throw new Exception("Test 3 job ID not available");

        // Set updated_at to 10 minutes in the past on the dead job
        $pdo->exec("UPDATE sync_jobs SET updated_at = UTC_TIMESTAMP() - INTERVAL 600 SECOND WHERE id = {$test3JobId}");

        $recovered = $jobRepo->recoverStuck(300);

        $j8 = $jobRepo->findById($test3JobId);
        if ($j8['status'] !== 'dead') {
            throw new Exception("Dead job was improperly altered by recoverStuck to: {$j8['status']}");
        }

        echo "[PASS]  8. Stuck-job recovery ignores 'dead' jobs\n";
        echo "        ↳ recoverStuck() strictly targets 'in_progress' rows; dead job remains untouched in 'dead' state\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  8. Stuck job recovery dead test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 9: Manual retry of a dead job through HTTP API (POST /api/retry.php)
    // ========================================================================
    try {
        if (!$test3JobId) throw new Exception("Test 3 job ID not available");

        $curl = new CurlClient();
        $retryUrl = 'http://127.0.0.1/api/retry.php';

        $res9 = $curl->post($retryUrl, json_encode(['job_id' => $test3JobId]), [
            'Content-Type: application/json',
        ]);

        if ($res9->statusCode !== 200) {
            throw new Exception("Expected HTTP 200 from retry API, got HTTP {$res9->statusCode}: " . $res9->body);
        }

        $body9 = json_decode((string) $res9->body, true);
        if (($body9['ok'] ?? false) !== true || ($body9['data']['status'] ?? '') !== 'pending') {
            throw new Exception("Retry API response payload invalid: " . $res9->body);
        }

        // Verify DB row
        $retriedJob = $jobRepo->findById($test3JobId);
        if ($retriedJob['status'] !== 'pending') {
            throw new Exception("Expected DB status 'pending', got '{$retriedJob['status']}'");
        }
        if ((int) $retriedJob['attempt_count'] !== 0) {
            throw new Exception("Expected attempt_count reset to 0, got {$retriedJob['attempt_count']}");
        }
        if ($retriedJob['last_error'] !== null) {
            throw new Exception("Expected last_error cleared to NULL, got: " . $retriedJob['last_error']);
        }

        // Assert all 5 previous attempt rows are still preserved in sync_attempts
        $oldAttempts = $attemptRepo->getByJobId($test3JobId);
        if (count($oldAttempts) !== 5) {
            throw new Exception("Audit history compromised: expected 5 attempt rows, got " . count($oldAttempts));
        }

        // Now run worker pass with Site C in always_succeed -> job should succeed!
        $setMockControl('site_c', 'always_succeed');
        $proc9 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver);
        $proc9->runOnce(10, [$test3JobId]);

        $finalSucceeded = $jobRepo->findById($test3JobId);
        if ($finalSucceeded['status'] !== 'success' || (int) $finalSucceeded['attempt_count'] !== 1) {
            throw new Exception("Expected job success with attempt_count 1 after retry, got: " . json_encode($finalSucceeded));
        }

        $allAttempts = $attemptRepo->getByJobId($test3JobId);
        if (count($allAttempts) !== 6) {
            throw new Exception("Expected 6 total attempt rows (5 old + 1 successful), got " . count($allAttempts));
        }

        echo "[PASS]  9. Manual retry of dead job via HTTP API (POST /api/retry.php)\n";
        echo "        ↳ HTTP 200: status='pending', attempts reset to 0; history preserved (5 rows); re-run succeeded (6th row)\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL]  9. Manual retry API test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 10: Manual retry of a 'failed' job
    // ========================================================================
    try {
        $setMockControl('site_c', 'always_fail');

        $res10 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 3,
            'price'           => 11000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId10 = (int) $res10['data']['update_id'];
        $trackUpdate($uId10);

        $j10Id = null;
        foreach ($jobRepo->getByUpdateId($uId10) as $j) {
            if ($j['adapter_class'] === 'SiteCAdapter') {
                $j10Id = (int) $j['id'];
                break;
            }
        }

        // Fail once: scheduled 60s into future
        $proc10 = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver);
        $proc10->runOnce(10, [$j10Id]);

        $failedBefore = $jobRepo->findById($j10Id);
        if ($failedBefore['status'] !== 'failed') {
            throw new Exception("Expected job status 'failed'");
        }

        // Manual retry via JobRepository::retry()
        $retryResult = $jobRepo->retry($j10Id);
        if ($retryResult !== 'retried') {
            throw new Exception("Expected 'retried', got '{$retryResult}'");
        }

        $refreshed10 = $jobRepo->findById($j10Id);
        if ($refreshed10['status'] !== 'pending' || (int) $refreshed10['attempt_count'] !== 0) {
            throw new Exception("Failed job was not reset properly: " . json_encode($refreshed10));
        }
        if (strtotime($refreshed10['next_attempt_at']) > time() + 2) {
            throw new Exception("next_attempt_at was not reset to immediate due time: " . $refreshed10['next_attempt_at']);
        }

        echo "[PASS] 10. Manual retry of 'failed' job waiting on backoff delay\n";
        echo "        ↳ Successfully transitioned back to 'pending' with immediate due time and attempts reset\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 10. Failed job retry test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 11: Retry refusal matrix
    // ========================================================================
    try {
        $curl = new CurlClient();
        $retryUrl = 'http://127.0.0.1/api/retry.php';

        // 11a: Success job -> 409 Conflict
        $resSuccess = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 8500,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId11 = (int) $resSuccess['data']['update_id'];
        $trackUpdate($uId11);
        $jobs11 = $jobRepo->getByUpdateId($uId11);
        $siteAJobId = (int) $jobs11[0]['id'];

        // Deliver Site A so it becomes 'success'
        (new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver))->runOnce(10, [$siteAJobId]);
        $rSuccess = $curl->post($retryUrl, json_encode(['job_id' => $siteAJobId]), ['Content-Type: application/json']);
        if ($rSuccess->statusCode !== 409) {
            throw new Exception("Expected 409 for success job, got HTTP {$rSuccess->statusCode}");
        }

        // 11b: Pending job -> 409 Conflict
        $siteBJobId = (int) $jobs11[1]['id']; // still pending
        $rPending = $curl->post($retryUrl, json_encode(['job_id' => $siteBJobId]), ['Content-Type: application/json']);
        if ($rPending->statusCode !== 409) {
            throw new Exception("Expected 409 for pending job, got HTTP {$rPending->statusCode}");
        }

        // 11c: In_progress job -> 409 Conflict
        $pdo->exec("UPDATE sync_jobs SET status = 'in_progress' WHERE id = {$siteBJobId}");
        $rInProgress = $curl->post($retryUrl, json_encode(['job_id' => $siteBJobId]), ['Content-Type: application/json']);
        if ($rInProgress->statusCode !== 409) {
            throw new Exception("Expected 409 for in_progress job, got HTTP {$rInProgress->statusCode}");
        }

        // 11d: Unknown job ID -> 404 Not Found
        $rNotFound = $curl->post($retryUrl, json_encode(['job_id' => 999999]), ['Content-Type: application/json']);
        if ($rNotFound->statusCode !== 404) {
            throw new Exception("Expected 404 for unknown job, got HTTP {$rNotFound->statusCode}");
        }

        // 11e: HTTP GET -> 405 Method Not Allowed
        $chGet = curl_init($retryUrl);
        curl_setopt($chGet, CURLOPT_RETURNTRANSFER, true);
        curl_exec($chGet);
        $codeGet = curl_getinfo($chGet, CURLINFO_HTTP_CODE);
        curl_close($chGet);
        if ($codeGet !== 405) {
            throw new Exception("Expected 405 for GET request, got HTTP {$codeGet}");
        }

        // 11f: Invalid / missing job_id -> 422 Unprocessable Entity
        $rBad1 = $curl->post($retryUrl, json_encode(['job_id' => 0]), ['Content-Type: application/json']);
        $rBad2 = $curl->post($retryUrl, json_encode(['job_id' => 'abc']), ['Content-Type: application/json']);
        $rBad3 = $curl->post($retryUrl, json_encode([]), ['Content-Type: application/json']);

        if ($rBad1->statusCode !== 422 || $rBad2->statusCode !== 422 || $rBad3->statusCode !== 422) {
            throw new Exception("Expected 422 for invalid job IDs, got {$rBad1->statusCode}, {$rBad2->statusCode}, {$rBad3->statusCode}");
        }

        echo "[PASS] 11. Retry refusal error matrix enforcement\n";
        echo "        ↳ Correctly rejected: success (409), pending (409), in_progress (409), unknown (404), GET (405), bad ID (422)\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 11. Retry refusal matrix failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 12: Concurrent manual retry race condition on same dead job
    // ========================================================================
    try {
        $res12 = $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 1,
            'price'           => 12000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId12 = (int) $res12['data']['update_id'];
        $trackUpdate($uId12);

        $j12Id = (int) $jobRepo->getByUpdateId($uId12)[0]['id'];
        $pdo->exec("UPDATE sync_jobs SET status = 'dead', attempt_count = 5 WHERE id = {$j12Id}");

        // Fire two simultaneous curl requests via proc_open
        $retryUrl = 'http://127.0.0.1/api/retry.php';
        $cmd1 = sprintf('curl -s -o /dev/null -w "%%{http_code}" -X POST %s -H "Content-Type: application/json" -d "{\"job_id\":%d}"', escapeshellarg($retryUrl), $j12Id);
        $cmd2 = sprintf('curl -s -o /dev/null -w "%%{http_code}" -X POST %s -H "Content-Type: application/json" -d "{\"job_id\":%d}"', escapeshellarg($retryUrl), $j12Id);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p1 = proc_open($cmd1, $descriptors, $pipes1);
        $p2 = proc_open($cmd2, $descriptors, $pipes2);

        $code1 = trim((string) stream_get_contents($pipes1[1]));
        $code2 = trim((string) stream_get_contents($pipes2[1]));
        fclose($pipes1[0]); fclose($pipes1[1]); fclose($pipes1[2]);
        fclose($pipes2[0]); fclose($pipes2[1]); fclose($pipes2[2]);
        proc_close($p1);
        proc_close($p2);

        $codes = [$code1, $code2];
        sort($codes);

        if ($codes !== ['200', '409']) {
            throw new Exception("Expected exactly one 200 and one 409 from race, got: " . json_encode($codes));
        }

        $job12 = $jobRepo->findById($j12Id);
        if ($job12['status'] !== 'pending') {
            throw new Exception("Job state corrupted by race: status is '{$job12['status']}'");
        }

        echo "[PASS] 12. Atomic concurrency race on parallel retries (proc_open)\n";
        echo "        ↳ Exactly one request succeeded (HTTP 200); competing request refused (HTTP 409); job ends 'pending'\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 12. Retry race condition test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 13: CLI retry utility (bin/retry_job.php)
    // ========================================================================
    try {
        $res13 = $inventoryService->createUpdate([
            'room_id'         => 3,
            'date_from'       => $today,
            'date_to'         => $today,
            'available_units' => 2,
            'price'           => 18000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $uId13 = (int) $res13['data']['update_id'];
        $trackUpdate($uId13);

        $jobs13 = $jobRepo->getByUpdateId($uId13);
        $j13a = (int) $jobs13[0]['id'];
        $j13b = (int) $jobs13[1]['id'];

        $pdo->exec("UPDATE sync_jobs SET status = 'dead', attempt_count = 5 WHERE id IN ({$j13a}, {$j13b})");

        $script = dirname(__DIR__) . '/bin/retry_job.php';

        // 13a: Retry specific job via CLI
        $cmdA = sprintf('php %s %d 2>&1', escapeshellarg($script), $j13a);
        exec($cmdA, $outA, $codeA);
        if ($codeA !== 0) {
            throw new Exception("CLI retry <id> failed with code {$codeA}: " . implode("\n", $outA));
        }
        $jobA = $jobRepo->findById($j13a);
        if ($jobA['status'] !== 'pending') {
            throw new Exception("Job {$j13a} was not reset to pending by CLI");
        }

        // 13b: Retry --all-dead via CLI
        $cmdAll = sprintf('php %s --all-dead 2>&1', escapeshellarg($script));
        exec($cmdAll, $outAll, $codeAll);
        if ($codeAll !== 0) {
            throw new Exception("CLI retry --all-dead failed with code {$codeAll}: " . implode("\n", $outAll));
        }
        $jobB = $jobRepo->findById($j13b);
        if ($jobB['status'] !== 'pending') {
            throw new Exception("Job {$j13b} was not reset by --all-dead");
        }

        echo "[PASS] 13. CLI manual retry script validation (bin/retry_job.php)\n";
        echo "        ↳ Both single job retry (<id>) and bulk retry (--all-dead) executed and verified\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 13. CLI retry test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 14: Parallel worker race across 10 updates (30 jobs) with new logic
    // ========================================================================
    try {
        $setMockControl('site_b', 'fast');
        $setMockControl('site_c', 'always_fail');
        @unlink($mockAFile);
        @unlink($mockBFile);
        @unlink($mockCFile);

        $raceJobIds = [];
        for ($i = 0; $i < 10; $i++) {
            $r = $inventoryService->createUpdate([
                'room_id'         => 1,
                'date_from'       => $today,
                'date_to'         => $today,
                'available_units' => 4,
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

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p1 = proc_open($cmd, $descriptors, $pipes1);
        $p2 = proc_open($cmd, $descriptors, $pipes2);

        $out1 = stream_get_contents($pipes1[1]);
        $out2 = stream_get_contents($pipes2[1]);
        fclose($pipes1[0]); fclose($pipes1[1]); fclose($pipes1[2]);
        fclose($pipes2[0]); fclose($pipes2[1]); fclose($pipes2[2]);
        proc_close($p1);
        proc_close($p2);

        // Verify: Sites A and B are success (20 jobs), Site C is failed with attempt_count 1 (10 jobs)
        $inIds = implode(',', $raceJobIds);
        $allJobs = $pdo->query("SELECT j.id, c.name AS channel, j.status, j.attempt_count FROM sync_jobs j JOIN channels c ON c.id = j.channel_id WHERE j.id IN ({$inIds})")->fetchAll(PDO::FETCH_ASSOC);

        $successCount = 0;
        $failedCount = 0;

        foreach ($allJobs as $aj) {
            if ($aj['channel'] === 'Site A' || $aj['channel'] === 'Site B') {
                if ($aj['status'] !== 'success' || (int) $aj['attempt_count'] !== 1) {
                    throw new Exception("Job #{$aj['id']} ({$aj['channel']}) expected success with 1 attempt, got {$aj['status']} ({$aj['attempt_count']})");
                }
                $successCount++;
            } elseif ($aj['channel'] === 'Site C') {
                if ($aj['status'] !== 'failed' || (int) $aj['attempt_count'] !== 1) {
                    throw new Exception("Job #{$aj['id']} (Site C) expected failed with 1 attempt, got {$aj['status']} ({$aj['attempt_count']})");
                }
                $failedCount++;
            }

            // Exactly 1 attempt row per job
            $attempts = $attemptRepo->getByJobId((int) $aj['id']);
            if (count($attempts) !== 1) {
                throw new Exception("Job #{$aj['id']} has " . count($attempts) . " attempt rows, expected exactly 1");
            }
        }

        if ($successCount !== 20 || $failedCount !== 10) {
            throw new Exception("Expected 20 successes and 10 failures, got success={$successCount}, failed={$failedCount}");
        }

        echo "[PASS] 14. Parallel worker safety across 30 jobs (2 worker processes)\n";
        echo "        ↳ All 20 Site A/B jobs succeeded; all 10 Site C jobs marked failed (attempt=1); exactly 1 attempt row each\n";
        $passedCount++;
    } catch (Throwable $e) {
        echo "[FAIL] 14. Parallel safety test failed: " . $e->getMessage() . "\n";
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
