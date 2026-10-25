<?php
declare(strict_types=1);

/**
 * Sync Guard - Verification Script
 *
 * Verifies that the environment, database, schema, indexes, seed data,
 * character encoding, timezone, and relational integrity satisfy all Phase 1 criteria.
 *
 * Exit Codes:
 *   0 = All checks PASSED
 *   1 = One or more checks FAILED
 */

// Autoloading with automatic fallback
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

echo "====================================================================\n";
echo "              Sync Guard - Phase 1 Verification Suite               \n";
echo "====================================================================\n\n";

$checks = [];
$pdo = null;
$config = require dirname(__DIR__) . '/config/config.php';
$dbName = $config['db']['name'] ?? 'sync_guard';

function report(string $name, bool $passed, string $details = ''): bool
{
    $status = $passed ? "[PASS]" : "[FAIL]";
    printf("%-7s %s\n", $status, $name);
    if ($details !== '') {
        echo "        ↳ " . $details . "\n";
    }
    return $passed;
}

// ----------------------------------------------------------------------------
// Check 1: PHP version >= 8.2 and pdo_mysql extension loaded
// ----------------------------------------------------------------------------
$phpVersionOk = version_compare(PHP_VERSION, '8.2.0', '>=');
$pdoMysqlOk = extension_loaded('pdo_mysql');
$c1Pass = $phpVersionOk && $pdoMysqlOk;
$c1Details = sprintf(
    'PHP Version: %s (>= 8.2 required: %s) | pdo_mysql loaded: %s',
    PHP_VERSION,
    $phpVersionOk ? 'yes' : 'no',
    $pdoMysqlOk ? 'yes' : 'no'
);
$checks[] = report('1. PHP 8.2+ and pdo_mysql extension', $c1Pass, $c1Details);

// ----------------------------------------------------------------------------
// Check 2: Database connection works
// ----------------------------------------------------------------------------
$c2Pass = false;
$c2Details = '';
try {
    $pdo = Connection::get();
    $c2Pass = true;
    $c2Details = sprintf('Connected successfully to database "%s"', $dbName);
} catch (Throwable $e) {
    $c2Details = 'Connection failed: ' . $e->getMessage();
}
$checks[] = report('2. Database connection', $c2Pass, $c2Details);

// If database connection fails, stop subsequent database checks
if (!$c2Pass || $pdo === null) {
    echo "\nCannot continue database checks because connection failed.\n";
    exit(1);
}

// ----------------------------------------------------------------------------
// Check 3: All 6 required tables exist
// ----------------------------------------------------------------------------
$expectedTables = [
    'rooms',
    'channels',
    'inventory_updates',
    'sync_jobs',
    'sync_attempts',
    'channel_health',
];

$stmt = $pdo->prepare('
    SELECT TABLE_NAME 
    FROM information_schema.tables 
    WHERE TABLE_SCHEMA = :db
');
$stmt->execute([':db' => $dbName]);
$existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

$missingTables = array_diff($expectedTables, $existingTables);
$c3Pass = count($missingTables) === 0;
$c3Details = $c3Pass
    ? sprintf('All 6 tables present: %s', implode(', ', $expectedTables))
    : sprintf('Missing tables: %s (Found: %s)', implode(', ', $missingTables), implode(', ', $existingTables));
$checks[] = report('3. All 6 tables exist', $c3Pass, $c3Details);

// ----------------------------------------------------------------------------
// Check 4: Connection charset is utf8mb4 and session timezone is UTC
// ----------------------------------------------------------------------------
$stmt = $pdo->query('
    SELECT 
        @@character_set_connection AS charset_conn,
        @@session.time_zone AS session_tz
');
$sysRow = $stmt->fetch();
$charsetConn = (string) ($sysRow['charset_conn'] ?? '');
$sessionTz   = (string) ($sysRow['session_tz'] ?? '');

$charsetOk = str_starts_with(strtolower($charsetConn), 'utf8mb4');
$tzOk = in_array(strtoupper($sessionTz), ['+00:00', 'UTC', 'SYSTEM'], true) && ($sessionTz === '+00:00' || $sessionTz === 'UTC');
$c4Pass = $charsetOk && $tzOk;
$c4Details = sprintf(
    'character_set_connection: "%s" (utf8mb4: %s) | session.time_zone: "%s" (UTC/+00:00: %s)',
    $charsetConn,
    $charsetOk ? 'yes' : 'no',
    $sessionTz,
    $tzOk ? 'yes' : 'no'
);
$checks[] = report('4. Charset utf8mb4 and UTC timezone', $c4Pass, $c4Details);

// ----------------------------------------------------------------------------
// Check 5: Required indexes exist
// - idx_due (on sync_jobs)
// - uq_update_channel (on sync_jobs)
// - idempotency_key UNIQUE (on inventory_updates)
// ----------------------------------------------------------------------------
$indexStmt = $pdo->prepare('
    SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME
    FROM information_schema.statistics
    WHERE TABLE_SCHEMA = :db
');
$indexStmt->execute([':db' => $dbName]);
$indexes = $indexStmt->fetchAll();

$hasIdxDue = false;
$hasUqUpdateChannel = false;
$hasUniqueIdempotency = false;

foreach ($indexes as $idx) {
    $table = $idx['TABLE_NAME'];
    $idxName = $idx['INDEX_NAME'];
    $colName = $idx['COLUMN_NAME'];
    $nonUnique = (int) $idx['NON_UNIQUE'];

    if ($table === 'sync_jobs' && $idxName === 'idx_due') {
        $hasIdxDue = true;
    }
    if ($table === 'sync_jobs' && $idxName === 'uq_update_channel') {
        $hasUqUpdateChannel = true;
    }
    if ($table === 'inventory_updates' && $colName === 'idempotency_key' && $nonUnique === 0) {
        $hasUniqueIdempotency = true;
    }
}

$c5Pass = $hasIdxDue && $hasUqUpdateChannel && $hasUniqueIdempotency;
$c5Details = sprintf(
    'idx_due: %s | uq_update_channel: %s | unique idempotency_key: %s',
    $hasIdxDue ? 'found' : 'MISSING',
    $hasUqUpdateChannel ? 'found' : 'MISSING',
    $hasUniqueIdempotency ? 'found' : 'MISSING'
);
$checks[] = report('5. Required indexes present', $c5Pass, $c5Details);

// ----------------------------------------------------------------------------
// Check 6: Seed counts: 4 rooms, 3 channels, 3 channel_health rows
// ----------------------------------------------------------------------------
$roomCount = (int) $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
$channelCount = (int) $pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn();
$healthCount = (int) $pdo->query('SELECT COUNT(*) FROM channel_health')->fetchColumn();

$c6Pass = ($roomCount === 4) && ($channelCount === 3) && ($healthCount === 3);
$c6Details = sprintf(
    'Rooms: %d (expected 4) | Channels: %d (expected 3) | Channel Health: %d (expected 3)',
    $roomCount,
    $channelCount,
    $healthCount
);
$checks[] = report('6. Seed record counts', $c6Pass, $c6Details);

// ----------------------------------------------------------------------------
// Check 7: Japanese round trip (Insert, read back, verify, roll back)
// ----------------------------------------------------------------------------
$c7Pass = false;
$c7Details = '';
try {
    $pdo->beginTransaction();

    $japaneseText = '露天風呂付き特別スイート（富士山眺望）🌸 予約受付中 12345';
    $stmt = $pdo->prepare('
        INSERT INTO rooms (name, total_units, base_price) 
        VALUES (:name, 1, 99999.00)
    ');
    $stmt->execute([':name' => $japaneseText]);
    $insertedId = (int) $pdo->lastInsertId();

    $readStmt = $pdo->prepare('SELECT name FROM rooms WHERE id = :id');
    $readStmt->execute([':id' => $insertedId]);
    $fetchedName = (string) $readStmt->fetchColumn();

    // Revert test insert immediately
    $pdo->rollBack();

    if ($fetchedName === $japaneseText) {
        $c7Pass = true;
        $c7Details = sprintf('Round-trip matched exactly: "%s"', $fetchedName);
    } else {
        $c7Details = sprintf('Mismatch! Expected: "%s", Received: "%s"', $japaneseText, $fetchedName);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $c7Details = 'Japanese test failed with error: ' . $e->getMessage();
}
$checks[] = report('7. Japanese UTF-8 round trip', $c7Pass, $c7Details);

// ----------------------------------------------------------------------------
// Check 8: Foreign key violation correctly rejected
// ----------------------------------------------------------------------------
$c8Pass = false;
$c8Details = '';
try {
    $pdo->beginTransaction();

    // Attempt inserting a job with non-existent update_id (e.g. 999999)
    $stmt = $pdo->prepare('
        INSERT INTO sync_jobs (update_id, channel_id, status)
        VALUES (999999, 1, "pending")
    ');
    $stmt->execute();

    // If execution reached here without an exception, foreign keys are NOT enforced!
    $pdo->rollBack();
    $c8Details = 'Foreign key violation was NOT rejected (expected PDOException was not thrown)';
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // SQLSTATE 23000 indicates integrity constraint violation
    if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'foreign key constraint fails')) {
        $c8Pass = true;
        $c8Details = 'Correctly threw PDOException on FK violation: ' . $e->getMessage();
    } else {
        $c8Details = 'Threw unexpected exception: ' . $e->getMessage();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $c8Details = 'Unexpected non-PDO error: ' . $e->getMessage();
}
$checks[] = report('8. Foreign key constraint enforcement', $c8Pass, $c8Details);

// ----------------------------------------------------------------------------
// Summary
// ----------------------------------------------------------------------------
echo "\n====================================================================\n";
$totalChecks = count($checks);
$passedChecks = count(array_filter($checks));

printf("Summary: %d / %d checks passed.\n", $passedChecks, $totalChecks);
echo "====================================================================\n";

if ($passedChecks === $totalChecks) {
    echo "Result: ALL CHECKS PASSED. Phase 1 foundation is sound!\n";
    exit(0);
} else {
    echo "Result: VERIFICATION FAILED. Review errors above.\n";
    exit(1);
}
