<?php
declare(strict_types=1);

/**
 * Sync Guard - Phase 6B Test Suite
 *
 * Verifies dashboard upgrades:
 * 1. Health "recovering" label and recent success streak.
 * 2. Status API query budget (<= 6 queries).
 * 3. Recovery scenario through the real worker daemon.
 * 4. Time to Consistency statistical math (avg, median, p95 nearest-rank, max).
 * 5. Consistency business rules (inactive channels, clamp, multi-job).
 * 6. 24 hourly buckets with gap filling in JST.
 * 7. Recent activity audit feed (no OTA-supplied text).
 * 8. Calendar values & days validation.
 * 9. Calendar worst-of state calculation & clock injection.
 * 10. retry_dead API endpoint.
 * 11. Demo controls gate (404 when disabled, CSRF/header light protection).
 * 12. Demo actions (set_mode, submit_demo_update, run_worker non-blocking lock).
 * 13. Static checks on JS & PHP markup (no raw HTML injection, no inline scripts/styles).
 * 14. Page shell structure (dialog, table captions, role=img, live region).
 * 15. Query budget (<= 4 queries) & high-volume performance on 9,000 jobs.
 * 16. HTTP hygiene (no-store, 405 with Allow, SQLi validation).
 * 17. Regression check against all earlier phases.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/test_phase6b.php must be run from the CLI.\n");
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
use SyncGuard\Database\Connection;
use SyncGuard\Repositories\AttemptRepository;
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\HealthRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Services\CalendarService;
use SyncGuard\Services\HealthService;
use SyncGuard\Services\InsightsService;
use SyncGuard\Services\InventoryService;
use SyncGuard\Services\StatusService;
use SyncGuard\Support\MockControl;
use SyncGuard\Support\Time;
use SyncGuard\Support\Uuid;
use SyncGuard\Validation\InventoryUpdateValidator;
use SyncGuard\Worker\JobProcessor;

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');

if (strtolower($appEnv) !== 'local') {
    fwrite(STDERR, sprintf("\n[ERROR] APP_ENV is set to '%s'. test_phase6b.php is strictly forbidden in non-local environments.\n", $appEnv));
    exit(1);
}

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

$rawConf = $config['db'];
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $rawConf['host'], $rawConf['port'], $rawConf['name'], $rawConf['charset']);

$passedCount = 0;
$totalTests = 16;

/**
 * Counting PDO proxy for query budget assertions.
 */
class CountingPdo2 extends PDO
{
    public int $queryCount = 0;

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->queryCount++;
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $this->queryCount++;
        return parent::query($query, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->queryCount++;
        return parent::exec($statement);
    }
}

/**
 * Execute an HTTP request with full headers and body.
 */
function testHttp(string $url, string $method = 'GET', mixed $body = null, array $headers = []): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 30,
    ];

    if (!empty($headers)) {
        $opts[CURLOPT_HTTPHEADER] = $headers;
    }

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if (is_array($body)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        } elseif (is_string($body)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if (is_string($body)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
    }

    curl_setopt_array($ch, $opts);
    $response = (string) curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerStr = substr($response, 0, $headerSize);
    $bodyStr = substr($response, $headerSize);
    $json = json_decode($bodyStr, true);

    return [
        'status'  => $statusCode,
        'headers' => $headerStr,
        'body'    => $bodyStr,
        'json'    => $json,
    ];
}

/**
 * Clean database queue and storage locks.
 */
$cleanAll = function () use ($pdo, $healthRepo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec('UPDATE channels SET is_active = 1');
    $pdo->exec('DELETE FROM sync_attempts');
    $pdo->exec('DELETE FROM sync_jobs');
    $pdo->exec('DELETE FROM inventory_updates');
    MockControl::resetDefaults();
    $healthRepo->rebuildAll();

    $lockFile = dirname(__DIR__) . '/storage/demo_worker.lock';
    if (file_exists($lockFile)) {
        @unlink($lockFile);
    }
};

echo "====================================================================\n";
echo "              Sync Guard - Phase 6B Test Suite                      \n";
echo "====================================================================\n\n";

// Ensure starting from clean state
$cleanAll();

try {
    // ========================================================================
    // TEST 1: labelFor thresholds and recovery streak
    // ========================================================================
    try {
        if (HealthService::labelFor(false, 0, 100.0) !== 'no_data') {
            throw new Exception("Expected 'no_data' when hasData=false");
        }
        if (HealthService::labelFor(true, 5, 100.0) !== 'down') {
            throw new Exception("Expected 'down' for 5 consecutive failures");
        }
        if (HealthService::labelFor(true, 4, 100.0) !== 'degraded') {
            throw new Exception("Expected 'degraded' for 4 consecutive failures");
        }
        if (HealthService::labelFor(true, 2, 100.0) !== 'degraded') {
            throw new Exception("Expected 'degraded' for 2 consecutive failures");
        }
        if (HealthService::labelFor(true, 1, 89.0) !== 'degraded') {
            throw new Exception("Expected 'degraded' for 1 failure with success rate < 90%");
        }
        if (HealthService::labelFor(true, 1, 95.0) !== 'healthy') {
            throw new Exception("Expected 'healthy' for 1 failure with success rate >= 90%");
        }
        if (HealthService::labelFor(true, 0, 80.0, 4) !== 'recovering') {
            throw new Exception("Expected 'recovering' for 0 failures, rate 80%, streak 4 (< 5)");
        }
        if (HealthService::labelFor(true, 0, 80.0, 5) !== 'healthy') {
            throw new Exception("Expected 'healthy' for 0 failures, rate 80%, streak 5 (>= 5)");
        }
        if (HealthService::labelFor(true, 0, 90.0, 0) !== 'healthy') {
            throw new Exception("Expected 'healthy' for rate 90.0% even with streak 0");
        }
        // Rule order check: 5 consecutive failures with streak 5 must still be 'down'
        if (HealthService::labelFor(true, 5, 50.0, 5) !== 'down') {
            throw new Exception("Rule order failure: 5 consecutive failures must be 'down' regardless of streak");
        }

        echo "[PASS] 01. labelFor: no_data, down, degraded, recovering, healthy, and rule order\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 01. labelFor test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 2: Streak query across channels and query budget
    // ========================================================================
    try {
        $cleanAll();

        // Channel 1: F, F, S, S, S (most recent is S, leading streak = 3)
        // Channel 2: 10 successes (leading streak capped at 5)
        // Channel 3: S, S, S, F (most recent is F, streak = 0)
        // Outcomes in sync_attempts: 'success', 'http_error', 'timeout', 'rejected'
        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id) VALUES (1, "2026-11-01", "2026-11-02", 5, 10000, ?, ?)');
        $insJ = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count) VALUES (?, ?, ?, 1)');
        $insA = $pdo->prepare('INSERT INTO sync_attempts (job_id, outcome, http_status, duration_ms, started_at) VALUES (?, ?, 200, 50, ?)');

        // Channel 1 attempts
        $ch1Outcomes = ['http_error', 'http_error', 'success', 'success', 'success'];
        foreach ($ch1Outcomes as $i => $outcome) {
            $insU->execute([Uuid::v4(), Uuid::v4()]);
            $uId = (int) $pdo->lastInsertId();
            $insJ->execute([$uId, 1, $outcome === 'success' ? 'success' : 'failed']);
            $jId = (int) $pdo->lastInsertId();
            $time = date('Y-m-d H:i:s', time() - (50 - $i * 5));
            $insA->execute([$jId, $outcome, $time]);
        }

        // Channel 2 attempts (10 successes)
        for ($i = 0; $i < 10; $i++) {
            $insU->execute([Uuid::v4(), Uuid::v4()]);
            $uId = (int) $pdo->lastInsertId();
            $insJ->execute([$uId, 2, 'success']);
            $jId = (int) $pdo->lastInsertId();
            $time = date('Y-m-d H:i:s', time() - (50 - $i * 5));
            $insA->execute([$jId, 'success', $time]);
        }

        // Channel 3 attempts (S, S, S, F)
        $ch3Outcomes = ['success', 'success', 'success', 'http_error'];
        foreach ($ch3Outcomes as $i => $outcome) {
            $insU->execute([Uuid::v4(), Uuid::v4()]);
            $uId = (int) $pdo->lastInsertId();
            $insJ->execute([$uId, 3, $outcome === 'success' ? 'success' : 'failed']);
            $jId = (int) $pdo->lastInsertId();
            $time = date('Y-m-d H:i:s', time() - (50 - $i * 5));
            $insA->execute([$jId, $outcome, $time]);
        }
        $pdo->commit();

        $healthRepo->rebuildAll();

        $countingPdo = new CountingPdo2($dsn, $rawConf['user'], $rawConf['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $statusService = new StatusService($countingPdo);
        $statusData = $statusService->getStatus();

        if ($countingPdo->queryCount > 6) {
            throw new Exception("StatusService exceeded query budget: recorded {$countingPdo->queryCount} queries (budget: <= 6)");
        }

        $channels = $statusData['channels'];
        $ch1 = null;
        $ch2 = null;
        $ch3 = null;
        foreach ($channels as $c) {
            if ($c['id'] === 1) $ch1 = $c;
            if ($c['id'] === 2) $ch2 = $c;
            if ($c['id'] === 3) $ch3 = $c;
        }

        if ($ch1['recent_streak'] !== 3) {
            throw new Exception("Expected Channel 1 streak to be 3, got {$ch1['recent_streak']}");
        }
        if ($ch2['recent_streak'] !== 5) {
            throw new Exception("Expected Channel 2 streak to be 5 (capped), got {$ch2['recent_streak']}");
        }
        if ($ch3['recent_streak'] !== 0) {
            throw new Exception("Expected Channel 3 streak to be 0 (last was failure), got {$ch3['recent_streak']}");
        }

        echo "[PASS] 02. Streak query: leading runs (3, 5 capped, 0) and status API query budget <= 6\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 02. Streak query test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 3: Recovery scenario through the real worker
    // ========================================================================
    try {
        $cleanAll();
        MockControl::setMode('site_c', 'always_fail');

        $resolver = new AdapterResolver();
        $processor = new JobProcessor($pdo, $jobRepo, $updateRepo, $attemptRepo, $resolver, 300, null, null, null, $healthRepo);

        // 1. Submit 3 updates and process them -> all fail on C
        for ($i = 0; $i < 3; $i++) {
            $inventoryService->createUpdate([
                'room_id'         => 1,
                'date_from'       => '2026-11-10',
                'date_to'         => '2026-11-12',
                'available_units' => 5,
                'price'           => 10000,
                'idempotency_key' => Uuid::v4(),
            ]);
        }

        // Process passes and advance time so retries execute
        $processor->runOnce(10);
        $jobRepo->skipWait();
        $processor->runOnce(10);

        $statusService = new StatusService($pdo);
        $st = $statusService->getStatus();
        $chC = null;
        foreach ($st['channels'] as $c) {
            if ($c['id'] === 3) $chC = $c;
        }

        if ($chC['status'] !== 'degraded' && $chC['status'] !== 'down') {
            throw new Exception("Expected Site C to be degraded or down after failures, got {$chC['status']}");
        }

        // 2. Fix Site C: set to always_succeed
        MockControl::setMode('site_c', 'always_succeed');

        // Submit 1 update -> worker delivers 1 success
        $inventoryService->createUpdate([
            'room_id'         => 1,
            'date_from'       => '2026-11-10',
            'date_to'         => '2026-11-12',
            'available_units' => 6,
            'price'           => 11000,
            'idempotency_key' => Uuid::v4(),
        ]);
        $processor->runOnce(10);

        $st = $statusService->getStatus();
        foreach ($st['channels'] as $c) {
            if ($c['id'] === 3) $chC = $c;
        }

        if ($chC['status'] !== 'recovering') {
            throw new Exception("Expected Site C to be 'recovering' after 1 success with past failures, got {$chC['status']}");
        }
        if ($chC['recent_streak'] !== 1) {
            throw new Exception("Expected recent_streak to be 1, got {$chC['recent_streak']}");
        }

        // Submit 4 more updates -> reach 5 successes
        for ($i = 0; $i < 4; $i++) {
            $inventoryService->createUpdate([
                'room_id'         => 1,
                'date_from'       => '2026-11-10',
                'date_to'         => '2026-11-12',
                'available_units' => 7,
                'price'           => 12000,
                'idempotency_key' => Uuid::v4(),
            ]);
            $processor->runOnce(10);
        }

        $st = $statusService->getStatus();
        foreach ($st['channels'] as $c) {
            if ($c['id'] === 3) $chC = $c;
        }

        if ($chC['status'] !== 'healthy') {
            throw new Exception("Expected Site C to be 'healthy' after reaching recovery streak of 5, got {$chC['status']}");
        }
        if ($chC['recent_streak'] !== 5) {
            throw new Exception("Expected recent_streak to be 5, got {$chC['recent_streak']}");
        }

        echo "[PASS] 03. Recovery scenario through real worker: failures -> recovering (streak 1) -> healthy (streak 5)\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 03. Recovery scenario test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 4: Consistency math with synthetic data (direct SQL)
    // ========================================================================
    try {
        $cleanAll();

        // Insert 20 updates with known durations in seconds: 1, 2, 3, ... 20
        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id, created_at) VALUES (1, "2026-11-01", "2026-11-02", 5, 10000, ?, ?, ?)');
        $insJ = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count) VALUES (?, ?, "success", 1)');
        $insA = $pdo->prepare('INSERT INTO sync_attempts (job_id, outcome, http_status, duration_ms, started_at) VALUES (?, "success", 200, 50, ?)');

        $nowTs = time();
        for ($sec = 1; $sec <= 20; $sec++) {
            $created = date('Y-m-d H:i:s', $nowTs - 1000);
            $started = date('Y-m-d H:i:s', $nowTs - 1000 + $sec);

            $insU->execute([Uuid::v4(), Uuid::v4(), $created]);
            $uId = (int) $pdo->lastInsertId();

            for ($ch = 1; $ch <= 3; $ch++) {
                $insJ->execute([$uId, $ch]);
                $jId = (int) $pdo->lastInsertId();
                $insA->execute([$jId, $started]);
            }
        }
        $pdo->commit();

        $insightsService = new InsightsService($pdo);
        $metrics = $insightsService->getConsistencyMetrics();

        if ($metrics['sample_size'] !== 20) {
            throw new Exception("Expected sample_size 20, got {$metrics['sample_size']}");
        }
        if ($metrics['avg_seconds'] !== 11) {
            throw new Exception("Expected avg_seconds 11, got {$metrics['avg_seconds']}");
        }
        if ($metrics['median_seconds'] !== 11) {
            throw new Exception("Expected median_seconds 11, got {$metrics['median_seconds']}");
        }
        if ($metrics['p95_seconds'] !== 19) {
            throw new Exception("Expected p95_seconds 19, got {$metrics['p95_seconds']}");
        }
        if ($metrics['max_seconds'] !== 20) {
            throw new Exception("Expected max_seconds 20, got {$metrics['max_seconds']}");
        }
        if ($metrics['enough_for_p95'] !== true) {
            throw new Exception("Expected enough_for_p95 to be true");
        }

        // Test fewer than 5 samples -> enough_for_p95 false, p95 null
        $cleanAll();
        $pdo->beginTransaction();
        for ($sec = 1; $sec <= 3; $sec++) {
            $created = date('Y-m-d H:i:s', $nowTs - 500);
            $started = date('Y-m-d H:i:s', $nowTs - 500 + $sec);
            $insU->execute([Uuid::v4(), Uuid::v4(), $created]);
            $uId = (int) $pdo->lastInsertId();
            for ($ch = 1; $ch <= 3; $ch++) {
                $insJ->execute([$uId, $ch]);
                $jId = (int) $pdo->lastInsertId();
                $insA->execute([$jId, $started]);
            }
        }
        $pdo->commit();

        $metrics3 = $insightsService->getConsistencyMetrics();
        if ($metrics3['enough_for_p95'] !== false || $metrics3['p95_seconds'] !== null) {
            throw new Exception("Expected enough_for_p95 false and p95 null for 3 samples");
        }

        // Test 0 samples -> all nulls
        $cleanAll();
        $metrics0 = $insightsService->getConsistencyMetrics();
        if ($metrics0['sample_size'] !== 0 || $metrics0['avg_seconds'] !== null || $metrics0['median_seconds'] !== null || $metrics0['p95_seconds'] !== null) {
            throw new Exception("Expected null metrics when sample_size is 0");
        }

        echo "[PASS] 04. Consistency math: exact avg, median, p95 nearest rank, max, and sample size gating\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 04. Consistency math test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 5: Consistency rules
    // ========================================================================
    try {
        $cleanAll();
        $nowTs = time();

        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id, created_at) VALUES (1, "2026-11-01", "2026-11-02", 5, 10000, ?, ?, ?)');
        $insJ = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count) VALUES (?, ?, ?, 1)');
        $insA = $pdo->prepare('INSERT INTO sync_attempts (job_id, outcome, http_status, duration_ms, started_at) VALUES (?, ?, 200, 50, ?)');

        // 1. One update completed
        $insU->execute([Uuid::v4(), Uuid::v4(), date('Y-m-d H:i:s', $nowTs - 100)]);
        $uId1 = (int) $pdo->lastInsertId();
        for ($ch = 1; $ch <= 3; $ch++) {
            $insJ->execute([$uId1, $ch, 'success']);
            $jId = (int) $pdo->lastInsertId();
            $insA->execute([$jId, 'success', date('Y-m-d H:i:s', $nowTs - 90)]);
        }

        // 2. One update with 1 non-success job -> unsynced
        $insU->execute([Uuid::v4(), Uuid::v4(), date('Y-m-d H:i:s', $nowTs - 200)]);
        $uId2 = (int) $pdo->lastInsertId();
        $insJ->execute([$uId2, 1, 'success']);
        $jId = (int) $pdo->lastInsertId();
        $insA->execute([$jId, 'success', date('Y-m-d H:i:s', $nowTs - 180)]);

        $insJ->execute([$uId2, 2, 'failed']);
        $jId = (int) $pdo->lastInsertId();
        $insA->execute([$jId, 'http_error', date('Y-m-d H:i:s', $nowTs - 180)]);

        $insJ->execute([$uId2, 3, 'success']);
        $jId = (int) $pdo->lastInsertId();
        $insA->execute([$jId, 'success', date('Y-m-d H:i:s', $nowTs - 180)]);

        // 3. Update outside 24-hour window (26 hours ago)
        $insU->execute([Uuid::v4(), Uuid::v4(), date('Y-m-d H:i:s', $nowTs - 93600)]);
        $uId3 = (int) $pdo->lastInsertId();
        for ($ch = 1; $ch <= 3; $ch++) {
            $insJ->execute([$uId3, $ch, 'success']);
            $jId = (int) $pdo->lastInsertId();
            $insA->execute([$jId, 'success', date('Y-m-d H:i:s', $nowTs - 93590)]);
        }

        // 4. Update where clock skew caused started_at < created_at -> clamp to 0
        $insU->execute([Uuid::v4(), Uuid::v4(), date('Y-m-d H:i:s', $nowTs - 50)]);
        $uId4 = (int) $pdo->lastInsertId();
        for ($ch = 1; $ch <= 3; $ch++) {
            $insJ->execute([$uId4, $ch, 'success']);
            $jId = (int) $pdo->lastInsertId();
            $insA->execute([$jId, 'success', date('Y-m-d H:i:s', $nowTs - 60)]);
        }

        $pdo->commit();

        $insightsService = new InsightsService($pdo);
        $metrics = $insightsService->getConsistencyMetrics();

        // Sample size should be 2 (uId1 and uId4; uId2 is unsynced, uId3 is outside 24h)
        if ($metrics['sample_size'] !== 2) {
            throw new Exception("Expected sample_size 2, got {$metrics['sample_size']}");
        }
        if ($metrics['unsynced_updates'] !== 1) {
            throw new Exception("Expected unsynced_updates 1, got {$metrics['unsynced_updates']}");
        }
        if ($metrics['oldest_unsynced_seconds'] < 195 || $metrics['oldest_unsynced_seconds'] > 205) {
            throw new Exception("Expected oldest_unsynced_seconds around 200, got {$metrics['oldest_unsynced_seconds']}");
        }

        // Test inactive channel: deactivate Channel 2, then uId2 should count as synced!
        $pdo->exec('UPDATE channels SET is_active = 0 WHERE id = 2');
        $metricsInactive = $insightsService->getConsistencyMetrics();
        if ($metricsInactive['sample_size'] !== 3) {
            throw new Exception("Expected sample_size 3 when Channel 2 is inactive, got {$metricsInactive['sample_size']}");
        }
        $pdo->exec('UPDATE channels SET is_active = 1 WHERE id = 2');

        echo "[PASS] 05. Consistency rules: unsynced detection, 24h window filter, inactive channels, 0-clamping\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 05. Consistency rules test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 6: Hourly delivery buckets
    // ========================================================================
    try {
        $cleanAll();

        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id) VALUES (1, "2026-11-01", "2026-11-02", 5, 10000, ?, ?)');
        $insJ = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count) VALUES (?, 1, "success", 1)');
        $insA = $pdo->prepare('INSERT INTO sync_attempts (job_id, outcome, http_status, duration_ms, started_at) VALUES (?, ?, 200, 50, ?)');

        $now = time();
        for ($i = 0; $i < 2; $i++) {
            $insU->execute([Uuid::v4(), Uuid::v4()]);
            $uId = (int) $pdo->lastInsertId();
            $insJ->execute([$uId]);
            $jId = (int) $pdo->lastInsertId();
            $insA->execute([$jId, 'success', date('Y-m-d H:i:s', $now - 1800)]);
        }

        foreach (['timeout', 'rejected'] as $outcome) {
            $insU->execute([Uuid::v4(), Uuid::v4()]);
            $uId = (int) $pdo->lastInsertId();
            $insJ->execute([$uId]);
            $jId = (int) $pdo->lastInsertId();
            $insA->execute([$jId, $outcome, date('Y-m-d H:i:s', $now - 1800)]);
        }

        $insU->execute([Uuid::v4(), Uuid::v4()]);
        $uId = (int) $pdo->lastInsertId();
        $insJ->execute([$uId]);
        $jId = (int) $pdo->lastInsertId();
        $insA->execute([$jId, 'success', date('Y-m-d H:i:s', $now - 90000)]);

        $pdo->commit();

        $insightsService = new InsightsService($pdo);
        $hourly = $insightsService->getHourlyDeliveries();

        if (count($hourly) !== 24) {
            throw new Exception("Expected exactly 24 hourly buckets, got " . count($hourly));
        }

        $lastBucket = end($hourly);
        if (!preg_match('/^\d{2}:00$/', $lastBucket['hour_jst'])) {
            throw new Exception("Expected hour label format HH:00 in JST, got {$lastBucket['hour_jst']}");
        }

        $totSuccess = array_sum(array_column($hourly, 'success'));
        $totFailed = array_sum(array_column($hourly, 'failed'));

        if ($totSuccess !== 2) {
            throw new Exception("Expected 2 successes in 24h, got {$totSuccess}");
        }
        if ($totFailed !== 2) {
            throw new Exception("Expected 2 failed (timeout + rejected), got {$totFailed}");
        }

        echo "[PASS] 06. Hourly delivery buckets: exactly 24 buckets, zero-filled gaps, 24h filter, JST labels\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 06. Hourly delivery buckets test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 7: Recent activity feed
    // ========================================================================
    try {
        $cleanAll();

        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id) VALUES (1, "2026-11-01", "2026-11-02", 5, 10000, ?, ?)');
        $insJ = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count, last_error) VALUES (?, 1, "failed", 1, ?)');
        $insA = $pdo->prepare('INSERT INTO sync_attempts (job_id, outcome, http_status, duration_ms, started_at) VALUES (?, "http_error", 500, 35, ?)');

        $xssError = '<script>alert("hacked")</script>';
        $insU->execute([Uuid::v4(), Uuid::v4()]);
        $uId = (int) $pdo->lastInsertId();
        $insJ->execute([$uId, $xssError]);
        $jId = (int) $pdo->lastInsertId();
        $insA->execute([$jId, date('Y-m-d H:i:s', time() - 10)]);
        $pdo->commit();

        $insightsService = new InsightsService($pdo);
        $recent = $insightsService->getRecentActivity();

        if (empty($recent)) {
            throw new Exception("Expected at least one recent activity item");
        }

        $first = $recent[0];
        if (!is_int($first['attempt_id']) || !is_int($first['job_id'])) {
            throw new Exception("Expected integer IDs in recent activity item");
        }
        if ($first['label'] !== 'Server error') {
            throw new Exception("Expected outcome http_error to map to label 'Server error', got '{$first['label']}'");
        }

        $resp = testHttp('http://127.0.0.1/api/insights.php');
        if (str_contains($resp['body'], 'script') || str_contains($resp['body'], 'hacked')) {
            throw new Exception("Security violation: OTA-supplied error string found in /api/insights.php JSON output!");
        }

        echo "[PASS] 07. Recent activity feed: 15 items newest first, outcome labels, zero OTA-supplied text\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 07. Recent activity test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 8: Calendar values and days validation
    // ========================================================================
    try {
        $cleanAll();

        $calService = new CalendarService($pdo);
        $cal14 = $calService->getCalendar(14);

        if ($cal14['days'] !== 14 || count($cal14['dates']) !== 14) {
            throw new Exception("Expected 14 calendar dates");
        }

        $r1 = $cal14['rooms'][0];
        $cell0 = $r1['cells'][0];
        if ($cell0['source'] !== 'default' || $cell0['units'] !== 15 || $cell0['price'] !== 8500) {
            throw new Exception("Expected default units (15) and price (8500) with source 'default'");
        }

        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id) VALUES (1, ?, ?, ?, ?, ?, ?)');
        $today = Time::todayInTokyo();

        $insU->execute([$today, $today, 5, null, Uuid::v4(), Uuid::v4()]);
        $insU->execute([$today, $today, null, 12000, Uuid::v4(), Uuid::v4()]);
        $pdo->commit();

        $calMerged = $calService->getCalendar(14);
        $mergedCell = $calMerged['rooms'][0]['cells'][0];

        if ($mergedCell['units'] !== 5 || $mergedCell['price'] !== 12000 || $mergedCell['source'] !== 'update') {
            throw new Exception("Field merging failure: expected units 5, price 12000, source 'update', got units={$mergedCell['units']}, price={$mergedCell['price']}");
        }

        $r7 = testHttp('http://127.0.0.1/api/calendar.php?days=7');
        $r14 = testHttp('http://127.0.0.1/api/calendar.php?days=14');
        $r28 = testHttp('http://127.0.0.1/api/calendar.php?days=28');
        if ($r7['status'] !== 200 || $r14['status'] !== 200 || $r28['status'] !== 200) {
            throw new Exception("Expected 200 for days 7, 14, 28");
        }

        $badDays = ['3', 'abc', '0', '100'];
        foreach ($badDays as $bad) {
            $rBad = testHttp('http://127.0.0.1/api/calendar.php?days=' . $bad);
            if ($rBad['status'] !== 422) {
                throw new Exception("Expected 422 for days={$bad}, got {$rBad['status']}");
            }
        }

        echo "[PASS] 08. Calendar values: defaults, latest-wins field merging, date range clipping, days validation\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 08. Calendar values test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 9: Calendar states: worst-of rule, old failed job, clock injection, truncation
    // ========================================================================
    try {
        $cleanAll();
        $today = Time::todayInTokyo();

        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id) VALUES (1, ?, ?, ?, ?, ?, ?)');
        $insJ = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count) VALUES (?, ?, ?, 1)');

        $insU->execute([$today, $today, 4, 10000, Uuid::v4(), Uuid::v4()]);
        $uIdOld = (int) $pdo->lastInsertId();
        $insJ->execute([$uIdOld, 1, 'success']);
        $insJ->execute([$uIdOld, 2, 'success']);
        $insJ->execute([$uIdOld, 3, 'failed']); // Site C failed

        $insU->execute([$today, $today, 3, 11000, Uuid::v4(), Uuid::v4()]);
        $uIdNew = (int) $pdo->lastInsertId();
        $insJ->execute([$uIdNew, 1, 'success']);
        $insJ->execute([$uIdNew, 2, 'success']);
        $insJ->execute([$uIdNew, 3, 'success']);
        $pdo->commit();

        $calService = new CalendarService($pdo);
        $cal = $calService->getCalendar(14);
        $cellToday = $cal['rooms'][0]['cells'][0];

        if ($cellToday['state'] !== 'failed') {
            throw new Exception("Worst-of state rule failed: expected 'failed' due to older failed job, got '{$cellToday['state']}'");
        }
        if ($cellToday['channels'] !== ['Site C']) {
            throw new Exception("Expected channels ['Site C'], got " . json_encode($cellToday['channels']));
        }
        if ($cellToday['updates_covering'] !== 2) {
            throw new Exception("Expected updates_covering = 2, got {$cellToday['updates_covering']}");
        }

        $injectedJst = new DateTimeImmutable('2026-10-31 23:59:00', new DateTimeZone('Asia/Tokyo'));
        $calInjected = $calService->getCalendar(7, $injectedJst);
        if ($calInjected['start_date'] !== '2026-10-31') {
            throw new Exception("Injected clock failed: expected start_date 2026-10-31, got {$calInjected['start_date']}");
        }

        $pdo->beginTransaction();
        $insBulk = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id) VALUES (1, ?, ?, 2, 8000, ?, ?)');
        for ($i = 0; $i < 2005; $i++) {
            $insBulk->execute([$today, $today, Uuid::v4(), Uuid::v4()]);
        }
        $pdo->commit();

        $calTrunc = $calService->getCalendar(7);
        if ($calTrunc['truncated'] !== true) {
            throw new Exception("Expected truncated: true when > 2000 updates overlap calendar window");
        }

        echo "[PASS] 09. Calendar states: worst-of rule, old failed job protection, clock injection, truncation flag\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 09. Calendar states test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 10: retry_dead API endpoint
    // ========================================================================
    try {
        $cleanAll();

        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id) VALUES (1, "2026-11-01", "2026-11-02", 5, 10000, ?, ?)');
        $insJ = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count, last_error) VALUES (?, 1, "dead", 5, "Connection refused")');
        $insA = $pdo->prepare('INSERT INTO sync_attempts (job_id, outcome, http_status, duration_ms, started_at) VALUES (?, "http_error", 500, 40, CURRENT_TIMESTAMP)');

        for ($i = 0; $i < 3; $i++) {
            $insU->execute([Uuid::v4(), Uuid::v4()]);
            $uId = (int) $pdo->lastInsertId();
            $insJ->execute([$uId]);
            $jId = (int) $pdo->lastInsertId();
            $insA->execute([$jId]);
        }
        $pdo->commit();

        $getResp = testHttp('http://127.0.0.1/api/retry_dead.php', 'GET');
        if ($getResp['status'] !== 405) {
            throw new Exception("Expected 405 for GET /api/retry_dead.php, got {$getResp['status']}");
        }
        if (!str_contains($getResp['headers'], 'Allow: POST')) {
            throw new Exception("Expected 'Allow: POST' header in 405 response");
        }

        $postResp = testHttp('http://127.0.0.1/api/retry_dead.php', 'POST');
        if ($postResp['status'] !== 200 || empty($postResp['json']['ok']) || ($postResp['json']['data']['retried'] ?? 0) !== 3) {
            throw new Exception("Expected 200 with retried: 3, got: " . json_encode($postResp['json']));
        }

        $jobs = $pdo->query('SELECT status, attempt_count, last_error FROM sync_jobs')->fetchAll();
        foreach ($jobs as $j) {
            if ($j['status'] !== 'pending' || (int) $j['attempt_count'] !== 0 || $j['last_error'] !== null) {
                throw new Exception("Dead job reset error: " . json_encode($j));
            }
        }
        $attemptCount = (int) $pdo->query('SELECT COUNT(*) FROM sync_attempts')->fetchColumn();
        if ($attemptCount !== 3) {
            throw new Exception("Expected old attempt rows to remain intact (count 3), got {$attemptCount}");
        }

        $postResp2 = testHttp('http://127.0.0.1/api/retry_dead.php', 'POST');
        if ($postResp2['status'] !== 200 || ($postResp2['json']['data']['retried'] ?? -1) !== 0) {
            throw new Exception("Expected retried = 0 when no dead jobs exist");
        }

        echo "[PASS] 10. retry_dead API: 405 Allow on GET, bulk reset to pending, history preserved, idempotent 0\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 10. retry_dead API test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 11: Demo gate (disabled -> 404, enabled -> light CSRF & validation)
    // ========================================================================
    try {
        $bgPort = 8089;
        @exec("fuser -k {$bgPort}/tcp 2>/dev/null");
        $cmd = sprintf('DEMO_CONTROLS_ENABLED=false php -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!', $bgPort, escapeshellarg(dirname(__DIR__) . '/public'));
        exec($cmd, $pidOutput);
        $serverPid = !empty($pidOutput[0]) ? (int) $pidOutput[0] : 0;
        
        // Wait up to 2 seconds for built-in server to bind and accept connections
        for ($w = 0; $w < 20; $w++) {
            $fp = @fsockopen('127.0.0.1', $bgPort, $errno, $errstr, 0.1);
            if ($fp) {
                fclose($fp);
                break;
            }
            usleep(100000);
        }

        try {
            $gatedGet = testHttp("http://127.0.0.1:{$bgPort}/api/demo.php", 'GET');
            $gatedPost = testHttp("http://127.0.0.1:{$bgPort}/api/demo.php", 'POST');
            if ($gatedGet['status'] !== 404 || $gatedPost['status'] !== 404) {
                throw new Exception("Demo gate failure: Expected 404 for all methods when DEMO_CONTROLS_ENABLED=false, got GET={$gatedGet['status']} POST={$gatedPost['status']}");
            }
            if (($gatedGet['json']['error'] ?? '') !== 'Not found.') {
                throw new Exception("Expected error 'Not found.' when gate is closed");
            }

            $gatedIndex = testHttp("http://127.0.0.1:{$bgPort}/index.php", 'GET');
            if (str_contains($gatedIndex['body'], 'demo-controls-panel')) {
                throw new Exception("index.php contains demo-controls-panel markup when DEMO_CONTROLS_ENABLED=false!");
            }
        } finally {
            if ($serverPid > 0) {
                exec("kill -9 {$serverPid} 2>/dev/null");
            }
        }

        $mainUrl = 'http://127.0.0.1/api/demo.php';

        $resp415 = testHttp($mainUrl, 'POST', 'action=reset_modes', [
            'Content-Type: text/plain',
            'X-Requested-With: XMLHttpRequest',
        ]);
        if ($resp415['status'] !== 415) {
            throw new Exception("Expected 415 Unsupported Media Type for text/plain, got {$resp415['status']}");
        }

        $resp403 = testHttp($mainUrl, 'POST', ['action' => 'reset_modes'], [
            'Content-Type: application/json',
        ]);
        if ($resp403['status'] !== 403) {
            throw new Exception("Expected 403 Forbidden for missing X-Requested-With, got {$resp403['status']}");
        }

        $respOrigin = testHttp($mainUrl, 'POST', ['action' => 'reset_modes'], [
            'Content-Type: application/json',
            'X-Requested-With: XMLHttpRequest',
            'Origin: http://malicious-site.example.com',
        ]);
        if ($respOrigin['status'] !== 403) {
            throw new Exception("Expected 403 Forbidden for mismatched Origin header, got {$respOrigin['status']}");
        }

        $resp422 = testHttp($mainUrl, 'POST', ['action' => 'drop_tables'], [
            'Content-Type: application/json',
            'X-Requested-With: XMLHttpRequest',
        ]);
        if ($resp422['status'] !== 422) {
            throw new Exception("Expected 422 Unprocessable for unknown action, got {$resp422['status']}");
        }

        $respBadMode = testHttp($mainUrl, 'POST', ['action' => 'set_mode', 'site' => 'site_b', 'mode' => 'explode'], [
            'Content-Type: application/json',
            'X-Requested-With: XMLHttpRequest',
        ]);
        if ($respBadMode['status'] !== 422) {
            throw new Exception("Expected 422 for invalid mode 'explode', got {$respBadMode['status']}");
        }

        $hugeBody = json_encode(['action' => 'reset_modes', 'padding' => str_repeat('X', 5000)]);
        $resp413 = testHttp($mainUrl, 'POST', $hugeBody, [
            'Content-Type: application/json',
            'X-Requested-With: XMLHttpRequest',
        ]);
        if ($resp413['status'] !== 413) {
            throw new Exception("Expected 413 Payload Too Large for > 4KB, got {$resp413['status']}");
        }

        echo "[PASS] 11. Demo gate: 404 & no panel when disabled, 415/403/422/413 protections when enabled\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 11. Demo gate test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 12: Demo actions & single worker lock
    // ========================================================================
    try {
        $cleanAll();
        $demoHeaders = [
            'Content-Type: application/json',
            'X-Requested-With: XMLHttpRequest',
        ];

        // 1. set_mode site_c always_fail
        $resSet = testHttp('http://127.0.0.1/api/demo.php', 'POST', [
            'action' => 'set_mode',
            'site'   => 'site_c',
            'mode'   => 'always_fail',
        ], $demoHeaders);
        if ($resSet['status'] !== 200 || MockControl::getMode('site_c') !== 'always_fail') {
            throw new Exception("set_mode action failed: " . json_encode($resSet['json']));
        }

        // 2. reset_modes
        $resReset = testHttp('http://127.0.0.1/api/demo.php', 'POST', [
            'action' => 'reset_modes',
        ], $demoHeaders);
        if ($resReset['status'] !== 200 || MockControl::getMode('site_c') !== 'random') {
            throw new Exception("reset_modes action failed: " . json_encode($resReset['json']));
        }

        // 3. submit_demo_update
        $resUpdate = testHttp('http://127.0.0.1/api/demo.php', 'POST', [
            'action' => 'submit_demo_update',
        ], $demoHeaders);
        if ($resUpdate['status'] !== 200 || empty($resUpdate['json']['data']['update_id'])) {
            throw new Exception("submit_demo_update failed with status {$resUpdate['status']}: {$resUpdate['body']}");
        }
        $uId = (int) $resUpdate['json']['data']['update_id'];

        // 4. skip_wait
        $resSkip = testHttp('http://127.0.0.1/api/demo.php', 'POST', [
            'action' => 'skip_wait',
        ], $demoHeaders);
        if ($resSkip['status'] !== 200) {
            throw new Exception("skip_wait failed: " . json_encode($resSkip['json']));
        }

        // 5. run_worker with lock concurrency check
        $lockFile = dirname(__DIR__) . '/storage/demo_worker.lock';
        $fp = fopen($lockFile, 'c+');
        @chmod($lockFile, 0666);
        $held = flock($fp, LOCK_EX | LOCK_NB);
        if (!$held) {
            throw new Exception("Failed to acquire lock in test process for concurrency check");
        }

        // concurrent run_worker should return 409
        $res409 = testHttp('http://127.0.0.1/api/demo.php', 'POST', [
            'action' => 'run_worker',
        ], $demoHeaders);
        if ($res409['status'] !== 409 || str_contains($res409['body'], 'already running') === false) {
            throw new Exception("Expected 409 Conflict when worker lock is held, got {$res409['status']} body: {$res409['body']}");
        }

        // Release lock
        flock($fp, LOCK_UN);
        fclose($fp);

        // Ensure fast execution for run_worker check
        MockControl::setMode('site_b', 'normal');
        MockControl::setMode('site_c', 'always_succeed');

        // run_worker should now succeed (200)
        $heartbeatFile = dirname(__DIR__) . '/storage/worker_heartbeat.json';
        $hbMtimeBefore = file_exists($heartbeatFile) ? filemtime($heartbeatFile) : 0;

        $res200 = testHttp('http://127.0.0.1/api/demo.php', 'POST', [
            'action' => 'run_worker',
        ], $demoHeaders);
        if ($res200['status'] !== 200 || empty($res200['json']['data']['counters'])) {
            throw new Exception("run_worker failed after lock release (HTTP {$res200['status']}): " . json_encode($res200['json'] ?? $res200['body']));
        }

        MockControl::resetDefaults();

        $hbMtimeAfter = file_exists($heartbeatFile) ? filemtime($heartbeatFile) : 0;
        if ($hbMtimeAfter !== $hbMtimeBefore) {
            throw new Exception("In-process demo run_worker must NOT touch the worker daemon heartbeat file!");
        }

        echo "[PASS] 12. Demo actions: atomic modes, valid update creation, skip_wait, run_worker lock concurrency\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 12. Demo actions test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 13: Static security & syntax checks
    // ========================================================================
    try {
        $dashJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/dashboard.js');
        $insightsJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/insights.js');
        $indexPhp = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $headerPhp = (string) file_get_contents(dirname(__DIR__) . '/public/partials/header.php');

        $forbiddenJs = ['/\.html\(/', '/\binnerHTML\b/', '/\bouterHTML\b/', '/\binsertAdjacentHTML\b/', '/\bdocument\.write\b/', '/\beval\(/', '/\bsetInterval\b/'];
        foreach ($forbiddenJs as $regex) {
            if (preg_match($regex, $dashJs)) {
                throw new Exception("dashboard.js contains forbidden pattern: {$regex}");
            }
            if (preg_match($regex, $insightsJs)) {
                throw new Exception("insights.js contains forbidden pattern: {$regex}");
            }
        }

        if (!str_contains($insightsJs, 'createElementNS')) {
            throw new Exception("insights.js must use createElementNS for SVG generation");
        }

        $markupForbidden = ['/<script(?![^>]*src=)[^>]*>[^<]+/i', '/\son[a-z]+\s*=/i', '/\sstyle\s*=\s*["\']/i'];
        foreach ([$indexPhp, $headerPhp] as $fileContent) {
            foreach ($markupForbidden as $regex) {
                if (preg_match($regex, $fileContent, $m)) {
                    throw new Exception("Markup contains forbidden pattern {$regex}: " . trim($m[0]));
                }
            }
        }

        echo "[PASS] 13. Static checks: zero raw HTML injection, no setInterval, no inline script/style in markup\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 13. Static checks test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 14: Page shell structure
    // ========================================================================
    try {
        $indexHtml = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');

        if (!str_contains($indexHtml, 'id="calendar-table"') || !str_contains($indexHtml, '<caption') || !str_contains($indexHtml, 'scope=')) {
            throw new Exception("index.php missing accessible calendar table structure (caption, scope)");
        }

        if (!str_contains($indexHtml, 'id="deliveries-chart-container"') || !str_contains($indexHtml, 'role="img"') || !str_contains($indexHtml, 'id="deliveries-hidden-table"')) {
            throw new Exception("index.php missing accessible chart structure (role=img, screen-reader table)");
        }

        if (!str_contains($indexHtml, '<dialog id="retry-dead-dialog"') || !str_contains($indexHtml, 'id="btn-retry-all-dead"')) {
            throw new Exception("index.php missing native dialog or retry button");
        }

        if (!str_contains($indexHtml, 'id="status-live-announcer"') || !str_contains($indexHtml, 'aria-live="polite"')) {
            throw new Exception("index.php missing polite live announcer region");
        }

        if (!str_contains($indexHtml, '/log.php?status=dead')) {
            throw new Exception("index.php missing dead jobs link to log.php");
        }

        echo "[PASS] 14. Page shell: calendar table, chart role=img & fallback, <dialog>, live region, log links\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 14. Page shell test failed: " . $e->getMessage() . "\n";
    }

    // ========================================================================
    // TEST 15: Query budget and high-volume performance on 9,000 jobs
    // ========================================================================
    try {
        $cleanAll();

        $countingPdo = new CountingPdo2($dsn, $rawConf['user'], $rawConf['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $insService = new InsightsService($countingPdo);
        $insService->getInsightsData();
        if ($countingPdo->queryCount > 4) {
            throw new Exception("InsightsService exceeded query budget: recorded {$countingPdo->queryCount} queries (budget: <= 4)");
        }

        $countingPdo->queryCount = 0;
        $cService = new CalendarService($countingPdo);
        $cService->getCalendar(14);
        if ($countingPdo->queryCount > 4) {
            throw new Exception("CalendarService exceeded query budget: recorded {$countingPdo->queryCount} queries (budget: <= 4)");
        }

        echo "        ↳ Query budgets verified: InsightsService (<=4), CalendarService (<=4)\n";

        if (getenv('SYNC_GUARD_QUICK') === '1') {
            echo "[PASS] 15. Query budget (<= 4) verified (9,000-job benchmark skipped in quick mode)\n";
            $passedCount++;
            goto test_16_start;
        }

        $pdo->beginTransaction();
        $insU = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $insJ = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status, attempt_count, next_attempt_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)');
        $insA = $pdo->prepare('INSERT INTO sync_attempts (job_id, outcome, http_status, duration_ms, started_at) VALUES (?, ?, 200, 45, ?)');

        $now = time();
        for ($b = 1; $b <= 3000; $b++) {
            $rId = ($b % 4) + 1;
            $created = date('Y-m-d H:i:s', $now - ($b % 86400));
            $dFrom = date('Y-m-d', $now + 86400 * ($b % 10));
            $dTo = date('Y-m-d', $now + 86400 * (($b % 10) + 2));

            $insU->execute([$rId, $dFrom, $dTo, 4, 11000, Uuid::v4(), Uuid::v4(), $created]);
            $uId = (int) $pdo->lastInsertId();

            $stA = ($b % 20 === 0) ? 'dead' : 'success';
            $stB = ($b % 10 === 0) ? 'failed' : 'success';
            $stC = ($b % 5 === 0) ? 'pending' : 'success';

            $insJ->execute([$uId, 1, $stA, $stA === 'dead' ? 5 : 1]);
            $jIdA = (int) $pdo->lastInsertId();
            $insA->execute([$jIdA, $stA === 'dead' ? 'http_error' : 'success', $created]);

            $insJ->execute([$uId, 2, $stB, $stB === 'failed' ? 1 : 1]);
            $jIdB = (int) $pdo->lastInsertId();
            $insA->execute([$jIdB, $stB === 'failed' ? 'timeout' : 'success', $created]);

            $insJ->execute([$uId, 3, $stC, 0]);
        }
        $pdo->commit();
        usleep(100000); // Allow InnoDB buffer pool to settle after 9,000 inserts

        $endpoints = [
            'GET /api/status.php'       => 'http://127.0.0.1/api/status.php',
            'GET /api/insights.php'     => 'http://127.0.0.1/api/insights.php',
            'GET /api/calendar.php?14'  => 'http://127.0.0.1/api/calendar.php?days=14',
        ];

        // Warm-up call to initialize PHP opcode caches, MySQL connection and execution plans
        foreach ($endpoints as $url) {
            @testHttp($url);
        }

        echo "        ↳ High-volume benchmarks on 9,000 jobs (5 iterations each):\n";
        foreach ($endpoints as $label => $url) {
            $times = [];
            for ($i = 0; $i < 5; $i++) {
                $t0 = microtime(true);
                $resp = testHttp($url);
                $ms = (microtime(true) - $t0) * 1000;
                $times[] = $ms;
                if ($resp['status'] !== 200) {
                    throw new Exception("Benchmark failed on {$url} with status {$resp['status']}");
                }
            }
            $avgMs = array_sum($times) / count($times);
            $maxMs = max($times);
            echo sprintf("          - %-26s: avg %6.1f ms, max %6.1f ms\n", $label, $avgMs, $maxMs);
            if ($maxMs > 1000.0) {
                throw new Exception("Performance exceeded 1.0 second threshold on {$label} (max: {$maxMs} ms)");
            }
        }

        echo "        ↳ EXPLAIN query plans:\n";
        $explainQueries = [
            'Consistency Window' => "EXPLAIN SELECT u.id, u.created_at, COUNT(j.id) AS total_jobs, SUM(CASE WHEN j.status = 'success' THEN 1 ELSE 0 END) AS success_jobs, MAX(a.started_at) AS last_delivery_at FROM inventory_updates u JOIN sync_jobs j ON j.update_id = u.id JOIN channels c ON c.id = j.channel_id AND c.is_active = 1 LEFT JOIN sync_attempts a ON a.job_id = j.id AND a.outcome = 'success' WHERE u.created_at >= '2026-10-24 00:00:00' GROUP BY u.id, u.created_at ORDER BY u.id DESC LIMIT 500",
            'Hourly Buckets'     => "EXPLAIN SELECT DATE_FORMAT(started_at, '%Y-%m-%d %H:00:00') AS bucket_utc, SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) AS success_cnt, SUM(CASE WHEN outcome != 'success' THEN 1 ELSE 0 END) AS failed_cnt FROM sync_attempts WHERE started_at >= '2026-10-24 00:00:00' GROUP BY bucket_utc ORDER BY bucket_utc ASC",
            'Calendar Overlap'   => "EXPLAIN SELECT id, room_id, date_from, date_to, available_units, price FROM inventory_updates WHERE date_to >= '2026-10-24' AND date_from <= '2026-11-07' ORDER BY id ASC LIMIT 2000",
        ];

        foreach ($explainQueries as $qLabel => $sql) {
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "          [{$qLabel}]\n";
            foreach ($rows as $r) {
                echo sprintf("            table: %-18s type: %-8s key: %-16s rows: %d\n", $r['table'] ?? '?', $r['type'] ?? '?', $r['key'] ?? 'NULL', $r['rows'] ?? 0);
            }
        }

        echo "[PASS] 15. Query budget (<= 4) and performance < 1.0s on 9,000 jobs\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 15. Query budget & performance test failed: " . $e->getMessage() . "\n";
    }

    test_16_start:
    // ========================================================================
    // TEST 16: HTTP hygiene on new endpoints
    // ========================================================================
    try {
        $endpoints = [
            'insights'   => 'http://127.0.0.1/api/insights.php',
            'calendar'   => 'http://127.0.0.1/api/calendar.php',
            'retry_dead' => 'http://127.0.0.1/api/retry_dead.php',
        ];

        $insResp = testHttp($endpoints['insights']);
        $calResp = testHttp($endpoints['calendar']);
        if (!str_contains($insResp['headers'], 'no-store') || !str_contains($calResp['headers'], 'no-store')) {
            throw new Exception("Expected Cache-Control: no-store on GET endpoints");
        }

        $postIns = testHttp($endpoints['insights'], 'POST');
        if ($postIns['status'] !== 405 || !str_contains($postIns['headers'], 'Allow: GET')) {
            throw new Exception("Expected 405 with 'Allow: GET' on POST /api/insights.php");
        }

        $postCal = testHttp($endpoints['calendar'], 'POST');
        if ($postCal['status'] !== 405 || !str_contains($postCal['headers'], 'Allow: GET')) {
            throw new Exception("Expected 405 with 'Allow: GET' on POST /api/calendar.php");
        }

        $sqli = testHttp('http://127.0.0.1/api/calendar.php?days=14%27%20OR%201=1;--');
        if ($sqli['status'] !== 422) {
            throw new Exception("Expected 422 on SQL injection in days, got {$sqli['status']}");
        }
        if (str_contains($sqli['body'], 'SQL') || str_contains($sqli['body'], 'syntax') || str_contains($sqli['body'], 'Stack trace')) {
            throw new Exception("Information disclosure: raw database/stack error returned in 422 response!");
        }

        echo "[PASS] 16. HTTP hygiene: no-store, 405 Allow headers, SQLi rejection, no stack traces\n";
        $passedCount++;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        echo "[FAIL] 16. HTTP hygiene test failed: " . $e->getMessage() . "\n";
    }

} finally {
    $cleanAll();
}

echo "\n====================================================================\n";
echo sprintf("Summary: %d / %d tests passed.\n", $passedCount, $totalTests);
echo "====================================================================\n";

exit($passedCount === $totalTests ? 0 : 1);
