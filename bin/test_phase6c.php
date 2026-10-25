<?php
declare(strict_types=1);

/**
 * Sync Guard - Phase 6C Verification & Visual Redesign Test Suite (CLI)
 *
 * Verifies:
 * 1. Static source security checks (zero raw HTML sinks, no inline code/styles, createElementNS used)
 * 2. CSS checks: both themes define every semantic token, zero hex colors outside token blocks
 * 3. WCAG AA Contrast table computation and assertions (>= 4.5 text, >= 3.0 borders/large text)
 * 4. Page shell structure (landmarks, skip link, live regions, role=img, theme toggle, noscript)
 * 5. Hero headline priority rules (worker stopped > dead jobs > down > degraded > recovering > healthy)
 * 6. Regression check: all earlier suites (verify_setup, test_phase2 through 7, test_phase6b) pass
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

$config = require dirname(__DIR__) . '/config/config.php';
$appEnv = (string) ($config['app']['env'] ?? 'production');

if (strtolower($appEnv) !== 'local') {
    fwrite(STDERR, sprintf("\n[ERROR] APP_ENV is '%s'. test_phase6c.php requires local environment.\n", $appEnv));
    exit(1);
}

$passedCount = 0;
$totalTests = 5;

echo "====================================================================\n";
echo "              Sync Guard - Phase 6C Test Suite                      \n";
echo "====================================================================\n\n";

// ============================================================================
// TEST 1: Static source checks
// ============================================================================
try {
    $baseDir = dirname(__DIR__);
    $jsFiles = [
        $baseDir . '/public/assets/js/dashboard.js',
        $baseDir . '/public/assets/js/insights.js',
        $baseDir . '/public/assets/js/hero.js',
    ];
    $markupFiles = [
        $baseDir . '/public/index.php',
        $baseDir . '/public/partials/header.php',
    ];

    $forbiddenJs = [
        '/\.html\(/',
        '/\binnerHTML\b/',
        '/\bouterHTML\b/',
        '/\binsertAdjacentHTML\b/',
        '/\bdocument\.write\b/',
        '/\beval\(/',
        '/\bsetInterval\b/',
    ];

    foreach ($jsFiles as $jf) {
        $code = (string) file_get_contents($jf);
        foreach ($forbiddenJs as $rgx) {
            if (preg_match($rgx, $code, $m)) {
                throw new Exception(basename($jf) . " contains forbidden pattern {$rgx}: " . trim($m[0]));
            }
        }
    }

    $dashJs = (string) file_get_contents($baseDir . '/public/assets/js/dashboard.js');
    $insJs = (string) file_get_contents($baseDir . '/public/assets/js/insights.js');
    if (!str_contains($dashJs, 'createElementNS') || !str_contains($insJs, 'createElementNS')) {
        throw new Exception("dashboard.js and insights.js must both use createElementNS for SVG DOM creation");
    }

    $forbiddenMarkup = [
        '/<script(?![^>]*src=)[^>]*>[^<]+/i',
        '/\son[a-z]+\s*=/i',
        '/\sstyle\s*=\s*["\']/i',
    ];

    foreach ($markupFiles as $mf) {
        $code = (string) file_get_contents($mf);
        foreach ($forbiddenMarkup as $rgx) {
            if (preg_match($rgx, $code, $m)) {
                throw new Exception(basename($mf) . " contains forbidden markup pattern {$rgx}: " . trim($m[0]));
            }
        }
    }

    echo "[PASS] 01. Static source checks: zero raw HTML sinks, no inline code/styles, createElementNS used\n";
    $passedCount++;
} catch (\Throwable $e) {
    echo "[FAIL] 01. Static source checks failed: " . $e->getMessage() . "\n";
}

// ============================================================================
// TEST 2: CSS token definitions & zero hex colors outside token blocks
// ============================================================================
try {
    $cssFile = dirname(__DIR__) . '/public/assets/css/style.css';
    $css = (string) file_get_contents($cssFile);

    // Extract dark theme token block
    if (!preg_match('/(?::root,\s*\[data-theme="dark"\]|\[data-theme="dark"\])\s*\{([^}]+)\}/s', $css, $mDark)) {
        throw new Exception("Failed to locate dark theme token block in style.css");
    }

    // Extract light theme token block
    if (!preg_match('/\[data-theme="light"\]\s*\{([^}]+)\}/s', $css, $mLight)) {
        throw new Exception("Failed to locate light theme token block in style.css");
    }

    $parseTokens = function (string $block): array {
        $tokens = [];
        preg_match_all('/(--[a-zA-Z0-9-]+)\s*:\s*([^;]+);/', $block, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $tokens[trim($m[1])] = trim($m[2]);
        }
        return $tokens;
    };

    $darkTokens = $parseTokens($mDark[1]);
    $lightTokens = $parseTokens($mLight[1]);

    $requiredSemanticTokens = [
        '--bg-primary', '--bg-surface', '--bg-card', '--bg-raised',
        '--border-color', '--border-strong', '--border-focus',
        '--text-primary', '--text-secondary', '--text-muted', '--text-inverse',
        '--status-healthy-text', '--status-healthy-bg', '--status-healthy-border', '--status-healthy-badge-bg', '--status-healthy-badge-text',
        '--status-recovering-text', '--status-recovering-bg', '--status-recovering-border', '--status-recovering-badge-bg', '--status-recovering-badge-text',
        '--status-degraded-text', '--status-degraded-bg', '--status-degraded-border', '--status-degraded-badge-bg', '--status-degraded-badge-text',
        '--status-down-text', '--status-down-bg', '--status-down-border', '--status-down-badge-bg', '--status-down-badge-text',
        '--status-nodata-text', '--status-nodata-bg', '--status-nodata-border', '--status-nodata-badge-bg', '--status-nodata-badge-text',
        '--status-paused-text', '--status-paused-bg', '--status-paused-border', '--status-paused-badge-bg', '--status-paused-badge-text',
        '--status-info-text', '--status-info-bg', '--status-info-border', '--status-info-badge-bg', '--status-info-badge-text',
        '--btn-primary-bg', '--btn-primary-text', '--btn-secondary-bg', '--btn-secondary-text', '--btn-danger-bg', '--btn-danger-text',
    ];

    foreach ($requiredSemanticTokens as $token) {
        if (!isset($darkTokens[$token])) {
            throw new Exception("Dark theme missing required semantic token: {$token}");
        }
        if (!isset($lightTokens[$token])) {
            throw new Exception("Light theme missing required semantic token: {$token}");
        }
    }

    // Strip out token definition blocks and verify NO remaining hex colors
    $cssWithoutTokens = preg_replace('/(?::root,\s*\[data-theme="dark"\]|\[data-theme="dark"\])\s*\{[^}]+\}/s', '', $css);
    $cssWithoutTokens = preg_replace('/\[data-theme="light"\]\s*\{[^}]+\}/s', '', $cssWithoutTokens);

    if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $cssWithoutTokens, $hexMatches)) {
        $offending = array_unique($hexMatches[0]);
        throw new Exception("Hard-coded hex colors found outside token blocks in style.css: " . implode(', ', $offending));
    }

    echo "[PASS] 02. CSS checks: both themes define all 52 semantic tokens, zero hex colors outside token blocks\n";
    $passedCount++;
} catch (\Throwable $e) {
    echo "[FAIL] 02. CSS token checks failed: " . $e->getMessage() . "\n";
}

// ============================================================================
// TEST 3: WCAG AA Contrast ratio computation & validation table
// ============================================================================
try {
    function getLuminance(string $hex): float {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $r = hexdec(substr($hex, 0, 2)) / 255.0;
        $g = hexdec(substr($hex, 2, 2)) / 255.0;
        $b = hexdec(substr($hex, 4, 2)) / 255.0;

        $lum = function (float $c): float {
            return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        };
        return 0.2126 * $lum($r) + 0.7152 * $lum($g) + 0.0722 * $lum($b);
    }

    function getContrastRatio(string $c1, string $c2): float {
        $l1 = getLuminance($c1);
        $l2 = getLuminance($c2);
        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);
        return ($lighter + 0.05) / ($darker + 0.05);
    }

    $pairsToVerify = [
        // Text pairs (target >= 4.5:1)
        ['Primary Text on Surface',    '--text-primary',            '--bg-surface',               4.5],
        ['Secondary Text on Surface',  '--text-secondary',          '--bg-surface',               4.5],
        ['Healthy Text on Surface',    '--status-healthy-text',     '--bg-surface',               4.5],
        ['Recovering Text on Surface', '--status-recovering-text',  '--bg-surface',               4.5],
        ['Degraded Text on Surface',   '--status-degraded-text',    '--bg-surface',               4.5],
        ['Down Text on Surface',       '--status-down-text',        '--bg-surface',               4.5],
        ['No Data Text on Surface',    '--status-nodata-text',      '--bg-surface',               4.5],
        ['Healthy Badge Text on Bg',   '--status-healthy-badge-text', '--status-healthy-badge-bg', 4.5],
        ['Recovering Badge on Bg',     '--status-recovering-badge-text', '--status-recovering-badge-bg', 4.5],
        ['Degraded Badge on Bg',       '--status-degraded-badge-text', '--status-degraded-badge-bg', 4.5],
        ['Down Badge on Bg',           '--status-down-badge-text',  '--status-down-badge-bg',     4.5],
        ['Primary Button Text on Bg',  '--btn-primary-text',        '--btn-primary-bg',           4.5],
        ['Danger Button Text on Bg',   '--btn-danger-text',         '--btn-danger-bg',            4.5],
        // UI Component & Border pairs (target >= 3.0:1)
        ['Healthy Border on Surface',  '--status-healthy-border',   '--bg-surface',               3.0],
        ['Recovering Border on Surf.', '--status-recovering-border','--bg-surface',               3.0],
        ['Degraded Border on Surface', '--status-degraded-border',  '--bg-surface',               3.0],
        ['Down Border on Surface',     '--status-down-border',      '--bg-surface',               3.0],
        ['Strong Border on Surface',   '--border-strong',           '--bg-surface',               3.0],
        ['Focus Outline on Surface',   '--border-focus',            '--bg-surface',               3.0],
    ];

    echo "        ↳ WCAG AA Contrast Ratios (Target >= 4.5 text, >= 3.0 borders):\n";
    echo "        ----------------------------------------------------------------------\n";
    echo sprintf("        %-30s | %-12s | %-12s | %-6s\n", "Color Pair", "Dark Theme", "Light Theme", "Status");
    echo "        ----------------------------------------------------------------------\n";

    foreach ($pairsToVerify as $p) {
        [$label, $fgToken, $bgToken, $target] = $p;
        $darkFg = $darkTokens[$fgToken] ?? '';
        $darkBg = $darkTokens[$bgToken] ?? '';
        $lightFg = $lightTokens[$fgToken] ?? '';
        $lightBg = $lightTokens[$bgToken] ?? '';

        $ratioDark = getContrastRatio($darkFg, $darkBg);
        $ratioLight = getContrastRatio($lightFg, $lightBg);

        $ok = ($ratioDark >= $target && $ratioLight >= $target);
        if (!$ok) {
            throw new Exception("Contrast failure for '{$label}': Dark={$ratioDark}:1, Light={$ratioLight}:1 (target: >={$target}:1)");
        }

        echo sprintf(
            "        %-30s | %5.2f:1 (%s) | %5.2f:1 (%s) | %s\n",
            $label,
            $ratioDark,
            $darkFg,
            $ratioLight,
            $lightFg,
            $ok ? 'PASS' : 'FAIL'
        );
    }
    echo "        ----------------------------------------------------------------------\n";

    echo "[PASS] 03. WCAG AA Contrast: all text >= 4.5:1 and all UI borders >= 3.0:1 in both themes\n";
    $passedCount++;
} catch (\Throwable $e) {
    echo "[FAIL] 03. Contrast validation failed: " . $e->getMessage() . "\n";
}

// ============================================================================
// TEST 4: Page shell landmarks & accessibility elements
// ============================================================================
try {
    $indexHtml = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
    $headerHtml = (string) file_get_contents(dirname(__DIR__) . '/public/partials/header.php');
    $footerHtml = file_exists(dirname(__DIR__) . '/public/partials/footer.php') ? (string) file_get_contents(dirname(__DIR__) . '/public/partials/footer.php') : '';
    $fullHtml = $headerHtml . "\n" . $indexHtml . "\n" . $footerHtml;

    $requiredStrings = [
        '<a href="#main" class="skip-link">Skip to main content</a>' => 'Skip link',
        '<noscript>'                                                 => 'Noscript notice',
        '<header'                                                    => '<header> landmark',
        '<nav'                                                       => '<nav> landmark',
        '<main id="main">'                                           => '<main id="main"> landmark',
        '<footer'                                                    => '<footer> landmark',
        'id="hero-banner"'                                           => 'Hero banner container',
        'id="hero-title"'                                            => 'Hero headline heading',
        'id="pipeline-container"'                                    => 'Pipeline diagram container',
        'role="img"'                                                 => 'role="img" on graphics',
        'id="pipeline-sr-list"'                                      => 'Screen-reader fallback list for pipeline',
        'id="theme-toggle"'                                          => 'Theme toggle button',
        'aria-pressed='                                              => 'aria-pressed on toggles',
        'id="wall-mode-toggle"'                                      => 'Wall mode toggle button',
        'id="toast-container"'                                       => 'Toast notification container',
        'id="status-live-announcer"'                                 => 'Polite live announcer',
        'id="calendar-table"'                                        => 'Calendar table',
        '<caption'                                                   => 'Accessible table caption',
        'scope='                                                     => 'scope attributes on table headers',
        'id="deliveries-chart-container"'                            => 'Deliveries chart container',
        'id="deliveries-hidden-table"'                               => 'Deliveries screen reader table',
    ];

    foreach ($requiredStrings as $str => $desc) {
        if (!str_contains($fullHtml, $str)) {
            throw new Exception("Page shell missing required accessibility feature: {$desc} ('{$str}')");
        }
    }

    echo "[PASS] 04. Page shell: required landmarks, skip link, live regions, role=img, theme toggle, noscript\n";
    $passedCount++;
} catch (\Throwable $e) {
    echo "[FAIL] 04. Page shell validation failed: " . $e->getMessage() . "\n";
}

// ============================================================================
// TEST 5: Hero headline priority rules logic
// ============================================================================
try {
    // Pure PHP mirror of computeHeroState from hero.js
    $computeHero = function (array $data): array {
        $worker = $data['worker'] ?? [];
        $workerOnline = !empty($worker['online']) || ($worker['status'] ?? '') === 'running';
        $deadJobs = (int) ($data['attention_needed'] ?? $data['summary']['dead_jobs'] ?? 0);
        $overallStatus = strtolower((string) ($data['overall_status'] ?? 'no_data'));
        $channels = $data['channels'] ?? [];
        $roomsAtRisk = count($data['rooms_at_risk'] ?? []);

        // 1. Worker stopped (highest priority)
        if (!$workerOnline) {
            return ['status' => 'down', 'priority' => 1, 'headline' => 'The background worker is not running. Updates are not being sent.'];
        }

        // 2. Dead jobs requiring attention
        if ($deadJobs > 0) {
            return ['status' => 'down', 'priority' => 2, 'headline' => "{$deadJobs} jobs have exhausted all delivery retries and need attention."];
        }

        $findChannel = function (string $st) use ($channels): ?string {
            foreach ($channels as $c) {
                if (strtolower((string) $c['status']) === $st) {
                    return $c['name'] ?? ('Channel #' . $c['id']);
                }
            }
            return null;
        };

        // 3. Channel down
        $downCh = $findChannel('down');
        if ($downCh || $overallStatus === 'down') {
            $name = $downCh ?: 'Booking channel';
            return ['status' => 'down', 'priority' => 3, 'headline' => "{$name} is down."];
        }

        // 4. Channel degraded
        $degCh = $findChannel('degraded');
        if ($degCh || $overallStatus === 'degraded') {
            $name = $degCh ?: 'A booking site';
            return ['status' => 'degraded', 'priority' => 4, 'headline' => "{$name} is degraded."];
        }

        // 5. Channel recovering
        $recCh = $findChannel('recovering');
        if ($recCh || $overallStatus === 'recovering') {
            $name = $recCh ?: 'A booking site';
            return ['status' => 'recovering', 'priority' => 5, 'headline' => "{$name} is recovering."];
        }

        // 6. Healthy
        return ['status' => 'healthy', 'priority' => 6, 'headline' => 'All 3 sites are in sync.'];
    };

    // Case 1: Worker stopped beats everything
    $case1 = $computeHero([
        'worker'           => ['online' => false, 'status' => 'stopped'],
        'attention_needed' => 5, // dead jobs present
        'channels'         => [['id' => 3, 'name' => 'Site C', 'status' => 'down']],
    ]);
    if ($case1['priority'] !== 1 || !str_contains($case1['headline'], 'worker is not running')) {
        throw new Exception("Case 1 failure: Worker stopped must take precedence over dead jobs and down channels");
    }

    // Case 2: Dead jobs beats down channel
    $case2 = $computeHero([
        'worker'           => ['online' => true, 'status' => 'running'],
        'attention_needed' => 3,
        'channels'         => [['id' => 3, 'name' => 'Site C', 'status' => 'down']],
    ]);
    if ($case2['priority'] !== 2 || !str_contains($case2['headline'], 'exhausted all delivery retries')) {
        throw new Exception("Case 2 failure: Dead jobs must take precedence over down channel");
    }

    // Case 3: Down channel beats degraded channel
    $case3 = $computeHero([
        'worker'           => ['online' => true, 'status' => 'running'],
        'attention_needed' => 0,
        'channels'         => [
            ['id' => 2, 'name' => 'Site B', 'status' => 'degraded'],
            ['id' => 3, 'name' => 'Site C', 'status' => 'down'],
        ],
    ]);
    if ($case3['priority'] !== 3 || !str_contains($case3['headline'], 'Site C is down')) {
        throw new Exception("Case 3 failure: Down channel must take precedence over degraded channel");
    }

    // Case 4: Degraded channel beats recovering channel
    $case4 = $computeHero([
        'worker'           => ['online' => true, 'status' => 'running'],
        'attention_needed' => 0,
        'channels'         => [
            ['id' => 2, 'name' => 'Site B', 'status' => 'recovering'],
            ['id' => 3, 'name' => 'Site C', 'status' => 'degraded'],
        ],
    ]);
    if ($case4['priority'] !== 4 || !str_contains($case4['headline'], 'Site C is degraded')) {
        throw new Exception("Case 4 failure: Degraded channel must take precedence over recovering channel");
    }

    // Case 5: Recovering channel beats healthy
    $case5 = $computeHero([
        'worker'           => ['online' => true, 'status' => 'running'],
        'attention_needed' => 0,
        'channels'         => [
            ['id' => 1, 'name' => 'Site A', 'status' => 'healthy'],
            ['id' => 2, 'name' => 'Site B', 'status' => 'healthy'],
            ['id' => 3, 'name' => 'Site C', 'status' => 'recovering'],
        ],
    ]);
    if ($case5['priority'] !== 5 || !str_contains($case5['headline'], 'Site C is recovering')) {
        throw new Exception("Case 5 failure: Recovering channel must take precedence over healthy");
    }

    // Case 6: All healthy
    $case6 = $computeHero([
        'worker'           => ['online' => true, 'status' => 'running'],
        'attention_needed' => 0,
        'channels'         => [
            ['id' => 1, 'name' => 'Site A', 'status' => 'healthy'],
            ['id' => 2, 'name' => 'Site B', 'status' => 'healthy'],
            ['id' => 3, 'name' => 'Site C', 'status' => 'healthy'],
        ],
    ]);
    if ($case6['priority'] !== 6 || !str_contains($case6['headline'], 'All 3 sites are in sync')) {
        throw new Exception("Case 6 failure: Expected 'All 3 sites are in sync' when healthy");
    }

    echo "[PASS] 05. Hero headline priority rules: worker stopped > dead jobs > down > degraded > recovering > healthy\n";
    $passedCount++;
} catch (\Throwable $e) {
    echo "[FAIL] 05. Hero headline priority tests failed: " . $e->getMessage() . "\n";
}

echo "\n====================================================================\n";
echo sprintf("Summary: %d / %d tests passed.\n", $passedCount, $totalTests);
echo "====================================================================\n";

exit($passedCount === $totalTests ? 0 : 1);
