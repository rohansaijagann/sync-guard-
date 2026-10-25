<?php
declare(strict_types=1);

/**
 * Sync Guard - Phase 2 Verification & Smoke Test Suite (CLI)
 *
 * Runs 8 rigorous automated integration tests against the real database:
 * 1. Valid update -> 1 update row and exactly one pending job per active channel (3)
 * 2. Same idempotency key + same data twice -> still 1 update, second call reports duplicate
 * 3. Same key + different data -> conflict result (409)
 * 4. Invalid inputs each rejected with field errors
 * 5. Full-width Japanese digits (１２３) accepted and stored as 123
 * 6. Rollback: stub JobRepository throws on insert -> NO inventory_updates row remains
 * 7. Zero active channels -> rejected with 409 and nothing saved
 * 8. Japanese room name round trip remains clean and uncorrupted
 *
 * Usage:
 *   php bin/test_phase2.php
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

use SyncGuard\Database\Connection;
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Services\InventoryService;
use SyncGuard\Support\Time;
use SyncGuard\Support\Uuid;
use SyncGuard\Validation\InventoryUpdateValidator;

echo "====================================================================\n";
echo "              Sync Guard - Phase 2 Test Suite                       \n";
echo "====================================================================\n\n";

$pdo = Connection::get();

$roomRepo = new RoomRepository($pdo);
$channelRepo = new ChannelRepository($pdo);
$updateRepo = new UpdateRepository($pdo);
$jobRepo = new JobRepository($pdo);
$validator = new InventoryUpdateValidator();

$service = new InventoryService(
    $pdo,
    $roomRepo,
    $channelRepo,
    $updateRepo,
    $jobRepo,
    $validator
);

$passedCount = 0;
$totalTests = 8;
$createdUpdateIds = [];

// Helper to register an update ID for teardown cleanup
$trackId = function (int $id) use (&$createdUpdateIds): void {
    if ($id > 0) {
        $createdUpdateIds[] = $id;
    }
};

// Cleanup function to restore database state after tests
$cleanup = function () use ($pdo, &$createdUpdateIds): void {
    if (empty($createdUpdateIds)) {
        return;
    }
    $ids = array_unique($createdUpdateIds);
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec("DELETE FROM sync_jobs WHERE update_id IN ({$in})");
    $pdo->exec("DELETE FROM inventory_updates WHERE id IN ({$in})");
};

register_shutdown_function($cleanup);

$today = Time::todayInTokyo();

// ----------------------------------------------------------------------------
// TEST 1: Valid update -> 1 update row and exactly one pending job per active channel (3)
// ----------------------------------------------------------------------------
try {
    $key1 = Uuid::v4();
    $input1 = [
        'room_id'         => 1,
        'date_from'       => $today,
        'date_to'         => $today,
        'available_units' => 5,
        'price'           => 9500,
        'idempotency_key' => $key1,
    ];

    $res1 = $service->createUpdate($input1);
    if (!$res1['ok'] || $res1['statusCode'] !== 201) {
        throw new Exception("Expected 201 Created, got: " . json_encode($res1));
    }

    $updateId1 = (int) $res1['data']['update_id'];
    $trackId($updateId1);

    if ($res1['data']['jobs_queued'] !== 3) {
        throw new Exception("Expected 3 jobs queued, got: " . $res1['data']['jobs_queued']);
    }

    // Verify DB state
    $stmtU = $pdo->prepare('SELECT COUNT(*) FROM inventory_updates WHERE id = :id');
    $stmtU->execute([':id' => $updateId1]);
    if ((int) $stmtU->fetchColumn() !== 1) {
        throw new Exception("Update record not found in inventory_updates");
    }

    $stmtJ = $pdo->prepare('SELECT COUNT(*) FROM sync_jobs WHERE update_id = :id AND status = "pending"');
    $stmtJ->execute([':id' => $updateId1]);
    $jobCount = (int) $stmtJ->fetchColumn();
    if ($jobCount !== 3) {
        throw new Exception("Expected 3 pending jobs in sync_jobs, found: {$jobCount}");
    }

    echo "[PASS]  1. Valid update created with outbox jobs\n";
    echo "        ↳ 1 inventory_updates row created, 3 sync_jobs queued with status 'pending'\n";
    $passedCount++;
} catch (Throwable $e) {
    echo "[FAIL]  1. Valid update creation failed: " . $e->getMessage() . "\n";
}

// ----------------------------------------------------------------------------
// TEST 2: Same idempotency key + same data twice -> still 1 update, second reports duplicate
// ----------------------------------------------------------------------------
try {
    $key2 = Uuid::v4();
    $input2 = [
        'room_id'         => 2,
        'date_from'       => $today,
        'date_to'         => $today,
        'available_units' => 3,
        'price'           => 14000,
        'idempotency_key' => $key2,
    ];

    $res2a = $service->createUpdate($input2);
    if (!$res2a['ok'] || $res2a['statusCode'] !== 201 || $res2a['data']['duplicate'] !== false) {
        throw new Exception("Initial call failed: " . json_encode($res2a));
    }
    $trackId((int) $res2a['data']['update_id']);

    // Replay identical submission
    $res2b = $service->createUpdate($input2);
    if (!$res2b['ok'] || $res2b['statusCode'] !== 200 || $res2b['data']['duplicate'] !== true) {
        throw new Exception("Duplicate call failed to return 200 duplicate: " . json_encode($res2b));
    }

    // Ensure database contains exactly 1 row with this key
    $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM inventory_updates WHERE idempotency_key = :k');
    $stmtCheck->execute([':k' => $key2]);
    if ((int) $stmtCheck->fetchColumn() !== 1) {
        throw new Exception("More than 1 update row was found for idempotency key");
    }

    echo "[PASS]  2. Idempotent duplicate replay protection\n";
    echo "        ↳ Exact duplicate returned HTTP 200 with duplicate=true; exactly 1 DB row exists\n";
    $passedCount++;
} catch (Throwable $e) {
    echo "[FAIL]  2. Duplicate replay test failed: " . $e->getMessage() . "\n";
}

// ----------------------------------------------------------------------------
// TEST 3: Same key + different data -> conflict result (409)
// ----------------------------------------------------------------------------
try {
    $key3 = Uuid::v4();
    $input3a = [
        'room_id'         => 2,
        'date_from'       => $today,
        'date_to'         => $today,
        'available_units' => 2,
        'price'           => 14000,
        'idempotency_key' => $key3,
    ];
    $res3a = $service->createUpdate($input3a);
    $trackId((int) $res3a['data']['update_id']);

    // Second call uses same key but different units
    $input3b = $input3a;
    $input3b['available_units'] = 4; // Altered data!

    $res3b = $service->createUpdate($input3b);
    if ($res3b['ok'] !== false || $res3b['statusCode'] !== 409) {
        throw new Exception("Expected 409 Conflict, got: " . json_encode($res3b));
    }

    echo "[PASS]  3. Key collision with altered data returns 409 Conflict\n";
    echo "        ↳ Correctly rejected request reusing an existing idempotency key with different data\n";
    $passedCount++;
} catch (Throwable $e) {
    echo "[FAIL]  3. Key collision test failed: " . $e->getMessage() . "\n";
}

// ----------------------------------------------------------------------------
// TEST 4: Invalid inputs each rejected with a field error
// ----------------------------------------------------------------------------
try {
    $invalidCases = [
        'negative units' => [
            'input' => ['room_id' => 1, 'date_from' => $today, 'date_to' => $today, 'available_units' => -1, 'price' => 8500, 'idempotency_key' => Uuid::v4()],
            'field' => 'available_units'
        ],
        'units above total_units' => [
            'input' => ['room_id' => 1, 'date_from' => $today, 'date_to' => $today, 'available_units' => 999, 'price' => 8500, 'idempotency_key' => Uuid::v4()],
            'field' => 'available_units'
        ],
        'reversed dates' => [
            'input' => ['room_id' => 1, 'date_from' => '2026-12-10', 'date_to' => '2026-12-01', 'available_units' => 2, 'price' => 8500, 'idempotency_key' => Uuid::v4()],
            'field' => 'date_to'
        ],
        '2026-02-31 calendar reality' => [
            'input' => ['room_id' => 1, 'date_from' => '2026-02-31', 'date_to' => '2026-03-05', 'available_units' => 2, 'price' => 8500, 'idempotency_key' => Uuid::v4()],
            'field' => 'date_from'
        ],
        'nonexistent room' => [
            'input' => ['room_id' => 99999, 'date_from' => $today, 'date_to' => $today, 'available_units' => 2, 'price' => 8500, 'idempotency_key' => Uuid::v4()],
            'field' => 'room_id'
        ],
        'neither units nor price' => [
            'input' => ['room_id' => 1, 'date_from' => $today, 'date_to' => $today, 'available_units' => null, 'price' => null, 'idempotency_key' => Uuid::v4()],
            'field' => 'available_units'
        ],
        'bad UUID' => [
            'input' => ['room_id' => 1, 'date_from' => $today, 'date_to' => $today, 'available_units' => 2, 'price' => 8500, 'idempotency_key' => 'not-a-valid-uuid'],
            'field' => 'idempotency_key'
        ],
        'date in the past' => [
            'input' => ['room_id' => 1, 'date_from' => '2020-01-01', 'date_to' => '2020-01-02', 'available_units' => 2, 'price' => 8500, 'idempotency_key' => Uuid::v4()],
            'field' => 'date_from'
        ],
    ];

    foreach ($invalidCases as $name => $case) {
        $res = $service->createUpdate($case['input']);
        if ($res['ok'] !== false || $res['statusCode'] !== 422 || !isset($res['fields'][$case['field']])) {
            throw new Exception("Case '{$name}' did not fail as expected: " . json_encode($res));
        }
    }

    echo "[PASS]  4. Strict server-side validation rules enforced\n";
    echo "        ↳ All 8 invalid input cases rejected with HTTP 422 and descriptive field errors\n";
    $passedCount++;
} catch (Throwable $e) {
    echo "[FAIL]  4. Validation testing failed: " . $e->getMessage() . "\n";
}

// ----------------------------------------------------------------------------
// TEST 5: Full-width digits (１２３) accepted and stored as 123
// ----------------------------------------------------------------------------
try {
    $key5 = Uuid::v4();
    $input5 = [
        'room_id'         => '１',             // Full-width 1
        'date_from'       => $today,
        'date_to'         => $today,
        'available_units' => '１０',           // Full-width 10
        'price'           => '１８０００',       // Full-width 18000
        'idempotency_key' => $key5,
    ];

    $res5 = $service->createUpdate($input5);
    if (!$res5['ok'] || $res5['statusCode'] !== 201) {
        throw new Exception("Full-width conversion failed: " . json_encode($res5));
    }

    $updateId5 = (int) $res5['data']['update_id'];
    $trackId($updateId5);

    $stmt5 = $pdo->prepare('SELECT available_units, price FROM inventory_updates WHERE id = :id');
    $stmt5->execute([':id' => $updateId5]);
    $stored = $stmt5->fetch();

    if ((int) $stored['available_units'] !== 10 || (float) $stored['price'] !== 18000.00) {
        throw new Exception("Full-width values not stored properly: " . json_encode($stored));
    }

    echo "[PASS]  5. Full-width Japanese IME digit normalization\n";
    echo "        ↳ '１０' and '１８０００' normalized via mb_convert_kana and saved as 10 and 18000.00\n";
    $passedCount++;
} catch (Throwable $e) {
    echo "[FAIL]  5. Full-width normalization test failed: " . $e->getMessage() . "\n";
}

// ----------------------------------------------------------------------------
// TEST 6: Rollback: stub JobRepository throws on insert -> NO inventory_updates row remains
// ----------------------------------------------------------------------------
try {
    $key6 = Uuid::v4();
    $failingJobRepo = new class($pdo) extends JobRepository {
        public function createJobsForUpdate(int $updateId, array $channelIds): int
        {
            throw new RuntimeException("Simulated worker queue crash during job insertion!");
        }
    };

    $failingService = new InventoryService(
        $pdo,
        $roomRepo,
        $channelRepo,
        $updateRepo,
        $failingJobRepo,
        $validator
    );

    $input6 = [
        'room_id'         => 1,
        'date_from'       => $today,
        'date_to'         => $today,
        'available_units' => 2,
        'price'           => 8500,
        'idempotency_key' => $key6,
    ];

    $res6 = $failingService->createUpdate($input6);
    if ($res6['ok'] !== false || $res6['statusCode'] !== 500) {
        throw new Exception("Expected 500 server error on simulated failure, got: " . json_encode($res6));
    }

    // Verify atomic rollback in MySQL: NO update row should exist with this idempotency key
    $stmt6 = $pdo->prepare('SELECT COUNT(*) FROM inventory_updates WHERE idempotency_key = :k');
    $stmt6->execute([':k' => $key6]);
    $count6 = (int) $stmt6->fetchColumn();

    if ($count6 !== 0) {
        throw new Exception("Atomic rollback failed! Found orphaned inventory_updates row with key: {$key6}");
    }

    echo "[PASS]  6. Transactional Outbox atomic rollback\n";
    echo "        ↳ JobRepository exception triggered complete transaction rollback; 0 orphaned rows remain\n";
    $passedCount++;
} catch (Throwable $e) {
    echo "[FAIL]  6. Rollback test failed: " . $e->getMessage() . "\n";
}

// ----------------------------------------------------------------------------
// TEST 7: Zero active channels -> rejected with 409 and nothing saved
// ----------------------------------------------------------------------------
try {
    $key7 = Uuid::v4();
    $zeroChannelRepo = new class($pdo) extends ChannelRepository {
        public function getActiveChannels(): array
        {
            return []; // Simulates zero active channels
        }
    };

    $zeroChannelService = new InventoryService(
        $pdo,
        $roomRepo,
        $zeroChannelRepo,
        $updateRepo,
        $jobRepo,
        $validator
    );

    $input7 = [
        'room_id'         => 1,
        'date_from'       => $today,
        'date_to'         => $today,
        'available_units' => 2,
        'price'           => 8500,
        'idempotency_key' => $key7,
    ];

    $res7 = $zeroChannelService->createUpdate($input7);
    if ($res7['ok'] !== false || $res7['statusCode'] !== 409) {
        throw new Exception("Expected 409 Conflict when 0 active channels exist, got: " . json_encode($res7));
    }

    $stmt7 = $pdo->prepare('SELECT COUNT(*) FROM inventory_updates WHERE idempotency_key = :k');
    $stmt7->execute([':k' => $key7]);
    if ((int) $stmt7->fetchColumn() !== 0) {
        throw new Exception("Update row was improperly saved despite zero active channels!");
    }

    echo "[PASS]  7. Zero active channels guard rule\n";
    echo "        ↳ Correctly rejected with HTTP 409 and prevented unreachable inventory updates\n";
    $passedCount++;
} catch (Throwable $e) {
    echo "[FAIL]  7. Zero active channels test failed: " . $e->getMessage() . "\n";
}

// ----------------------------------------------------------------------------
// TEST 8: Japanese room name round trip still correct
// ----------------------------------------------------------------------------
try {
    $rooms = $roomRepo->getAll();
    $roomMap = [];
    foreach ($rooms as $r) {
        $roomMap[(int) $r['id']] = $r['name'];
    }

    $expectedJapanese = [
        1 => 'シングルルーム (Single Room)',
        2 => 'ツインルーム (Twin Room)',
        3 => '和室 (Japanese-style Room)',
        4 => 'スイート (Suite)',
    ];

    foreach ($expectedJapanese as $id => $expectedName) {
        if (!isset($roomMap[$id]) || $roomMap[$id] !== $expectedName) {
            throw new Exception("Room {$id} name mismatch: expected '{$expectedName}', found '" . ($roomMap[$id] ?? 'missing') . "'");
        }
        if (!mb_check_encoding($roomMap[$id], 'UTF-8')) {
            throw new Exception("Room {$id} name corrupted or not valid UTF-8");
        }
    }

    echo "[PASS]  8. Japanese room name UTF-8 round trip\n";
    echo "        ↳ All 4 room names matched expected Kanji and Kana with zero character mangling\n";
    $passedCount++;
} catch (Throwable $e) {
    echo "[FAIL]  8. Japanese room name test failed: " . $e->getMessage() . "\n";
}

// Cleanup any remaining test updates
$cleanup();

echo "\n====================================================================\n";
echo "Summary: {$passedCount} / {$totalTests} tests passed.\n";
echo "====================================================================\n";

if ($passedCount === $totalTests) {
    echo "Result: ALL TESTS PASSED. Phase 2 implementation is rock-solid!\n\n";
    exit(0);
} else {
    echo "Result: SOME TESTS FAILED. Please review the output above.\n\n";
    exit(1);
}
