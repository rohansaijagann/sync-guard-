<?php
declare(strict_types=1);

/**
 * Sync Guard - Real-Time Operational Dashboard
 *
 * Operations console displaying:
 * 1. Hero Status Banner with single-sentence priority hierarchy
 * 2. Pipeline Diagram (inline SVG)
 * 3. Channel Health Cards (donut rings, latency bar, failure dots, compact chips, sparklines)
 * 4. Rooms at Risk Panel (severity grouping, collapsible top-5, empty state illustration)
 * 5. Time to Consistency Metrics
 * 6. Next 14 Days Availability & Risk Calendar Matrix
 * 7. Deliveries Stacked Chart (24h) & Recent Delivery Activity Feed
 * 8. Demo Controls (gated to local development)
 * 9. Diagnostics & Native Modal Dialog for Dead Retries
 */

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

use SyncGuard\Database\Connection;

$dbConnected = false;
$charset = 'Unknown';
$sessionTz = 'Unknown';

try {
    $pdo = Connection::get();
    $dbConnected = true;

    // Fetch system variables for the diagnostics footer
    $sysStmt = $pdo->query('SELECT @@character_set_connection AS c, @@session.time_zone AS tz');
    $sysInfo = $sysStmt->fetch();
    $charset = (string) ($sysInfo['c'] ?? 'utf8mb4');
    $sessionTz = (string) ($sysInfo['tz'] ?? '+00:00');
} catch (\Throwable $e) {
    error_log('[Dashboard Index] Database check error: ' . $e->getMessage());
}

$config = require dirname(__DIR__) . '/config/config.php';
$isDemoEnabled = (($config['app']['env'] ?? '') === 'local') && !empty($config['demo']['enabled']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard · Sync Guard</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
    <a href="#main" class="skip-link">Skip to main content</a>

    <noscript>
        <div class="banner banner-danger" role="alert">
            Sync Guard requires JavaScript to display real-time channel telemetry and delivery pipeline updates.
        </div>
    </noscript>

    <div class="container">
        <!-- Application Header (Partial) -->
        <?php
        $currentPage = 'dashboard';
        require __DIR__ . '/partials/header.php';
        ?>

        <!-- Toast Notification Container (Live Region for State Changes) -->
        <div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="true"></div>

        <!-- Polite Live Region for Screen Readers (Announces Status Changes Only) -->
        <div id="status-live-announcer" class="sr-only" aria-live="polite"></div>

        <!-- Attention Banner (Maintained for backward compatibility and quick retry) -->
        <div id="attention-banner" class="banner attention-banner u-hidden" role="alert">
            <span class="banner-icon">🚨</span>
            <span class="banner-text"><strong>Attention Needed:</strong> <span id="attention-count">0</span> dead job(s) require manual intervention.</span>
            <button type="button" id="btn-retry-all-dead" class="btn btn-sm btn-danger">Retry all dead jobs</button>
            <a href="/log.php?status=dead" class="banner-action-link">View dead jobs →</a>
        </div>

        <div id="stale-banner" class="banner stale-banner u-hidden" role="status">
            <span class="banner-icon">⚠️</span>
            <span id="stale-message">Can't reach the server. Retrying...</span>
        </div>

        <!-- Main Operational Console -->
        <main id="main">
            <!-- 1. Hero Status Banner -->
            <section id="hero-banner" class="hero-banner hero-healthy" aria-labelledby="hero-title">
                <div class="hero-content">
                    <span id="hero-icon" class="hero-icon" aria-hidden="true">✔</span>
                    <div class="hero-text-group">
                        <h2 id="hero-title" class="hero-headline">All 3 sites are in sync.</h2>
                        <p id="hero-secondary" class="hero-secondary">--% success rate · 0 pending · 0 failed</p>
                    </div>
                </div>
                <div class="hero-actions">
                    <button type="button" id="hero-dead-btn" class="btn btn-danger u-hidden">
                        <span id="hero-dead-count">0</span> jobs need attention
                    </button>
                </div>
            </section>

            <!-- 2. Delivery Pipeline Diagram (Inline SVG Component) -->
            <section id="pipeline-section" class="pipeline-section" aria-labelledby="pipeline-heading">
                <h2 id="pipeline-heading" class="sr-only">Delivery Pipeline Status</h2>
                <div id="pipeline-container" class="pipeline-diagram-wrapper" role="img" aria-label="Delivery pipeline diagram: Staff update to Queue to Worker to Sites">
                    <!-- Inline SVG built and updated in-place via document.createElementNS in dashboard.js -->
                </div>
                <ul id="pipeline-sr-list" class="sr-only">
                    <li>Staff update node: Active</li>
                    <li>Queue node: 0 pending jobs</li>
                    <li>Worker node: Checking worker status</li>
                    <li>Site nodes: Synchronizing channels</li>
                </ul>
            </section>

            <!-- 3. Channel Health Cards (Sites) -->
            <section id="sites-section" class="sites-section" aria-labelledby="sites-heading">
                <h2 id="sites-heading" class="sr-only">Channel Health Cards</h2>
                <div id="channel-cards-container" class="sites-grid">
                    <!-- Channel Cards (Site A, Site B, Site C) dynamically rendered here -->
                </div>
            </section>

            <!-- 4. Rooms at Risk Panel -->
            <section id="at-risk-section" class="at-risk-panel" aria-labelledby="at-risk-heading">
                <div class="at-risk-header">
                    <h2 id="at-risk-heading">
                        <span>Rooms at Risk</span>
                        <span id="at-risk-header-count" class="badge badge-neutral">0</span>
                    </h2>
                </div>

                <div id="at-risk-empty" class="empty-state">
                    <svg class="empty-check-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                    <span>Nothing at risk. Every site has the latest availability.</span>
                </div>

                <div id="at-risk-list" class="at-risk-list"></div>
                <button type="button" id="btn-show-all-at-risk" class="btn btn-sm btn-secondary u-hidden" aria-expanded="false">
                    Show all at-risk rooms
                </button>
            </section>

            <!-- 5. Time to Consistency Card (Summary Grid) -->
            <section aria-labelledby="consistency-heading">
                <div class="dashboard-grid">
                    <div id="consistency-card" class="consistency-card">
                        <div class="card-header">
                            <h2 id="consistency-heading">Time to Consistency</h2>
                            <span id="consistency-badge" class="badge badge-neutral">24h Window</span>
                        </div>

                        <div class="consistency-body">
                            <div class="consistency-big-stat">
                                <span class="stat-label">Median Delivery Latency</span>
                                <span id="consistency-median" class="stat-value big-number">--</span>
                            </div>

                            <div class="consistency-sub-stats">
                                <div class="stat-item">
                                    <span class="stat-label">Average</span>
                                    <span id="consistency-avg" class="stat-value">--</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">P95</span>
                                    <span id="consistency-p95" class="stat-value">--</span>
                                </div>
                                <div class="stat-item">
                                    <span class="stat-label">Max</span>
                                    <span id="consistency-max" class="stat-value">--</span>
                                </div>
                            </div>

                            <div class="consistency-status-line">
                                <span id="consistency-sync-status">Checking sync freshness...</span>
                            </div>
                        </div>

                        <details class="consistency-explainer">
                            <summary>What does this mean?</summary>
                            <p>How long it takes from a staff change until every site has it.</p>
                        </details>
                    </div>

                    <!-- Overall Aggregate System Summary Card -->
                    <div id="overall-card" class="card">
                        <div class="card-header">
                            <h3>Overall Pipeline Health</h3>
                            <span class="badge overall-status-badge badge-neutral">
                                <span class="badge-symbol">—</span>
                                <span class="badge-text">Loading...</span>
                            </span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Fleet Success Rate</span>
                            <span class="stat-value overall-success-rate">--%</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Average Response Time</span>
                            <span class="stat-value overall-avg-ms">-- ms</span>
                        </div>
                        <div class="channel-chips">
                            <span class="chip chip-pending">Pending: <strong class="cnt-pending">0</strong></span>
                            <span class="chip">Active: <strong class="cnt-in-progress">0</strong></span>
                            <span class="chip chip-failed">Failed: <strong class="cnt-failed">0</strong></span>
                            <span class="chip chip-dead">Dead: <strong class="cnt-dead">0</strong></span>
                            <span class="chip">Success: <strong class="cnt-success">0</strong></span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 6. Next 14 Days Availability & Risk Calendar Matrix -->
            <section id="calendar-section" class="calendar-section" aria-labelledby="calendar-heading">
                <div class="calendar-header">
                    <div class="calendar-title-group">
                        <h2 id="calendar-heading">Next <span id="calendar-days-display">14</span> Days Availability &amp; Risk</h2>
                        <span id="calendar-stale-indicator" class="stale-indicator u-hidden">Stale</span>
                    </div>
                    <div class="calendar-controls">
                        <span class="control-label">Range:</span>
                        <div class="btn-group" role="group" aria-label="Calendar duration selector">
                            <button type="button" class="btn btn-sm btn-range" data-days="7">7 Days</button>
                            <button type="button" class="btn btn-sm btn-range active" data-days="14">14 Days</button>
                            <button type="button" class="btn btn-sm btn-range" data-days="28">28 Days</button>
                        </div>
                    </div>
                </div>

                <div class="calendar-legend" aria-label="Calendar cell states legend">
                    <span class="legend-item"><span class="cell-badge state-synced">✔</span> Synced</span>
                    <span class="legend-item"><span class="cell-badge state-syncing">⟳</span> Syncing</span>
                    <span class="legend-item"><span class="cell-badge state-failed">⚠</span> Failed (Retrying)</span>
                    <span class="legend-item"><span class="cell-badge state-dead">✖</span> Dead (Needs Attention)</span>
                </div>

                <div class="calendar-table-wrapper" tabindex="0" role="region" aria-label="Room availability calendar matrix">
                    <table id="calendar-table" class="calendar-table">
                        <caption class="sr-only">Room availability, rates, and sync health matrix across the selected forecast days</caption>
                        <thead id="calendar-thead"></thead>
                        <tbody id="calendar-tbody"></tbody>
                    </table>
                </div>
                <div id="calendar-empty" class="empty-state u-hidden">No calendar data available.</div>
            </section>

            <!-- 7. Deliveries Chart & Recent Activity Row -->
            <div class="insights-row">
                <!-- Deliveries Stacked Chart (Inline SVG) -->
                <section class="card chart-card" aria-labelledby="chart-heading">
                    <div class="card-header">
                        <h2 id="chart-heading">Deliveries, last 24 hours</h2>
                        <span id="chart-stale-indicator" class="stale-indicator u-hidden">Stale</span>
                    </div>
                    <div id="deliveries-chart-container" class="chart-container" role="img" aria-label="Deliveries chart: 0 delivered, 0 failed in the last 24 hours">
                        <!-- Inline SVG created with document.createElementNS -->
                    </div>
                    <div class="chart-legend">
                        <span class="legend-item"><span class="legend-color legend-success"></span> Delivered</span>
                        <span class="legend-item"><span class="legend-color legend-failed"></span> Failed</span>
                    </div>
                    <table id="deliveries-hidden-table" class="sr-only">
                        <caption>Hourly delivery counts for screen readers</caption>
                        <thead>
                            <tr>
                                <th scope="col">Hour (JST)</th>
                                <th scope="col">Success</th>
                                <th scope="col">Failed</th>
                            </tr>
                        </thead>
                        <tbody id="deliveries-hidden-tbody"></tbody>
                    </table>
                </section>

                <!-- Recent Activity Feed -->
                <section class="card recent-activity-card" aria-labelledby="recent-heading">
                    <div class="card-header">
                        <h2 id="recent-heading">Recent activity</h2>
                        <span class="header-hint">Last 15 delivery attempts</span>
                    </div>
                    <div id="recent-activity-container">
                        <ul id="recent-activity-list" class="recent-list"></ul>
                        <div id="recent-activity-empty" class="empty-state u-hidden">No recent delivery activity.</div>
                    </div>
                </section>
            </div>

            <!-- 8. Demo Controls Panel (Gated strictly to local development with demo flag) -->
            <?php if ($isDemoEnabled): ?>
            <details id="demo-controls-panel" class="demo-controls-details card">
                <summary class="demo-controls-summary">
                    <span class="demo-title">Demo controls (local only)</span>
                    <span class="badge badge-degraded">Local Testing</span>
                </summary>
                <div class="demo-controls-content">
                    <p class="demo-warning-line">⚠️ <strong>Warning:</strong> These buttons change data and break sites on purpose.</p>
                    
                    <div class="demo-grid">
                        <div class="demo-site-control">
                            <label for="demo-mode-site-b">Site B Behavior:</label>
                            <select id="demo-mode-site-b" class="form-control">
                                <option value="normal">normal - 200 OK after 50ms</option>
                                <option value="slow">slow - Takes 6s, triggers timeout</option>
                                <option value="http_500">http_500 - Returns 500 Internal Error</option>
                                <option value="malformed_xml">malformed_xml - Returns invalid XML</option>
                                <option value="auth_fail">auth_fail - Returns 401 Unauthorized</option>
                                <option value="network_drop">network_drop - Drops connection</option>
                            </select>
                            <button type="button" id="btn-apply-site-b" class="btn btn-sm btn-secondary">Apply Site B</button>
                        </div>

                        <div class="demo-site-control">
                            <label for="demo-mode-site-c">Site C Behavior:</label>
                            <select id="demo-mode-site-c" class="form-control">
                                <option value="random">random - 70% success, 30% timeout/error</option>
                                <option value="always_succeed">always_succeed - 100% success</option>
                                <option value="always_fail">always_fail - Returns 503 Service Unavailable</option>
                                <option value="always_timeout">always_timeout - Takes 6s, triggers timeout</option>
                                <option value="invalid_token">invalid_token - Returns 401 Invalid Token</option>
                                <option value="rate_limited">rate_limited - Returns 429 Too Many Requests</option>
                            </select>
                            <button type="button" id="btn-apply-site-c" class="btn btn-sm btn-secondary">Apply Site C</button>
                        </div>
                    </div>

                    <div class="demo-actions-row">
                        <button type="button" id="btn-demo-reset" class="btn btn-sm btn-secondary">Reset sites</button>
                        <button type="button" id="btn-demo-submit-update" class="btn btn-sm btn-primary">Submit demo update</button>
                        <button type="button" id="btn-demo-run-worker" class="btn btn-sm btn-secondary">Run worker once</button>
                        <button type="button" id="btn-demo-skip-wait" class="btn btn-sm btn-secondary">Skip retry waits</button>
                    </div>

                    <div class="demo-status-info">
                        <span id="demo-status-modes">Site B: normal | Site C: random</span>
                        <p id="demo-worker-hint" class="demo-hint u-hidden">
                            The background worker is not running. Click "Run worker once" or start it with: <code>docker compose --profile worker up -d worker</code>
                        </p>
                    </div>
                </div>
            </details>
            <?php endif; ?>

            <!-- 9. Collapsible System Information (Diagnostics & Environment) -->
            <details class="system-info-details">
                <summary>System Diagnostics &amp; Environment</summary>
                <div class="system-info-content">
                    <div class="sys-grid">
                        <div>
                            <strong>PHP Version:</strong> <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div>
                            <strong>Database Connection:</strong> 
                            <?= $dbConnected ? '<span class="sys-connected">Connected (MySQL 8)</span>' : '<span class="sys-failed">Failed</span>' ?>
                        </div>
                        <div>
                            <strong>Database Charset:</strong> <?= htmlspecialchars($charset, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div>
                            <strong>Database Session TZ:</strong> <?= htmlspecialchars($sessionTz, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                    <div>
                        <a href="/health.php" target="_blank" class="sys-link">
                            View JSON Health Probe Endpoint (/health.php) →
                        </a>
                    </div>
                </div>
            </details>
        </main>

        <!-- Native Modal Dialog for Retry All Dead Jobs -->
        <dialog id="retry-dead-dialog" class="modal-dialog">
            <form method="dialog" class="dialog-content">
                <h3 class="dialog-title">Retry Dead Jobs</h3>
                <p class="dialog-body">Retry <span id="dialog-dead-count">0</span> dead jobs? Each job gets a fresh set of 5 attempts and the history is kept.</p>
                <div class="dialog-actions">
                    <button type="button" id="dialog-cancel-btn" class="btn btn-secondary">Cancel</button>
                    <button type="submit" id="dialog-confirm-btn" class="btn btn-danger">Retry</button>
                </div>
            </form>
        </dialog>

        <?php require __DIR__ . '/partials/footer.php'; ?>
    </div>

    <!-- Scripts (jQuery 3.7.1 + Hero Calculator + Dashboard + Insights Pollers) -->
    <script src="/assets/js/vendor/jquery.min.js?v=<?= filemtime(__DIR__ . '/assets/js/vendor/jquery.min.js') ?>" defer></script>
    <script src="/assets/js/hero.js?v=<?= filemtime(__DIR__ . '/assets/js/hero.js') ?>" defer></script>
    <script src="/assets/js/dashboard.js?v=<?= filemtime(__DIR__ . '/assets/js/dashboard.js') ?>" defer></script>
    <script src="/assets/js/insights.js?v=<?= filemtime(__DIR__ . '/assets/js/insights.js') ?>" defer></script>
</body>
</html>
