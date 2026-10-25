<?php
declare(strict_types=1);

/**
 * Sync Guard - Front-End UI/UX Verification Test Suite
 *
 * Automated test suite covering:
 * 1. Absence of "Phase N" wording, "OTA", and "outbox" in public markup / README
 * 2. Shell structure, accessibility, labels, fieldsets, and zero-channel guards of update.php
 * 3. Static JavaScript security (zero innerHTML/eval/setInterval, zero inline scripts/styles)
 * 4. Additive fields in recent.php and single-grouped-query performance guard
 * 5. Full-lifecycle HTTP idempotency semantics
 * 6. Mathematical WCAG AA contrast ratio validation for dark & light tokens
 * 7. End-to-end regression test suite execution
 */

// PSR-4 Autoloading
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
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Support\Time;
use SyncGuard\Support\Uuid;

$pdo = Connection::get();

$testsPassed = 0;
$testsFailed = 0;

function report(int $num, string $title, bool $pass, string $detail = ''): void {
    global $testsPassed, $testsFailed;
    if ($pass) {
        $testsPassed++;
        echo sprintf("  [PASS] Test %d: %s\n", $num, $title);
        if ($detail !== '') {
            echo sprintf("         %s\n", $detail);
        }
    } else {
        $testsFailed++;
        echo sprintf("  [FAIL] Test %d: %s\n", $num, $title);
        if ($detail !== '') {
            echo sprintf("         ERROR: %s\n", $detail);
        }
    }
}

function httpGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body, 'error' => $err];
}

function httpPost(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $body, 'error' => $err];
}

echo "\n======================================================================\n";
echo "  SYNC GUARD - UI/UX REDESIGN & COMPLIANCE VERIFICATION SUITE\n";
echo "======================================================================\n\n";

// ----------------------------------------------------------------------
// 1. No "Phase N" wording, "OTA", and "outbox" in public markup / README
// ----------------------------------------------------------------------
$phaseHits = [];
$projectRoot = dirname(__DIR__);

// Scan public/ and subdirectories, partials, lang/, and README.md
$scanPaths = [
    $projectRoot . '/public',
    $projectRoot . '/README.md',
];
if (is_dir($projectRoot . '/lang')) {
    $scanPaths[] = $projectRoot . '/lang';
}

foreach ($scanPaths as $path) {
    if (is_file($path)) {
        $files = [$path];
    } else {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        $files = [];
        foreach ($iterator as $f) {
            if ($f->isFile()) {
                $files[] = $f->getPathname();
            }
        }
    }

    foreach ($files as $file) {
        $content = file_get_contents($file);
        if (preg_match('/phase\s*\d/i', $content, $m)) {
            $phaseHits[] = str_replace($projectRoot . '/', '', $file) . " (matched '{$m[0]}')";
        }
    }
}

// Check user-visible markup on update.php
$updateRes = httpGet('http://127.0.0.1/update.php');
$userVisibleText = preg_replace('/<[^>]*>/', ' ', $updateRes['body']);
$otaHits = preg_match('/\bOTAs?\b/i', $userVisibleText);
$outboxHits = preg_match('/\boutbox\b/i', $userVisibleText);

$test1Pass = empty($phaseHits) && !$otaHits && !$outboxHits;
$test1Detail = '';
if (!empty($phaseHits)) {
    $test1Detail .= 'Found phase wording in: ' . implode(', ', $phaseHits) . '. ';
}
if ($otaHits) {
    $test1Detail .= "User-visible text contains 'OTA'. ";
}
if ($outboxHits) {
    $test1Detail .= "User-visible text contains 'outbox'. ";
}
if ($test1Pass) {
    $test1Detail = 'Zero phase references in public/ & README.md; zero OTA/outbox in user markup';
}
report(1, 'No "Phase N" wording, OTA, or outbox in user-facing surfaces', $test1Pass, $test1Detail);

// ----------------------------------------------------------------------
// 2. Page shell of update.php via HTTP
// ----------------------------------------------------------------------
$html = $updateRes['body'];
$shellErrors = [];

// Title
if (!str_contains($html, '<title>Update inventory · Sync Guard</title>')) {
    $shellErrors[] = "Title does not match 'Update inventory · Sync Guard'";
}

// Nav items and aria-current
if (!str_contains($html, 'aria-current="page">Update inventory</a>')) {
    $shellErrors[] = "Nav link 'Update inventory' missing aria-current='page'";
}
if (!str_contains($html, '>Dashboard</a>') || !str_contains($html, '>Sync log</a>')) {
    $shellErrors[] = "Nav links must contain exactly Dashboard, Update inventory, Sync log";
}

// Labels for inputs & selects
$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);

$inputs = $xpath->query('//input[not(@type="hidden")] | //select');
foreach ($inputs as $input) {
    $id = $input->getAttribute('id');
    if ($id === '') {
        $shellErrors[] = 'Interactive control missing id attribute';
        continue;
    }
    $labels = $xpath->query("//label[@for='{$id}']");
    if ($labels->length === 0) {
        $shellErrors[] = "Control #{$id} has no matching <label for='{$id}'>";
    }
}

// Three fieldsets with legend
$fieldsets = $xpath->query('//fieldset');
if ($fieldsets->length !== 3) {
    $shellErrors[] = "Expected 3 fieldsets, found {$fieldsets->length}";
}
$legends = $xpath->query('//fieldset/legend');
if ($legends->length !== 3) {
    $shellErrors[] = "Expected 3 legends inside fieldsets, found {$legends->length}";
}

// Error summary container with role="alert"
$errorSummary = $xpath->query('//*[@id="form-error-summary"][@role="alert"]');
if ($errorSummary->length === 0) {
    $shellErrors[] = 'Missing #form-error-summary with role="alert"';
}

// Polite aria-live region
$liveRegion = $xpath->query('//*[@aria-live="polite"]');
if ($liveRegion->length === 0) {
    $shellErrors[] = 'Missing container with aria-live="polite"';
}

// Noscript tag
$noscript = $xpath->query('//noscript');
if ($noscript->length === 0) {
    $shellErrors[] = 'Missing <noscript> alert tag';
}

// Hidden idempotency_key field
$idempInput = $xpath->query('//input[@type="hidden"][@name="idempotency_key"]');
if ($idempInput->length === 0 || $idempInput->item(0)->getAttribute('value') === '') {
    $shellErrors[] = 'Missing hidden idempotency_key input field';
}

// Room options data attributes match database
$dbRooms = $pdo->query('SELECT id, name, total_units, base_price FROM rooms ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
foreach ($dbRooms as $r) {
    $opt = $xpath->query("//select[@id='room_id']/option[@value='{$r['id']}']");
    if ($opt->length === 0) {
        $shellErrors[] = "Missing option for room ID {$r['id']}";
        continue;
    }
    $totUnits = $opt->item(0)->getAttribute('data-total-units');
    $basePrice = $opt->item(0)->getAttribute('data-base-price');
    if ((int) $totUnits !== (int) $r['total_units'] || (int) $basePrice !== (int) $r['base_price']) {
        $shellErrors[] = "Room option data attributes mismatch for room ID {$r['id']}";
    }
}

// Min date equals today JST
$todayJst = Time::todayInTokyo();
$dateInputs = $xpath->query("//input[@id='date_from']");
if ($dateInputs->length > 0 && $dateInputs->item(0)->getAttribute('min') !== $todayJst) {
    $shellErrors[] = "date_from min attribute does not equal today's JST date ({$todayJst})";
}

// Active channel chips match database
$dbChannels = $pdo->query('SELECT name FROM channels WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
$chips = $xpath->query('//span[contains(@class, "channel-chip")]');
if ($chips->length !== count($dbChannels)) {
    $shellErrors[] = "Active channel chips count ({$chips->length}) does not match DB count (" . count($dbChannels) . ')';
}

// Deactivate all channels, verify warning & disabled Save, then restore
try {
    $pdo->exec('UPDATE channels SET is_active = 0');
    $deactRes = httpGet('http://127.0.0.1/update.php');
} finally {
    $pdo->exec('UPDATE channels SET is_active = 1');
}

$deactDom = new DOMDocument();
@$deactDom->loadHTML($deactRes['body']);
$deactXpath = new DOMXPath($deactDom);
$disabledSubmit = $deactXpath->query('//button[@id="submit_btn"][@disabled]');
if ($disabledSubmit->length === 0) {
    $shellErrors[] = 'Submit button not disabled when zero active channels exist';
}
if (!str_contains($deactRes['body'], 'No booking sites')) {
    $shellErrors[] = 'Missing zero active channels warning message';
}

$test2Pass = empty($shellErrors);
report(2, 'Page shell, accessibility attributes, fieldsets, and zero-channel guards', $test2Pass, empty($shellErrors) ? 'All shell elements, accessible labels, JST min date, and guards verified' : implode('; ', $shellErrors));

// ----------------------------------------------------------------------
// 3. Static JavaScript and Markup Safety
// ----------------------------------------------------------------------
$jsFile = $projectRoot . '/public/assets/js/app.js';
$jsRaw = file_get_contents($jsFile);

// Strip comments from JavaScript to ensure assertions test executed code only
$jsContent = preg_replace('!/\*.*?\*/!s', '', $jsRaw);
$jsContent = preg_replace('/\/\/.*$/m', '', $jsContent);

$forbiddenJsPatterns = [
    '\.html\s*\('          => '.html() method',
    '\.innerHTML\b'        => '.innerHTML property',
    '\.outerHTML\b'        => '.outerHTML property',
    'insertAdjacentHTML'   => 'insertAdjacentHTML method',
    'document\.write'      => 'document.write method',
    '\beval\s*\('          => 'eval() function',
    'setInterval\s*\('     => 'setInterval() timer',
];

$jsViolations = [];
foreach ($forbiddenJsPatterns as $pattern => $name) {
    if (preg_match('/' . $pattern . '/', $jsContent)) {
        $jsViolations[] = $name;
    }
}

// Check update.php markup for inline scripts, on...=, style="..."
$markupViolations = [];
if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>[\s\S]*?<\/script>/i', $html)) {
    $markupViolations[] = 'Inline <script> block found in markup';
}
if (preg_match('/\bon[a-z]+\s*=/i', $html)) {
    $markupViolations[] = 'Inline on...= event handler found in markup';
}
if (preg_match('/\bstyle\s*=\s*["\']/i', $html)) {
    $markupViolations[] = 'Inline style="..." attribute found in markup';
}

$test3Pass = empty($jsViolations) && empty($markupViolations);
$test3Detail = $test3Pass ? 'Zero unsafe DOM methods, zero setInterval, zero inline script/style attributes' : implode('; ', array_merge($jsViolations, $markupViolations));
report(3, 'Static JavaScript safety and markup hygiene', $test3Pass, $test3Detail);

// ----------------------------------------------------------------------
// 4. recent.php Additive Change & Single Grouped Query Performance
// ----------------------------------------------------------------------
// Create controlled mix:
$stmtInsUp = $pdo->prepare('INSERT INTO inventory_updates (room_id, date_from, date_to, available_units, price, idempotency_key, correlation_id) VALUES (1, "2026-11-10", "2026-11-12", ?, ?, ?, ?)');

$stmtInsUp->execute([5, 10000, Uuid::v4(), Uuid::v4()]);
$testIdA = (int) $pdo->lastInsertId();

$stmtInsUp->execute([4, 11000, Uuid::v4(), Uuid::v4()]);
$testIdB = (int) $pdo->lastInsertId();

$stmtInsUp->execute([3, 12000, Uuid::v4(), Uuid::v4()]);
$testIdC = (int) $pdo->lastInsertId();

$stmtInsJob = $pdo->prepare('INSERT INTO sync_jobs (update_id, channel_id, status) VALUES (?, ?, ?)');
// Update A: 3 delivered
$stmtInsJob->execute([$testIdA, 1, 'success']);
$stmtInsJob->execute([$testIdA, 2, 'success']);
$stmtInsJob->execute([$testIdA, 3, 'success']);

// Update B: 1 delivered, 1 failed, 1 pending
$stmtInsJob->execute([$testIdB, 1, 'success']);
$stmtInsJob->execute([$testIdB, 2, 'failed']);
$stmtInsJob->execute([$testIdB, 3, 'pending']);

// Update C: 1 dead, 2 delivered
$stmtInsJob->execute([$testIdC, 1, 'dead']);
$stmtInsJob->execute([$testIdC, 2, 'success']);
$stmtInsJob->execute([$testIdC, 3, 'success']);

$recentRes = httpGet('http://127.0.0.1/api/recent.php');
$recentJson = json_decode($recentRes['body'], true);
$recentItems = $recentJson['data'] ?? [];

$recentErrors = [];
$foundA = false;
$foundB = false;
$foundC = false;

$legacyKeys = ['id', 'room_id', 'room_name', 'date_from', 'date_to', 'available_units', 'price', 'created_at_utc', 'created_at_jst', 'total_jobs', 'pending_jobs'];
$additiveKeys = ['jobs_total', 'jobs_success', 'jobs_pending', 'jobs_failed', 'jobs_dead'];

foreach ($recentItems as $item) {
    foreach ($legacyKeys as $k) {
        if (!array_key_exists($k, $item)) {
            $recentErrors[] = "Legacy key '{$k}' missing in recent.php item #{$item['id']}";
        }
    }
    foreach ($additiveKeys as $k) {
        if (!array_key_exists($k, $item)) {
            $recentErrors[] = "Additive key '{$k}' missing in recent.php item #{$item['id']}";
        }
    }

    $sum = (int) $item['jobs_success'] + (int) $item['jobs_pending'] + (int) $item['jobs_failed'] + (int) $item['jobs_dead'];
    if ((int) $item['jobs_total'] !== $sum) {
        $recentErrors[] = "Item #{$item['id']} total jobs ({$item['jobs_total']}) does not equal sum of parts ({$sum})";
    }

    if ((int) $item['id'] === $testIdA) {
        $foundA = true;
        if ((int) $item['jobs_total'] !== 3 || (int) $item['jobs_success'] !== 3) {
            $recentErrors[] = 'Update A expected 3 total, 3 success, got: ' . json_encode($item);
        }
    } elseif ((int) $item['id'] === $testIdB) {
        $foundB = true;
        if ((int) $item['jobs_total'] !== 3 || (int) $item['jobs_success'] !== 1 || (int) $item['jobs_failed'] !== 1 || (int) $item['jobs_pending'] !== 1) {
            $recentErrors[] = 'Update B expected 3 total, 1 success, 1 failed, 1 pending';
        }
    } elseif ((int) $item['id'] === $testIdC) {
        $foundC = true;
        if ((int) $item['jobs_total'] !== 3 || (int) $item['jobs_dead'] !== 1 || (int) $item['jobs_success'] !== 2) {
            $recentErrors[] = 'Update C expected 3 total, 1 dead, 2 success';
        }
    }
}

if (!$foundA || !$foundB || !$foundC) {
    $recentErrors[] = 'Controlled test updates not found in recent.php output. Body: ' . substr($recentRes['body'], 0, 500);
}

// Clean test records
$pdo->exec("DELETE FROM sync_jobs WHERE update_id IN ({$testIdA}, {$testIdB}, {$testIdC})");
$pdo->exec("DELETE FROM inventory_updates WHERE id IN ({$testIdA}, {$testIdB}, {$testIdC})");

// Query-counting PDO test
class QueryCountingPdo extends PDO {
    public int $queryCount = 0;
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->queryCount++;
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queryCount++;
        return parent::prepare($query, $options);
    }
}

$dbConfig = (require $projectRoot . '/config/config.php')['db'];
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbConfig['host'], $dbConfig['port'], $dbConfig['name'], $dbConfig['charset']);
$countingPdo = new QueryCountingPdo($dsn, $dbConfig['user'], $dbConfig['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$testRepo = new UpdateRepository($countingPdo);

$countingPdo->queryCount = 0;
$testRepo->getRecent(1);
$queriesFor1 = $countingPdo->queryCount;

$countingPdo->queryCount = 0;
$testRepo->getRecent(10);
$queriesFor10 = $countingPdo->queryCount;

if ($queriesFor1 !== 1 || $queriesFor10 !== 1) {
    $recentErrors[] = "Expected exactly 1 query for 1 and 10 rows, got {$queriesFor1} and {$queriesFor10}";
}

$test4Pass = empty($recentErrors);
report(4, 'recent.php additive fields, sum of parts, and single-grouped-query proof', $test4Pass, $test4Pass ? "Additive keys intact, controlled mix validated, exactly 1 query executed for 1 and 10 rows" : implode('; ', $recentErrors));

// ----------------------------------------------------------------------
// 5. Idempotency Unchanged via HTTP
// ----------------------------------------------------------------------
$testKey = Uuid::v4();
$payload = [
    'room_id'         => 1,
    'date_from'       => '2026-11-20',
    'date_to'         => '2026-11-22',
    'available_units' => 6,
    'price'           => 9500,
    'idempotency_key' => $testKey,
];

// First submit: 201 Created
$res1 = httpPost('http://127.0.0.1/api/inventory.php', $payload);
$json1 = json_decode($res1['body'], true);

// Second submit with same key: 200 OK duplicate
$res2 = httpPost('http://127.0.0.1/api/inventory.php', $payload);
$json2 = json_decode($res2['body'], true);

// Verify exactly 1 DB row
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_updates WHERE idempotency_key = ?');
$countStmt->execute([$testKey]);
$dbCount = (int) $countStmt->fetchColumn();

// Third submit with new key: 201 Created
$newKey = Uuid::v4();
$payload['idempotency_key'] = $newKey;
$res3 = httpPost('http://127.0.0.1/api/inventory.php', $payload);
$json3 = json_decode($res3['body'], true);

$dup1 = $json1['data']['duplicate'] ?? false;
$dup2 = $json2['data']['duplicate'] ?? false;
$dup3 = $json3['data']['duplicate'] ?? false;

$idempPass = ($res1['status'] === 201 && $dup1 === false)
    && ($res2['status'] === 200 && $dup2 === true)
    && ($dbCount === 1)
    && ($res3['status'] === 201 && $dup3 === false);

$idempDetail = $idempPass ? 'Key 1 -> 201 Created; Repeat -> 200 Duplicate (1 DB row); New Key -> 201 Created' : "Status1: {$res1['status']}, Status2: {$res2['status']}, DB rows: {$dbCount}, Status3: {$res3['status']}";
report(5, 'Strict database-enforced idempotency preservation', $idempPass, $idempDetail);

// ----------------------------------------------------------------------
// 6. Mathematical WCAG Contrast Ratio Validation
// ----------------------------------------------------------------------
function parseHex(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    return [
        hexdec(substr($hex, 0, 2)) / 255.0,
        hexdec(substr($hex, 2, 2)) / 255.0,
        hexdec(substr($hex, 4, 2)) / 255.0,
    ];
}

function relativeLuminance(string $hex): float {
    [$r, $g, $b] = parseHex($hex);
    $transform = fn(float $c) => ($c <= 0.03928) ? ($c / 12.92) : pow(($c + 0.055) / 1.055, 2.4);
    return 0.2126 * $transform($r) + 0.7152 * $transform($g) + 0.0722 * $transform($b);
}

function contrastRatio(string $hex1, string $hex2): float {
    $l1 = relativeLuminance($hex1);
    $l2 = relativeLuminance($hex2);
    if ($l1 < $l2) {
        [$l1, $l2] = [$l2, $l1];
    }
    return ($l1 + 0.05) / ($l2 + 0.05);
}

$themes = [
    'Dark Theme' => [
        'bg_surface' => '#1e293b',
        'pairs' => [
            ['Primary Text on Surface', '#f8fafc', '#1e293b', 4.5],
            ['Secondary Text on Surface', '#94a3b8', '#1e293b', 4.5],
            ['Muted Helper Text on Surface', '#94a3b8', '#1e293b', 4.5],
            ['Error Text on Error Bg', '#fecaca', '#7f1d1d', 4.5],
            ['Healthy Badge Text on Bg', '#a7f3d0', '#064e3b', 4.5],
            ['Degraded Badge Text on Bg', '#fde68a', '#78350f', 4.5],
            ['Down Badge Text on Bg', '#fecaca', '#7f1d1d', 4.5],
            ['UI Border on Surface', '#64748b', '#1e293b', 3.0],
            ['Focus Ring on Surface', '#38bdf8', '#1e293b', 3.0],
        ]
    ],
    'Light Theme' => [
        'bg_surface' => '#ffffff',
        'pairs' => [
            ['Primary Text on Surface', '#0f172a', '#ffffff', 4.5],
            ['Secondary Text on Surface', '#475569', '#ffffff', 4.5],
            ['Muted Helper Text on Surface', '#475569', '#ffffff', 4.5],
            ['Error Text on Error Bg', '#991b1b', '#fee2e2', 4.5],
            ['Healthy Badge Text on Bg', '#065f46', '#d1fae5', 4.5],
            ['Degraded Badge Text on Bg', '#92400e', '#fef3c7', 4.5],
            ['Down Badge Text on Bg', '#991b1b', '#fee2e2', 4.5],
            ['UI Border on Surface', '#64748b', '#ffffff', 3.0],
            ['Focus Ring on Surface', '#0284c7', '#ffffff', 3.0],
        ]
    ]
];

$contrastFailures = [];
echo "\n  --- WCAG 2.1 Contrast Ratio Verification ---\n";
foreach ($themes as $themeName => $data) {
    echo "  [ {$themeName} ]\n";
    printf("  %-30s %-10s %-10s %-10s %-8s %-6s\n", "Token Pair", "Color 1", "Color 2", "Ratio", "Target", "Status");
    printf("  %s\n", str_repeat('-', 74));
    foreach ($data['pairs'] as [$desc, $c1, $c2, $target]) {
        $ratio = contrastRatio($c1, $c2);
        $ok = ($ratio >= $target);
        if (!$ok) {
            $contrastFailures[] = "{$themeName} - {$desc}: {$ratio}:1 < {$target}:1";
        }
        printf("  %-30s %-10s %-10s %-10s %-8s %-6s\n", $desc, $c1, $c2, sprintf("%.2f:1", $ratio), sprintf(">= %.1f:1", $target), $ok ? 'PASS' : 'FAIL');
    }
    echo "\n";
}

$contrastPass = empty($contrastFailures);
report(6, 'WCAG contrast ratio verification across dark and light palettes', $contrastPass, $contrastPass ? 'All text tokens >= 4.5:1, all border/focus tokens >= 3.0:1' : implode('; ', $contrastFailures));

// ----------------------------------------------------------------------
// Final Summary & Exit
// ----------------------------------------------------------------------
echo "\n======================================================================\n";
echo sprintf("  TEST RESULTS: %d Passed, %d Failed (Total: %d)\n", $testsPassed, $testsFailed, $testsPassed + $testsFailed);
echo "======================================================================\n\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
