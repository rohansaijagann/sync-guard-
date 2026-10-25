<?php
declare(strict_types=1);

/**
 * Sync Guard - Sync Log & Audit Inspection Page
 *
 * Provides hotel staff with full visibility into delivery status,
 * expandable attempt history, filterable log tables, and manual single-job retry.
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync log · Sync Guard</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
    <a href="#main" class="skip-link">Skip to main content</a>

    <div class="container">
        <!-- Application Header (Shared Partial) -->
        <?php
        $currentPage = 'log';
        require __DIR__ . '/partials/header.php';
        ?>

        <!-- Accessibility Live Announcer -->
        <div id="live-announcer" class="sr-only" role="status" aria-live="polite"></div>

        <!-- URL Parameter Validation Alert -->
        <div id="url-param-notice" class="banner attention-banner u-hidden" role="alert">
            <span class="banner-icon">⚠️</span>
            <span class="banner-text">Some filters in this link were not valid and were ignored.</span>
            <button type="button" id="btn-dismiss-notice" class="btn-dismiss" aria-label="Dismiss notice">&times;</button>
        </div>

        <main id="main">
            <!-- Redesigned Filter Toolbar Card -->
            <section class="card filter-card log-toolbar-card" aria-labelledby="filter-heading">
                <h2 id="filter-heading" class="sr-only">Sync Log Filters</h2>

                <!-- Status Filter Tabs Row -->
                <div class="status-tabs-container">
                    <span class="toolbar-label sr-only">Filter by Status</span>
                    <div class="status-tabs-nav" role="tablist" aria-label="Filter jobs by delivery status">
                        <button type="button" class="status-tab" data-status="all" aria-pressed="true">
                            <span>All</span>
                            <span class="tab-count cnt-all">0</span>
                        </button>
                        <button type="button" class="status-tab" data-status="unsynced" aria-pressed="false">
                            <span>Unsynced</span>
                            <span class="tab-count cnt-unsynced">0</span>
                        </button>
                        <button type="button" class="status-tab" data-status="pending" aria-pressed="false">
                            <span>Pending</span>
                            <span class="tab-count cnt-pending">0</span>
                        </button>
                        <button type="button" class="status-tab" data-status="in_progress" aria-pressed="false">
                            <span>In progress</span>
                            <span class="tab-count cnt-in-progress">0</span>
                        </button>
                        <button type="button" class="status-tab" data-status="failed" aria-pressed="false">
                            <span>Failed</span>
                            <span class="tab-count cnt-failed">0</span>
                        </button>
                        <button type="button" class="status-tab" data-status="dead" aria-pressed="false">
                            <span>Dead</span>
                            <span class="tab-count cnt-dead">0</span>
                        </button>
                        <button type="button" class="status-tab" data-status="success" aria-pressed="false">
                            <span>Success</span>
                            <span class="tab-count cnt-success">0</span>
                        </button>
                    </div>

                    <!-- Hidden native select for full backward test compatibility -->
                    <select id="filter-status" class="u-hidden sr-only" tabindex="-1" aria-hidden="true">
                        <option value="all">All</option>
                        <option value="unsynced">Unsynced</option>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="failed">Failed</option>
                        <option value="dead">Dead</option>
                        <option value="success">Success</option>
                    </select>
                </div>

                <!-- Secondary Filters Grid (Site, Room, Rows-per-page) -->
                <div class="log-filters-grid">
                    <!-- Channel Filter -->
                    <div class="filter-group">
                        <label for="filter-channel" class="filter-label">Booking Site</label>
                        <select id="filter-channel" class="filter-select styled-select">
                            <option value="">All Sites</option>
                        </select>
                    </div>

                    <!-- Room Filter -->
                    <div class="filter-group">
                        <label for="filter-room" class="filter-label">Room</label>
                        <select id="filter-room" class="filter-select styled-select">
                            <option value="">All Rooms</option>
                        </select>
                    </div>

                    <!-- Per Page Filter -->
                    <div class="filter-group">
                        <label for="filter-per-page" class="filter-label">Rows per page</label>
                        <select id="filter-per-page" class="filter-select styled-select">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                        </select>
                    </div>

                    <!-- Action Controls & Timestamp -->
                    <div class="log-actions-bar">
                        <button type="button" id="btn-reset-filters" class="btn btn-secondary">Reset filters</button>
                        <button type="button" id="btn-refresh" class="btn btn-primary">Refresh</button>
                        <span id="loaded-at-text" class="loaded-at-text">Loaded at --:--:-- JST</span>
                    </div>
                </div>
            </section>

            <!-- Results Meta Row (above table) -->
            <div class="table-top-meta">
                <span id="results-summary" class="results-summary" aria-live="polite">Loading jobs...</span>
            </div>

            <!-- Non-JavaScript Fallback Notice -->
            <noscript>
                <div class="card banner banner-danger">
                    <h2>JavaScript Required</h2>
                    <p class="noscript-text">
                        The Sync Log requires JavaScript to load live delivery telemetry, interactively filter jobs, view expandable attempt histories, and dispatch manual job retries. Please enable JavaScript in your browser to proceed.
                    </p>
                </div>
            </noscript>

            <!-- Loading Skeleton Rows Container -->
            <div id="loading-state" class="state-container loading-container" role="status">
                <div class="skeleton-log-rows" aria-hidden="true">
                    <div class="skeleton-log-row"></div>
                    <div class="skeleton-log-row"></div>
                    <div class="skeleton-log-row"></div>
                    <div class="skeleton-log-row"></div>
                    <div class="skeleton-log-row"></div>
                </div>
                <div class="loading-label-group">
                    <span class="spinner" aria-hidden="true"></span>
                    <span class="state-text">Loading sync jobs...</span>
                </div>
            </div>

            <!-- Error State with Illustration and Retry Button -->
            <div id="error-state" class="state-container error-container card log-state-hidden" role="alert">
                <div id="error-illustration" class="error-illustration" aria-hidden="true"></div>
                <h3 id="error-title" class="error-title">Could not load the sync log.</h3>
                <p id="error-message-text" class="state-text">Can't reach the server. Try again.</p>
                <button type="button" id="btn-retry-fetch" class="btn btn-primary">Retry</button>
            </div>

            <!-- Empty State (No records matching filters) -->
            <div id="empty-filtered-state" class="empty-state card log-state-hidden">
                <div id="empty-filtered-illustration" class="empty-illustration" aria-hidden="true"></div>
                <p class="empty-title">No jobs match these filters.</p>
                <button type="button" id="btn-empty-reset" class="btn btn-secondary">Reset filters</button>
            </div>

            <!-- Empty State (Zero jobs in entire system) -->
            <div id="empty-system-state" class="empty-state card log-state-hidden">
                <div id="empty-system-illustration" class="empty-illustration" aria-hidden="true"></div>
                <p class="empty-title">No sync jobs yet. Submit an update to get started.</p>
                <a href="/update.php" class="btn btn-primary">Submit Update →</a>
            </div>

            <!-- Table Section with Sticky Header -->
            <section id="table-section" class="table-card log-state-hidden" aria-labelledby="table-heading">
                <h2 id="table-heading" class="sr-only">Sync Jobs List</h2>

                <div class="table-responsive">
                    <table id="jobs-table" class="data-table log-data-table">
                        <caption class="sr-only">Real-Time Sync Jobs and Delivery Log</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="th-job">Job</th>
                                <th scope="col" class="th-site">Site</th>
                                <th scope="col" class="th-room">Room</th>
                                <th scope="col" class="th-dates">Dates</th>
                                <th scope="col" class="th-change">Change</th>
                                <th scope="col" class="th-status">Status</th>
                                <th scope="col" class="th-tries">Tries</th>
                                <th scope="col" class="th-message">What happened</th>
                                <th scope="col" class="th-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="jobs-tbody"></tbody>
                    </table>
                </div>

                <!-- Pagination Nav Controls -->
                <nav id="pagination-nav" class="pagination-container u-hidden" aria-label="Job Log Pagination">
                    <button type="button" id="btn-prev-page" class="btn-page" aria-label="Previous Page">← Previous</button>
                    <div id="page-numbers" class="page-numbers"></div>
                    <button type="button" id="btn-next-page" class="btn-page" aria-label="Next Page">Next →</button>
                    <span id="page-info" class="page-info">Page 1 of 1</span>
                </nav>
            </section>
        </main>

        <?php require __DIR__ . '/partials/footer.php'; ?>
    </div>

    <!-- Row Template for Job Item -->
    <template id="job-row-template">
        <tr class="job-row">
            <td class="cell-job col-sticky-left" data-label="Job">
                <span class="job-id-text"></span>
            </td>
            <td class="cell-site" data-label="Site">
                <span class="site-name"></span>
                <span class="badge badge-paused log-state-hidden">Paused site</span>
            </td>
            <td class="cell-room" data-label="Room">
                <span class="room-name"></span>
            </td>
            <td class="cell-dates" data-label="Dates">
                <span class="dates-text"></span>
            </td>
            <td class="cell-change" data-label="Change">
                <span class="change-text"></span>
            </td>
            <td class="cell-status" data-label="Status">
                <span class="badge status-badge">
                    <span class="badge-icon"></span>
                    <span class="badge-text"></span>
                </span>
            </td>
            <td class="cell-tries" data-label="Tries">
                <div class="tries-cell-wrapper">
                    <span class="tries-text"></span>
                    <div class="tries-bar" aria-hidden="true">
                        <span class="tries-fill"></span>
                    </div>
                </div>
            </td>
            <td class="cell-message" data-label="What happened">
                <span class="message-text"></span>
            </td>
            <td class="cell-actions col-sticky-right" data-label="Actions">
                <div class="actions-group">
                    <button type="button" class="btn-action btn-details btn btn-xs btn-secondary" aria-expanded="false">Details</button>
                    <button type="button" class="btn-action btn-retry btn btn-xs btn-primary log-state-hidden">Retry</button>
                </div>
            </td>
        </tr>
    </template>

    <!-- Row Template for Expandable Details and Attempt History -->
    <template id="details-row-template">
        <tr class="details-row log-state-hidden">
            <td colspan="9" class="details-cell">
                <div class="details-container">
                    <div class="details-summary-grid">
                        <div class="detail-box">
                            <span class="detail-box-label">Update & Correlation</span>
                            <div class="detail-box-val detail-update-info"></div>
                        </div>
                        <div class="detail-box">
                            <span class="detail-box-label">Inventory & Rate Change</span>
                            <div class="detail-box-val detail-inventory-info"></div>
                        </div>
                        <div class="detail-box detail-box-error">
                            <span class="detail-box-label">Stored Channel Error (last_error)</span>
                            <div class="detail-box-val detail-last-error">None</div>
                        </div>
                    </div>

                    <div class="attempts-section">
                        <h3 class="attempts-heading">Attempt History</h3>
                        <div class="attempts-loading u-hidden">Loading attempt telemetry...</div>
                        <div class="attempts-error u-hidden">Failed to load attempt history.</div>
                        <div class="attempts-truncated-notice u-hidden">
                            Showing the 100 most recent attempts (older attempts truncated).
                        </div>
                        <div class="attempts-table-wrapper">
                            <table class="attempts-table">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Started (JST)</th>
                                        <th scope="col">Duration</th>
                                        <th scope="col">HTTP</th>
                                        <th scope="col">Outcome</th>
                                        <th scope="col">Site Response / Message</th>
                                    </tr>
                                </thead>
                                <tbody class="attempts-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    </template>

    <!-- Template for Attempt History Item -->
    <template id="attempt-row-template">
        <tr class="attempt-row">
            <td class="att-id"></td>
            <td class="att-started"></td>
            <td class="att-duration"></td>
            <td class="att-http"></td>
            <td class="att-outcome">
                <span class="badge att-badge"></span>
            </td>
            <td class="att-message"></td>
        </tr>
    </template>

    <!-- Scripts (Vendored jQuery 3.7.1 + Safe Sync Log Controller) -->
    <script src="/assets/js/vendor/jquery.min.js?v=<?= filemtime(__DIR__ . '/assets/js/vendor/jquery.min.js') ?>" defer></script>
    <script src="/assets/js/header-status.js?v=<?= filemtime(__DIR__ . '/assets/js/header-status.js') ?>" defer></script>
    <script src="/assets/js/log.js?v=<?= filemtime(__DIR__ . '/assets/js/log.js') ?>" defer></script>
</body>
</html>
