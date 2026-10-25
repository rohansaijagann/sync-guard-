<?php
declare(strict_types=1);

/**
 * Sync Guard - Mock Booking Partner Console View Partial
 *
 * Renders an operational console view for simulated OTA partners (Site A, Site B, Site C).
 * Fully aligned with the design language of index.php, update.php, and log.php.
 *
 * @var string $siteId           'site_a' | 'site_b' | 'site_c'
 * @var string $siteName         'Site A' | 'Site B' | 'Site C'
 * @var string $siteLabel        'Fast and reliable' | 'Slow on purpose' | 'Unreliable on purpose'
 * @var string $accentClass      'site-theme-a' | 'site-theme-b' | 'site-theme-c'
 * @var string $currentMode      Current operational simulation mode
 * @var string $modeExplanation  Plain-English explanation of mode behavior
 * @var int    $receivedCount    Total updates received
 * @var int    $duplicateCount   Total duplicate hits rejected
 * @var int|null $forgottenCount Total silent drops (Site C only)
 * @var array  $recentUpdates    Last 20 updates
 * @var bool   $isFailingMode    Whether current mode represents down/failing state
 * @var array  $behaviorBullets  3 bullet points describing site behavior
 */

$currentPage = 'mock';
$isMockPage = true;

// Helper to escape HTML safely
$esc = static function (?string $val): string {
    return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

// Rooms map fallback for Japanese room labels
$roomNames = [
    1 => 'シングルルーム (Single Room)',
    2 => 'ツインルーム (Twin Room)',
    3 => '和室 (Japanese-style Room)',
    4 => 'スイート (Suite)',
];

// Determine last received relative text
$lastReceivedText = 'Never';
if (!empty($recentUpdates)) {
    $latest = $recentUpdates[0];
    $latestTs = strtotime((string) ($latest['received_at'] ?? ''));
    if ($latestTs !== false) {
        $sec = max(0, time() - $latestTs);
        if ($sec < 60) {
            $lastReceivedText = $sec . 's ago';
        } elseif ($sec < 3600) {
            $lastReceivedText = floor($sec / 60) . ' min ago';
        } elseif ($sec < 86400) {
            $lastReceivedText = floor($sec / 3600) . ' hr ago';
        } else {
            $lastReceivedText = floor($sec / 86400) . ' d ago';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Sync Guard simulated booking partner console for <?= $esc($siteName) ?>">
    <title><?= $esc($siteName) ?> (Mock Booking Partner) - Sync Guard</title>

    <!-- Design System Stylesheet & Scoped Mock Theme -->
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/style.css') ?>">
    <link rel="stylesheet" href="/assets/css/mock.css?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/css/mock.css') ?>">
</head>
<body class="<?= $esc($accentClass) ?>">
    <!-- Skip to main content -->
    <a href="#main-content" class="skip-link">Skip to main content</a>

    <div class="container">
        <!-- Shared Header Partial -->
        <?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>

        <main id="main-content">
            <!-- 1. Hero Banner -->
            <section class="mock-hero-banner" aria-labelledby="mock-hero-title">
                <div class="mock-hero-header">
                    <div class="mock-title-row">
                        <h1 id="mock-hero-title" class="mock-site-title"><?= $esc($siteName) ?></h1>
                        <span class="badge badge-neutral">Mock booking site</span>
                        <span class="mock-personality-badge"><?= $esc($siteLabel) ?></span>
                    </div>

                    <div class="mock-mode-meta">
                        <span class="mock-mode-badge badge <?= $isFailingMode ? 'badge-danger' : 'badge-healthy' ?>">
                            <span class="badge-icon" aria-hidden="true"><?= $isFailingMode ? '▲' : '●' ?></span>
                            <span id="current-mode-badge" class="badge-text"><?= $esc($currentMode) ?></span>
                        </span>
                        <span class="mock-mode-explanation"><?= $esc($modeExplanation) ?></span>
                    </div>
                </div>

                <div class="mock-hero-actions">
                    <div class="live-indicator" aria-live="polite">
                        <span class="live-pulse-dot" aria-hidden="true"></span>
                        <span id="live-seconds-text">Live &middot; Updated 0s ago</span>
                        <span id="live-stale-notice" class="live-stale-notice u-hidden" role="status">May be out of date</span>
                    </div>
                    <a href="/" class="btn btn-sm btn-secondary">&larr; Back to dashboard</a>
                </div>
            </section>

            <!-- 2. Pipeline Flow Diagram -->
            <section class="mock-flow-card" aria-label="Delivery connection status">
                <span class="mock-flow-label">Delivery Pipeline Simulation</span>
                <svg class="mock-flow-svg" viewBox="0 0 340 40" fill="none" role="img" aria-label="Delivery pipeline from Sync Guard worker to <?= $esc($siteName) ?>: <?= $isFailingMode ? 'Failing connection' : 'Healthy connection' ?>">
                    <rect x="2" y="5" width="100" height="30" rx="4" fill="var(--bg-raised)" stroke="var(--border-color)" stroke-width="1.5" />
                    <text x="52" y="24" fill="var(--text-primary)" font-size="11" font-weight="600" text-anchor="middle" font-family="inherit">Sync Worker</text>
                    <line x1="104" y1="20" x2="226" y2="20" stroke="<?= $isFailingMode ? 'var(--status-down-text)' : 'var(--mock-accent)' ?>" stroke-width="2" <?= $isFailingMode ? 'stroke-dasharray="4 4"' : '' ?> />
                    <polygon points="228,20 220,16 220,24" fill="<?= $isFailingMode ? 'var(--status-down-text)' : 'var(--mock-accent)' ?>" />
                    <rect x="230" y="5" width="108" height="30" rx="4" fill="var(--mock-accent-bg)" stroke="var(--mock-accent-border)" stroke-width="1.5" />
                    <text x="284" y="24" fill="var(--mock-accent)" font-size="11" font-weight="700" text-anchor="middle" font-family="inherit"><?= $esc($siteName) ?></text>
                </svg>
            </section>

            <!-- 3. Stat Cards Row -->
            <section class="mock-stats-grid" aria-label="Partner update metrics">
                <div class="mock-stat-card">
                    <div class="mock-stat-icon-tile" aria-hidden="true">📥</div>
                    <div class="mock-stat-content">
                        <span id="stat-received-count" class="mock-stat-value"><?= (int) $receivedCount ?></span>
                        <span class="mock-stat-label">Updates received</span>
                    </div>
                </div>

                <div class="mock-stat-card">
                    <div class="mock-stat-icon-tile" aria-hidden="true">🔁</div>
                    <div class="mock-stat-content">
                        <span id="stat-duplicate-count" class="mock-stat-value"><?= (int) $duplicateCount ?></span>
                        <span class="mock-stat-label">Duplicates ignored</span>
                    </div>
                </div>

                <div class="mock-stat-card">
                    <div class="mock-stat-icon-tile" aria-hidden="true">🕒</div>
                    <div class="mock-stat-content">
                        <span id="stat-last-received" class="mock-stat-value" style="font-size: var(--font-lg);"><?= $esc($lastReceivedText) ?></span>
                        <span class="mock-stat-label">Last update received</span>
                    </div>
                </div>

                <?php if ($forgottenCount !== null): ?>
                    <div class="mock-stat-card">
                        <div class="mock-stat-icon-tile" style="color: var(--status-down-text); background-color: var(--status-down-bg); border-color: var(--status-down-border);" aria-hidden="true">🕳️</div>
                        <div class="mock-stat-content">
                            <span id="stat-dropped-count" class="mock-stat-value" style="color: var(--status-down-text);"><?= (int) $forgottenCount ?></span>
                            <span class="mock-stat-label">Silently dropped</span>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- 4. Behavior & Rules Card -->
            <section class="mock-behavior-card" aria-labelledby="behavior-heading">
                <h2 id="behavior-heading" class="mock-card-heading">
                    <span aria-hidden="true">ℹ️</span> How <?= $esc($siteName) ?> behaves
                </h2>
                <ul class="mock-behavior-list">
                    <?php foreach ($behaviorBullets as $b): ?>
                        <li><?= $esc($b) ?></li>
                    <?php endforeach; ?>
                </ul>

                <?php if ($siteId === 'site_c' && $currentMode === 'forget'): ?>
                    <div class="mock-forget-banner" role="alert">
                        <span aria-hidden="true">⚠️</span>
                        <span>This site says OK but does not save updates (Simulating silent transaction divergence).</span>
                    </div>
                <?php endif; ?>
            </section>

            <!-- 5. Updates Received Table Panel -->
            <section id="mock-table-card" class="mock-table-card <?= empty($recentUpdates) ? 'u-hidden' : '' ?>" aria-labelledby="updates-heading">
                <div class="mock-table-header">
                    <h2 id="updates-heading" class="mock-card-heading">
                        <span aria-hidden="true">📋</span> Updates received (newest first, last 20)
                    </h2>
                </div>

                <div class="table-responsive">
                    <table class="data-table mock-data-table">
                        <caption class="sr-only">List of room inventory updates received by <?= $esc($siteName) ?></caption>
                        <thead>
                            <tr>
                                <th scope="col">Received At</th>
                                <th scope="col">Room</th>
                                <th scope="col">Dates</th>
                                <th scope="col">Units</th>
                                <th scope="col">Rate</th>
                                <th scope="col">Idempotency Key</th>
                            </tr>
                        </thead>
                        <tbody id="mock-updates-tbody">
                            <?php foreach ($recentUpdates as $u): ?>
                                <?php
                                $uKey = (string) ($u['idempotency_key'] ?? '');
                                $shortKey = strlen($uKey) > 8 ? substr($uKey, 0, 8) : $uKey;
                                $rId = (int) ($u['room_id'] ?? 0);
                                $rName = !empty($u['room_name']) ? (string) $u['room_name'] : ($roomNames[$rId] ?? "Room #{$rId}");
                                $from = (string) ($u['date_from'] ?? '');
                                $to = (string) ($u['date_to'] ?? '');
                                $dateStr = ($from && $to) ? date('j M', strtotime($from)) . ' to ' . date('j M', strtotime($to)) : '—';
                                $unitsStr = isset($u['available_units']) && $u['available_units'] !== null ? ((int) $u['available_units']) . ' ' . ((int) $u['available_units'] === 1 ? 'unit' : 'units') : '—';
                                $priceStr = isset($u['price']) && $u['price'] !== null ? '¥' . number_format((float) $u['price']) : '—';
                                $timeStr = '—';
                                if (!empty($u['received_at'])) {
                                    $uTs = strtotime((string) $u['received_at']);
                                    if ($uTs !== false) {
                                        $secAge = max(0, time() - $uTs);
                                        $ageStr = $secAge < 60 ? "{$secAge}s ago" : ($secAge < 3600 ? floor($secAge / 60) . ' min ago' : floor($secAge / 3600) . ' hr ago');
                                        $timeStr = gmdate('H:i:s \J\S\T', $uTs + 32400) . " ({$ageStr})";
                                    }
                                }
                                ?>
                                <tr class="mock-row" data-key="<?= $esc($uKey) ?>">
                                    <td data-label="Received At"><?= $esc($timeStr) ?></td>
                                    <td data-label="Room"><?= $esc($rName) ?></td>
                                    <td data-label="Dates"><?= $esc($dateStr) ?></td>
                                    <td data-label="Units"><?= $esc($unitsStr) ?></td>
                                    <td data-label="Rate"><?= $esc($priceStr) ?></td>
                                    <td data-label="Idempotency Key">
                                        <code class="key-chip" title="<?= $esc($uKey) ?>"><?= $esc($shortKey) ?></code>
                                        <?php if (!empty($u['hit_count']) && (int) $u['hit_count'] > 1): ?>
                                            <span class="chip chip-muted">duplicate x<?= (int) $u['hit_count'] ?></span>
                                        <?php endif; ?>

                                        <?php if (!empty($u['raw_xml'])): ?>
                                            <details class="raw-xml-details">
                                                <summary class="raw-xml-summary">Show raw XML</summary>
                                                <pre class="raw-xml-pre"><code><?= $esc((string) $u['raw_xml']) ?></code></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- 6. Empty State -->
            <section id="mock-empty-state" class="empty-state card <?= empty($recentUpdates) ? '' : 'u-hidden' ?>" aria-label="No updates received">
                <div class="empty-illustration" aria-hidden="true">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="var(--text-muted)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="3" width="20" height="18" rx="2" />
                        <line x1="8" y1="12" x2="16" y2="12" />
                        <line x1="12" y1="8" x2="12" y2="16" />
                    </svg>
                </div>
                <h2 class="empty-title">No updates received yet.</h2>
                <p class="state-text">Save an update in Sync Guard and run the worker.</p>
                <div class="hero-actions" style="justify-content: center; margin-top: var(--space-3);">
                    <a href="/update.php" class="btn btn-primary">Update inventory &rarr;</a>
                    <a href="/" class="btn btn-secondary">Go to dashboard</a>
                </div>
            </section>
        </main>

        <!-- Shared Footer Partial -->
        <?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
    </div>

    <!-- Client Scripts -->
    <script src="/assets/js/vendor/jquery.min.js?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/js/vendor/jquery.min.js') ?>" defer></script>
    <script src="/assets/js/header-status.js?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/js/header-status.js') ?>" defer></script>
    <script src="/assets/js/mock-console.js?v=<?= filemtime(dirname(__DIR__, 2) . '/assets/js/mock-console.js') ?>" defer></script>
</body>
</html>
