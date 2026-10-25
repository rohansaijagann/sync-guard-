<?php
declare(strict_types=1);

/**
 * Sync Guard - Mock OTA Chaos & Control CLI Utility
 *
 * Controls operational failure modes and latency simulations for Mock Sites B and C.
 * Uses shared SyncGuard\Support\MockControl.
 *
 * Usage:
 *   php bin/mock_control.php status
 *   php bin/mock_control.php set site_b <normal|fast|slow|down|reject>
 *   php bin/mock_control.php set site_c <random|always_succeed|always_fail|always_reject|forget>
 *   php bin/mock_control.php reset
 *   php bin/mock_control.php clear
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: bin/mock_control.php must be run from the CLI.\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');
if (strtolower($appEnv) !== 'local') {
    fwrite(STDERR, sprintf("[ERROR] mock_control is only allowed in 'local' environment (current: '%s').\n", $appEnv));
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

use SyncGuard\Support\MockControl;

$controlFile = MockControl::getControlFile();
$allowedModes = MockControl::ALLOWED_MODES;
$defaultState = MockControl::DEFAULT_STATE;

$action = $argv[1] ?? 'status';

switch ($action) {
    case 'status':
        $state = MockControl::read();
        echo "====================================================\n";
        echo "           Mock OTA Simulation Status               \n";
        echo "====================================================\n";
        echo sprintf("Site B Mode : %s (Allowed: %s)\n", $state['site_b'], implode(', ', $allowedModes['site_b']));
        echo sprintf("Site C Mode : %s (Allowed: %s)\n", $state['site_c'], implode(', ', $allowedModes['site_c']));
        echo "Control file: {$controlFile}\n";
        exit(0);

    case 'set':
        $site = strtolower($argv[2] ?? '');
        $mode = strtolower($argv[3] ?? '');

        if (!isset($allowedModes[$site])) {
            fwrite(STDERR, sprintf("Error: Unknown site '%s'. Allowed sites: %s\n", $site, implode(', ', array_keys($allowedModes))));
            exit(1);
        }

        if (!in_array($mode, $allowedModes[$site], true)) {
            fwrite(STDERR, sprintf("Error: Invalid mode '%s' for %s. Allowed modes: %s\n", $mode, $site, implode(', ', $allowedModes[$site])));
            exit(1);
        }

        if (!MockControl::setMode($site, $mode)) {
            fwrite(STDERR, "Error: Failed to write control file.\n");
            exit(1);
        }

        echo sprintf("[OK] Set %s mode to '%s'.\n", $site, $mode);
        exit(0);

    case 'reset':
        if (!MockControl::reset()) {
            fwrite(STDERR, "Error: Failed to reset control file.\n");
            exit(1);
        }
        echo "[OK] Mock controls reset to defaults (Site B: normal, Site C: random).\n";
        exit(0);

    case 'clear':
        $storageDir = MockControl::getStorageDir();
        $mockFiles = [
            $storageDir . '/site_a.json',
            $storageDir . '/site_b.json',
            $storageDir . '/site_c.json',
        ];
        $cleared = 0;
        foreach ($mockFiles as $f) {
            if (file_exists($f)) {
                @unlink($f);
                $cleared++;
            }
        }
        echo sprintf("[OK] Cleared %d mock partner storage files.\n", $cleared);
        exit(0);

    default:
        fwrite(STDERR, "Usage: php bin/mock_control.php <status|set <site> <mode>|reset|clear>\n");
        exit(1);
}
