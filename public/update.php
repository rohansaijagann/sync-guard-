<?php
declare(strict_types=1);

/**
 * Sync Guard - Staff Inventory Update Portal
 *
 * Allows front-desk and revenue staff to update room availability and rates.
 * Renders the update form, pre-loads active room options, generates an initial
 * client idempotency key, and displays the recent updates table in JST.
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
use SyncGuard\Repositories\ChannelRepository;
use SyncGuard\Repositories\JobRepository;
use SyncGuard\Repositories\RoomRepository;
use SyncGuard\Repositories\UpdateRepository;
use SyncGuard\Services\InventoryService;
use SyncGuard\Support\Time;
use SyncGuard\Support\Uuid;
use SyncGuard\Validation\InventoryUpdateValidator;

$rooms = [];
$activeChannels = [];
$recentUpdates = [];
$dbError = null;
$initialIdempotencyKey = Uuid::v4();
$todayTokyo = Time::todayInTokyo();

try {
    $pdo = Connection::get();
    $roomRepo = new RoomRepository($pdo);
    $channelRepo = new ChannelRepository($pdo);
    $updateRepo = new UpdateRepository($pdo);
    $jobRepo = new JobRepository($pdo);
    $validator = new InventoryUpdateValidator();

    $rooms = $roomRepo->getAll();
    $activeChannels = $channelRepo->getActiveChannels();

    $service = new InventoryService($pdo, $roomRepo, $channelRepo, $updateRepo, $jobRepo, $validator);
    $recentUpdates = $service->getRecentUpdates(10);

} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$activeCount = count($activeChannels);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update inventory · Sync Guard</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>
    <a href="#main" class="skip-link">Skip to main content</a>

    <noscript>
        <div class="banner banner-danger" role="alert">
            Sync Guard requires JavaScript to validate inventory inputs, calculate price comparisons, and transmit updates.
        </div>
    </noscript>

    <div class="container update-page-container">
        <!-- Shared Header Partial -->
        <?php
        $currentPage = 'update';
        require __DIR__ . '/partials/header.php';
        ?>

        <!-- Polite Live Region for Results -->
        <div id="live-feedback-region" class="sr-only" role="status" aria-live="polite"></div>

        <main id="main" class="update-main-layout">
            <!-- Page Header -->
            <header class="page-intro">
                <h1 class="page-title">Update inventory</h1>
                <p class="page-description">Change availability or price for a room. The update is sent to every booking site.</p>
            </header>

            <?php if ($dbError !== null): ?>
                <div class="banner banner-danger" role="alert">
                    <strong>Database Connection Error:</strong>
                    <span><?= htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php else: ?>
                <div class="update-grid">
                    <!-- Column 1: Inventory Update Form Card -->
                    <div class="update-form-column">
                        <section class="card form-card" aria-labelledby="form-card-title">
                            <h2 id="form-card-title" class="sr-only">Room Inventory Form</h2>

                            <!-- Error Summary Box (Focus moved here on submit error) -->
                            <div id="form-error-summary" class="error-summary u-hidden" role="alert" tabindex="-1">
                                <h3 class="error-summary-title">
                                    <span class="summary-icon" aria-hidden="true">⚠️</span>
                                    <span>Please fix the following errors</span>
                                </h3>
                                <ul id="error-summary-list" class="error-summary-list"></ul>
                            </div>

                            <form id="inventory_form" method="GET" action="update.php" novalidate data-today-jst="<?= htmlspecialchars($todayTokyo, ENT_QUOTES, 'UTF-8') ?>">
                                <!-- Hidden Idempotency Key (renewed only on 200/201 success) -->
                                <input type="hidden" name="idempotency_key" id="idempotency_key" value="<?= htmlspecialchars($initialIdempotencyKey, ENT_QUOTES, 'UTF-8') ?>">

                                <!-- Section 1: Room -->
                                <fieldset class="form-section">
                                    <legend class="section-legend">1. Room</legend>
                                    <div class="form-group">
                                        <label for="room_id" class="form-label">
                                            <span>Room type</span>
                                            <span class="label-ja">客室タイプ</span>
                                            <span class="badge-required">required</span>
                                        </label>
                                        <select name="room_id" id="room_id" class="form-select" required aria-describedby="room_helper_text error_room_id">
                                            <option value="">Select a room type...</option>
                                            <?php foreach ($rooms as $room): ?>
                                                <option value="<?= (int) $room['id'] ?>"
                                                        data-total-units="<?= (int) $room['total_units'] ?>"
                                                        data-base-price="<?= (int) $room['base_price'] ?>"
                                                        data-capacity="<?= (int) $room['total_units'] ?>">
                                                    <?= htmlspecialchars($room['name'], ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="room_helper_text" class="field-helper" aria-live="polite">Select a room to view capacity and base price</div>
                                        <div class="field-error" id="error_room_id" role="alert"></div>
                                    </div>
                                </fieldset>

                                <!-- Section 2: Dates -->
                                <fieldset class="form-section">
                                    <legend class="section-legend">2. Dates</legend>
                                    <div class="dates-row">
                                        <div class="form-group">
                                            <label for="date_from" class="form-label">
                                                <span>From</span>
                                                <span class="label-ja">適用開始日</span>
                                                <span class="badge-required">required</span>
                                            </label>
                                            <input type="date" name="date_from" id="date_from" class="form-input" min="<?= htmlspecialchars($todayTokyo, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($todayTokyo, ENT_QUOTES, 'UTF-8') ?>" required aria-describedby="date_helper_text error_date_from">
                                            <div class="field-error" id="error_date_from" role="alert"></div>
                                        </div>
                                        <div class="form-group">
                                            <label for="date_to" class="form-label">
                                                <span>To</span>
                                                <span class="label-ja">適用終了日</span>
                                                <span class="badge-required">required</span>
                                            </label>
                                            <input type="date" name="date_to" id="date_to" class="form-input" min="<?= htmlspecialchars($todayTokyo, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($todayTokyo, ENT_QUOTES, 'UTF-8') ?>" required aria-describedby="date_helper_text error_date_to">
                                            <div class="field-error" id="error_date_to" role="alert"></div>
                                        </div>
                                    </div>

                                    <!-- Quick Date Presets -->
                                    <div class="presets-container">
                                        <span class="presets-label">Quick presets:</span>
                                        <div class="presets-group" role="group" aria-label="Date presets">
                                            <button type="button" class="btn btn-preset" data-preset="today">Today</button>
                                            <button type="button" class="btn btn-preset" data-preset="tomorrow">Tomorrow</button>
                                            <button type="button" class="btn btn-preset" data-preset="weekend">This weekend</button>
                                            <button type="button" class="btn btn-preset" data-preset="next7">Next 7 days</button>
                                            <button type="button" class="btn btn-preset" data-preset="next30">Next 30 days</button>
                                        </div>
                                    </div>

                                    <!-- Live Date Range Summary -->
                                    <div id="date_live_summary" class="date-live-summary" aria-live="polite"></div>
                                    <div id="date_helper_text" class="field-helper">Dates are in Japan time (JST)</div>
                                </fieldset>

                                <!-- Section 3: What changes -->
                                <fieldset class="form-section">
                                    <legend class="section-legend">3. What changes</legend>
                                    <div id="section3_error" class="field-error section-general-error" role="alert"></div>

                                    <!-- Available Units -->
                                    <div class="form-group">
                                        <label for="available_units" class="form-label">
                                            <span>Available units</span>
                                            <span class="label-ja">販売可能室数</span>
                                        </label>
                                        <div class="stepper-wrapper">
                                            <button type="button" class="btn btn-stepper" id="btn-units-minus" aria-label="Decrease units">&minus;</button>
                                            <input type="number" name="available_units" id="available_units" class="form-input input-stepper" min="0" inputmode="numeric" placeholder="Leave blank to keep current" aria-describedby="units_helper error_available_units">
                                            <button type="button" class="btn btn-stepper" id="btn-units-plus" aria-label="Increase units">&plus;</button>
                                        </div>
                                        <div class="quick-unit-buttons">
                                            <button type="button" class="btn btn-xs btn-chip" id="btn-sold-out">Sold out (0)</button>
                                            <button type="button" class="btn btn-xs btn-chip" id="btn-all-rooms">All rooms</button>
                                        </div>
                                        <div id="units_helper" class="field-helper">Leave blank to keep the current value</div>
                                        <div class="field-error" id="error_available_units" role="alert"></div>
                                    </div>

                                    <!-- Price in JPY -->
                                    <div class="form-group">
                                        <label for="price" class="form-label">
                                            <span>Price</span>
                                            <span class="label-ja">販売料金</span>
                                        </label>
                                        <div class="price-input-wrapper">
                                            <span class="currency-prefix" aria-hidden="true">&yen;</span>
                                            <input type="text" name="price" id="price" class="form-input input-price" inputmode="numeric" placeholder="e.g. 12,000" aria-describedby="price_helper price_comparison error_price">
                                        </div>
                                        <div id="price_helper" class="field-helper">Leave blank to keep the current price</div>
                                        <div id="price_comparison" class="price-comparison" aria-live="polite"></div>
                                        <div class="field-error" id="error_price" role="alert"></div>
                                    </div>
                                </fieldset>

                                <!-- Summary & Save Area (Sticky on mobile) -->
                                <div class="form-summary-card">
                                    <div class="summary-box">
                                        <span class="summary-eyebrow">Update summary</span>
                                        <p id="form_live_summary" class="summary-text" aria-live="polite">Select a room and dates to see update summary</p>
                                    </div>

                                    <!-- Distribution Channel Target Chips -->
                                    <div class="channel-distribution-line">
                                        <span class="distribution-label">Will be sent to:</span>
                                        <div class="channel-chips-list">
                                            <?php if ($activeCount > 0): ?>
                                                <?php foreach ($activeChannels as $channel): ?>
                                                    <span class="channel-chip">
                                                        <span class="chip-dot" aria-hidden="true"></span>
                                                        <span><?= htmlspecialchars($channel['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="warning-text">No booking sites currently active</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($activeCount === 0): ?>
                                        <div class="banner banner-warning" role="alert">
                                            No booking sites are currently active. Updates cannot be distributed.
                                        </div>
                                    <?php endif; ?>

                                    <!-- Submit Button -->
                                    <div class="submit-actions">
                                        <button type="submit" id="submit_btn" class="btn btn-primary btn-save" <?= ($activeCount === 0) ? 'disabled' : '' ?>>
                                            <span class="spinner u-hidden" id="submit_spinner" aria-hidden="true"></span>
                                            <span id="submit_btn_text">Save and send to <?= $activeCount ?> site<?= $activeCount === 1 ? '' : 's' ?></span>
                                        </button>
                                        <button type="button" id="btn-start-over" class="btn btn-text btn-start-over u-hidden">Start over</button>
                                    </div>

                                    <!-- Result Card (in polite aria-live container) -->
                                    <div id="result-card" class="result-card u-hidden" role="status" aria-live="polite">
                                        <div class="result-content">
                                            <span id="result-icon" class="result-icon" aria-hidden="true"></span>
                                            <div class="result-text-group">
                                                <p id="result-message" class="result-message"></p>
                                                <div id="result-links" class="result-links"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </section>
                    </div>

                    <!-- Column 2: Recent Updates Panel -->
                    <div class="update-recent-column">
                        <section class="card recent-card" aria-labelledby="recent-heading">
                            <div class="card-header recent-header">
                                <div>
                                    <h2 id="recent-heading" class="card-title">Recent updates</h2>
                                    <p class="card-subtitle">Times are in Japan time (JST)</p>
                                </div>
                                <span id="recent-stale-notice" class="badge badge-subtle u-hidden" title="Recent updates may be out of date">may be out of date</span>
                            </div>

                            <div class="table-wrapper">
                                <table class="recent-table" aria-label="Recent Inventory Updates">
                                    <thead>
                                        <tr>
                                            <th scope="col">Time</th>
                                            <th scope="col">Room</th>
                                            <th scope="col">Dates</th>
                                            <th scope="col">Change</th>
                                            <th scope="col">Delivery</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recent_updates_tbody">
                                        <!-- Rendered dynamically by app.js; server fallback provided -->
                                        <?php if (!empty($recentUpdates)): ?>
                                            <?php foreach ($recentUpdates as $update): ?>
                                                <?php
                                                $uTotal = (int) ($update['jobs_total'] ?? $update['total_jobs']);
                                                $uPending = (int) ($update['jobs_pending'] ?? $update['pending_jobs']);
                                                $uSuccess = (int) ($update['jobs_success'] ?? 0);
                                                $uFailed = (int) ($update['jobs_failed'] ?? 0);
                                                $uDead = (int) ($update['jobs_dead'] ?? 0);

                                                if ($uDead > 0) {
                                                    $badgeClass = 'badge-danger';
                                                    $badgeText = "✖ Needs attention {$uDead}/{$uTotal}";
                                                    $badgeLink = '/log.php?status=dead';
                                                } elseif ($uFailed > 0) {
                                                    $badgeClass = 'badge-warning';
                                                    $badgeText = "⚠ Retrying {$uFailed}/{$uTotal}";
                                                    $badgeLink = null;
                                                } elseif ($uPending > 0) {
                                                    $badgeClass = 'badge-info';
                                                    $badgeText = "⟳ Sending {$uPending}/{$uTotal}";
                                                    $badgeLink = null;
                                                } elseif ($uTotal > 0 && $uSuccess === $uTotal) {
                                                    $badgeClass = 'badge-success';
                                                    $badgeText = "✔ Delivered {$uSuccess}/{$uTotal}";
                                                    $badgeLink = null;
                                                } else {
                                                    $badgeClass = 'badge-muted';
                                                    $badgeText = '–';
                                                    $badgeLink = null;
                                                }
                                                ?>
                                                <tr>
                                                    <td class="col-time">
                                                        <div class="time-primary"><?= htmlspecialchars(date('H:i', strtotime(Time::utcToJst($update['created_at']))), ENT_QUOTES, 'UTF-8') ?></div>
                                                        <div class="time-id">#<?= (int) $update['id'] ?></div>
                                                    </td>
                                                    <td class="col-room">
                                                        <strong><?= htmlspecialchars($update['room_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    </td>
                                                    <td class="col-dates">
                                                        <?= htmlspecialchars($update['date_from'] . ' → ' . $update['date_to'], ENT_QUOTES, 'UTF-8') ?>
                                                    </td>
                                                    <td class="col-change">
                                                        <div class="change-chips">
                                                            <?php if ($update['available_units'] !== null): ?>
                                                                <span class="chip chip-sm"><?= (int) $update['available_units'] ?> rooms</span>
                                                            <?php endif; ?>
                                                            <?php if ($update['price'] !== null): ?>
                                                                <span class="chip chip-sm">¥<?= number_format((float) $update['price']) ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($update['available_units'] === null && $update['price'] === null): ?>
                                                                <span class="chip chip-muted">No change</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="col-delivery">
                                                        <?php if ($badgeLink !== null): ?>
                                                            <a href="<?= $badgeLink ?>" class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8') ?></a>
                                                        <?php else: ?>
                                                            <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr class="empty-row">
                                                <td colspan="5" class="empty-cell">
                                                    No updates yet. Your first change will appear here.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="recent-footer">
                                <a href="/log.php" class="view-all-link">View all in the sync log &rarr;</a>
                            </div>
                        </section>
                    </div>
                </div>
            <?php endif; ?>
        </main>

        <!-- Shared Footer Partial -->
        <?php require __DIR__ . '/partials/footer.php'; ?>
    </div>

    <!-- Scripts (Vendored jQuery 3.7.1 + Client Controller) -->
    <script src="/assets/js/vendor/jquery.min.js?v=<?= filemtime(__DIR__ . '/assets/js/vendor/jquery.min.js') ?>" defer></script>
    <script src="/assets/js/header-status.js?v=<?= filemtime(__DIR__ . '/assets/js/header-status.js') ?>" defer></script>
    <script src="/assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>" defer></script>
</body>
</html>
