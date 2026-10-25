<?php
declare(strict_types=1);

/**
 * Sync Guard - Unified Test Suite Runner (CLI)
 *
 * Runs each test suite in a separate, isolated child process with independent timeouts.
 * Enforces non-nested process isolation, automatic cleanup, environment safety,
 * and comprehensive diagnostic reporting.
 *
 * Usage:
 *   php bin/run_tests.php                (Runs full test suite)
 *   php bin/run_tests.php --quick        (Skips slow tests; sets SYNC_GUARD_QUICK=1)
 *   php bin/run_tests.php --list         (Lists registered suites and exit)
 *   php bin/run_tests.php --only=a,b     (Runs only specific suites)
 *   php bin/run_tests.php --keep-going   (Continues running remaining suites on failure)
 *   php bin/run_tests.php --with-browser (Enables Docker Playwright smoke test)
 *   php bin/run_tests.php --verbose      (Prints child stdout/stderr even on success)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/run_tests.php must be run from the CLI.\n");
    exit(1);
}

// ----------------------------------------------------------------------------
// 1. Safety Guard: Require APP_ENV=local
// ----------------------------------------------------------------------------
$projectRoot = dirname(__DIR__);
$config = require $projectRoot . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');

if (strtolower($appEnv) !== 'local') {
    fwrite(STDERR, sprintf("\n[ERROR] APP_ENV is '%s'. bin/run_tests.php is strictly forbidden in non-local environments.\n", $appEnv));
    exit(1);
}

// ----------------------------------------------------------------------------
// 2. Suite Registry Array
// ----------------------------------------------------------------------------
$suites = [
    [
        'name'    => 'verify_setup',
        'command' => 'php bin/verify_setup.php',
        'timeout' => 30,
        'tags'    => ['core', 'fast'],
    ],
    [
        'name'    => 'test_phase2',
        'command' => 'php bin/test_phase2.php',
        'timeout' => 60,
        'tags'    => ['core', 'fast'],
    ],
    [
        'name'    => 'test_phase3',
        'command' => 'php bin/test_phase3.php',
        'timeout' => 60,
        'tags'    => ['core', 'fast'],
    ],
    [
        'name'    => 'test_phase4',
        'command' => 'php bin/test_phase4.php',
        'timeout' => 60,
        'tags'    => ['core', 'fast'],
    ],
    [
        'name'    => 'test_phase5',
        'command' => 'php bin/test_phase5.php',
        'timeout' => 60,
        'tags'    => ['core', 'fast'],
    ],
    [
        'name'    => 'test_phase6',
        'command' => 'php bin/test_phase6.php',
        'timeout' => 60,
        'tags'    => ['core', 'fast'],
    ],
    [
        'name'    => 'test_phase6b',
        'command' => 'php bin/test_phase6b.php',
        'timeout' => 120,
        'tags'    => ['dashboard', 'fast'],
    ],
    [
        'name'    => 'test_phase6c',
        'command' => 'php bin/test_phase6c.php',
        'timeout' => 60,
        'tags'    => ['dashboard', 'fast'],
    ],
    [
        'name'    => 'test_phase7',
        'command' => 'php bin/test_phase7.php',
        'timeout' => 120,
        'tags'    => ['log', 'fast'],
    ],
    [
        'name'    => 'test_ui_inventory',
        'command' => 'php bin/test_ui_inventory.php',
        'timeout' => 60,
        'tags'    => ['ui', 'fast'],
    ],
    [
        'name'    => 'browser_smoke',
        'command' => PHP_OS_FAMILY === 'Windows'
            ? 'powershell -ExecutionPolicy Bypass -File .\\tools\\e2e\\run.ps1'
            : 'docker run --rm --network syncguard_default -v "' . $projectRoot . '/tools/e2e:/e2e" -w /e2e mcr.microsoft.com/playwright:v1.49.0-noble node smoke.mjs',
        'timeout' => 120,
        'tags'    => ['browser', 'slow'],
    ],
];

// ----------------------------------------------------------------------------
// 3. Option Parsing
// ----------------------------------------------------------------------------
$options = [
    'list'         => false,
    'quick'        => false,
    'only'         => null,
    'keep-going'   => false,
    'with-browser' => false,
    'verbose'      => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--list') {
        $options['list'] = true;
    } elseif ($arg === '--quick') {
        $options['quick'] = true;
    } elseif ($arg === '--keep-going') {
        $options['keep-going'] = true;
    } elseif ($arg === '--with-browser') {
        $options['with-browser'] = true;
    } elseif ($arg === '--verbose') {
        $options['verbose'] = true;
    } elseif (str_starts_with($arg, '--only=')) {
        $options['only'] = array_map('trim', explode(',', substr($arg, 7)));
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "Sync Guard Test Runner\n";
        echo "Options:\n";
        echo "  --list           List all registered test suites\n";
        echo "  --quick          Skip slow tests / 9,000-job benchmarks (sets SYNC_GUARD_QUICK=1)\n";
        echo "  --only=a,b       Run only specified comma-separated suites\n";
        echo "  --keep-going     Continue running remaining suites on failure (default: stop)\n";
        echo "  --with-browser   Include Playwright browser smoke tests\n";
        echo "  --verbose        Display child process output even on pass\n";
        exit(0);
    }
}

// ----------------------------------------------------------------------------
// 4. Handle --list
// ----------------------------------------------------------------------------
if ($options['list']) {
    echo "========================================================================================\n";
    echo "                         Sync Guard - Registered Test Suites                            \n";
    echo "========================================================================================\n";
    printf("%-20s %-8s %-16s %-40s\n", "Suite Name", "Timeout", "Tags", "Command");
    echo str_repeat('-', 88) . "\n";
    foreach ($suites as $s) {
        printf("%-20s %-8s %-16s %-40s\n", $s['name'], $s['timeout'] . 's', implode(', ', $s['tags']), $s['command']);
    }
    echo "========================================================================================\n";
    exit(0);
}

// ----------------------------------------------------------------------------
// 5. Cleanup Utility
// ----------------------------------------------------------------------------
$cleanup = function () use ($projectRoot): void {
    // 1. Reset mock control file
    $controlFile = $projectRoot . '/storage/mock/control.json';
    @file_put_contents($controlFile, json_encode(['site_b' => 'normal', 'site_c' => 'random'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @chmod($controlFile, 0666);

    // 2. Reset mock storage files
    $storageFiles = [
        'site_a.json' => ['received_count' => 0, 'duplicate_hits' => 0, 'updates' => []],
        'site_b.json' => ['received_count' => 0, 'duplicate_hits' => 0, 'updates' => []],
        'site_c.json' => ['received_count' => 0, 'duplicate_hits' => 0, 'forgotten_count' => 0, 'updates' => []],
    ];
    foreach ($storageFiles as $file => $initial) {
        $path = $projectRoot . '/storage/mock/' . $file;
        @file_put_contents($path, json_encode($initial, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0666);
    }

    // 3. Reset queue non-interactively
    @exec('php ' . escapeshellarg($projectRoot . '/bin/reset_queue.php') . ' --yes 2>&1');

    // 4. Rebuild channel health non-interactively
    @exec('php ' . escapeshellarg($projectRoot . '/bin/rebuild_health.php') . ' 2>&1');
};

// Register shutdown function to guarantee cleanup
register_shutdown_function($cleanup);

// Run pre-test cleanup
$cleanup();

// ----------------------------------------------------------------------------
// 6. Filter Suites to Run
// ----------------------------------------------------------------------------
$suitesToRun = [];
foreach ($suites as $s) {
    // If --only specified, filter by name
    if ($options['only'] !== null && !in_array($s['name'], $options['only'], true)) {
        continue;
    }

    // Skip browser suite unless --with-browser
    if (in_array('browser', $s['tags'], true) && !$options['with-browser']) {
        continue;
    }

    // If --quick, skip suites tagged 'slow'
    if ($options['quick'] && in_array('slow', $s['tags'], true)) {
        continue;
    }

    $suitesToRun[] = $s;
}

if (empty($suitesToRun)) {
    echo "No test suites selected for execution.\n";
    exit(0);
}

// ----------------------------------------------------------------------------
// 7. Test Execution Engine
// ----------------------------------------------------------------------------
echo "\n========================================================================================\n";
echo "                      Sync Guard - Test Runner Execution                                \n";
echo sprintf(" Mode: %s | Suites: %d | Keep-Going: %s\n",
    $options['quick'] ? 'Quick (SYNC_GUARD_QUICK=1)' : 'Full',
    count($suitesToRun),
    $options['keep-going'] ? 'yes' : 'no'
);
echo "========================================================================================\n\n";

$results = [];
$anyFailed = false;
$startTimeAll = microtime(true);

foreach ($suitesToRun as $suite) {
    $name = $suite['name'];
    $cmd = $suite['command'];
    $timeoutSec = (int) $suite['timeout'];

    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $env = $_ENV;
    $env['APP_ENV'] = 'local';
    if ($options['quick']) {
        $env['SYNC_GUARD_QUICK'] = '1';
    }

    $t0 = microtime(true);
    $proc = proc_open($cmd, $descriptors, $pipes, $projectRoot, $env);

    if (!is_resource($proc)) {
        echo sprintf("  [FAIL] %-20s - Failed to spawn process\n", $name);
        $results[] = [
            'name'     => $name,
            'status'   => 'FAIL',
            'summary'  => 'Spawn failed',
            'duration' => 0.0,
            'notes'    => 'proc_open failed',
        ];
        $anyFailed = true;
        if (!$options['keep-going']) {
            break;
        }
        continue;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdoutBuffer = '';
    $stderrBuffer = '';
    $timedOut = false;
    $exitCode = -1;

    while (true) {
        $status = proc_get_status($proc);
        $elapsed = microtime(true) - $t0;

        $r1 = fread($pipes[1], 8192);
        if ($r1 !== false && $r1 !== '') {
            $stdoutBuffer .= $r1;
        }

        $r2 = fread($pipes[2], 8192);
        if ($r2 !== false && $r2 !== '') {
            $stderrBuffer .= $r2;
        }

        if (!$status['running']) {
            $exitCode = $status['exitcode'];
            break;
        }

        if ($elapsed > $timeoutSec) {
            $timedOut = true;
            $pid = $status['pid'] ?? null;
            if ($pid) {
                if (PHP_OS_FAMILY === 'Windows') {
                    @exec("taskkill /F /T /PID {$pid} 2>NUL");
                } else {
                    @exec("kill -9 {$pid} 2>/dev/null");
                }
            }
            @proc_terminate($proc);
            break;
        }

        usleep(50000); // 50ms polling tick
    }

    // Read remaining buffer
    $stdoutBuffer .= stream_get_contents($pipes[1]);
    $stderrBuffer .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $closeCode = proc_close($proc);
    if ($exitCode === -1 && $closeCode !== -1) {
        $exitCode = $closeCode;
    }
    $duration = microtime(true) - $t0;

    $fullOutput = trim($stdoutBuffer . "\n" . $stderrBuffer);
    $outputLines = explode("\n", $fullOutput);

    // Parse summary line if available
    $summary = '—';
    if (preg_match('/Summary:\s*(\d+\s*\/\s*\d+[^.\n\r]*)/i', $fullOutput, $m)) {
        $summary = trim($m[1]);
    } elseif (preg_match('/TEST RESULTS:\s*(\d+\s*Passed,\s*\d+\s*Failed)/i', $fullOutput, $m)) {
        $summary = trim($m[1]);
    } elseif (preg_match('/Results:\s*(\d+\/\d+\s*Passed)/i', $fullOutput, $m)) {
        $summary = trim($m[1]);
    }

    if ($timedOut) {
        $anyFailed = true;
        echo sprintf("  [FAIL] %-20s TIMED OUT after %ds\n", $name, $timeoutSec);
        echo "         ↳ Last 20 lines of output:\n";
        $last20 = array_slice($outputLines, -20);
        foreach ($last20 as $l) {
            echo "           " . $l . "\n";
        }
        $results[] = [
            'name'     => $name,
            'status'   => 'TIMEOUT',
            'summary'  => "Timeout ({$timeoutSec}s)",
            'duration' => $duration,
            'notes'    => "Exceeded {$timeoutSec}s limit",
        ];
        $cleanup();
        if (!$options['keep-going']) {
            break;
        }
        continue;
    }

    $isPass = ($exitCode === 0);
    if ($isPass) {
        echo sprintf("  [PASS] %-20s (%s) [%.2fs]\n", $name, $summary, $duration);
        if ($options['verbose'] && !empty($fullOutput)) {
            echo "         --------------------------------------------------\n";
            foreach ($outputLines as $line) {
                echo "         " . $line . "\n";
            }
            echo "         --------------------------------------------------\n";
        }
    } else {
        $anyFailed = true;
        echo sprintf("  [FAIL] %-20s (exit code %d) [%.2fs]\n", $name, $exitCode, $duration);
        echo "         --------------------------------------------------\n";
        $tail = $options['verbose'] ? $outputLines : array_slice($outputLines, -20);
        foreach ($tail as $line) {
            echo "         " . $line . "\n";
        }
        echo "         --------------------------------------------------\n";
    }

    $results[] = [
        'name'     => $name,
        'status'   => $isPass ? 'PASS' : 'FAIL',
        'summary'  => $summary,
        'duration' => $duration,
        'notes'    => $isPass ? 'OK' : "Exit code {$exitCode}",
    ];

    if (!$isPass && !$options['keep-going']) {
        break;
    }
}

$totalDuration = microtime(true) - $startTimeAll;

// Post-test cleanup
$cleanup();

// ----------------------------------------------------------------------------
// 8. Results Summary Table
// ----------------------------------------------------------------------------
echo "\n========================================================================================\n";
echo "                                   FINAL TEST RESULTS                                   \n";
echo "========================================================================================\n";
printf("%-20s %-10s %-26s %-12s %-16s\n", "Suite Name", "Status", "Summary", "Duration", "Notes");
echo str_repeat('-', 88) . "\n";

$passCount = 0;
foreach ($results as $res) {
    if ($res['status'] === 'PASS') {
        $passCount++;
    }
    printf("%-20s %-10s %-26s %-12s %-16s\n",
        $res['name'],
        $res['status'],
        substr($res['summary'], 0, 26),
        sprintf("%.2fs", $res['duration']),
        $res['notes']
    );
}

echo str_repeat('-', 88) . "\n";
echo sprintf(" Total: %d / %d suites passed in %.2f seconds.\n", $passCount, count($suitesToRun), $totalDuration);
echo "========================================================================================\n\n";

exit($anyFailed ? 1 : 0);
