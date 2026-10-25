/**
 * Sync Guard - Insights & Forecast Calendar Poller
 *
 * Implements operations console dashboard analytics:
 * 1. Time to Consistency metrics & distribution.
 * 2. 14-day room availability and worst-of risk forecast calendar.
 * 3. 24-hour delivery activity SVG chart (inline DOM creation, zero libraries).
 * 4. Recent activity audit feed.
 *
 * ARCHITECTURE INVARIANTS:
 * - Independent polling chains with setTimeout after each response.
 * - Out-of-order response rejection with request sequence counters.
 * - Strict DOM textContent and createElement architecture without raw HTML sinks.
 * - Single-chain chained timeouts without periodic timer loops.
 * - All SVGs constructed via document.createElementNS.
 */

window.SyncGuard = window.SyncGuard || {};

$(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';

    // -------------------------------------------------------------
    // Insights Poller State (15s interval)
    // -------------------------------------------------------------
    var insightsIntervalMs = 15000;
    var baseInsightsIntervalMs = 15000;
    var maxInsightsIntervalMs = 60000;
    var insightsTimerId = null;
    var insightsRequestId = 0;
    var isInsightsActive = true;
    var knownAttemptIds = {};

    // -------------------------------------------------------------
    // Calendar Poller State (15s interval)
    // -------------------------------------------------------------
    var calendarIntervalMs = 15000;
    var baseCalendarIntervalMs = 15000;
    var maxCalendarIntervalMs = 60000;
    var calendarTimerId = null;
    var calendarRequestId = 0;
    var isCalendarActive = true;
    var selectedDays = 14;

    // DOM Elements
    var $consistencyMedian = $('#consistency-median');
    var $consistencyAvg = $('#consistency-avg');
    var $consistencyP95 = $('#consistency-p95');
    var $consistencyMax = $('#consistency-max');
    var $consistencySyncStatus = $('#consistency-sync-status');
    var $chartContainer = $('#deliveries-chart-container');
    var $chartStaleIndicator = $('#chart-stale-indicator');
    var $deliveriesHiddenTbody = $('#deliveries-hidden-tbody');
    var $recentActivityList = $('#recent-activity-list');
    var $recentActivityEmpty = $('#recent-activity-empty');
    var $calendarDaysDisplay = $('#calendar-days-display');
    var $calendarStaleIndicator = $('#calendar-stale-indicator');
    var $calendarThead = $('#calendar-thead');
    var $calendarTbody = $('#calendar-tbody');
    var $calendarEmpty = $('#calendar-empty');
    var $calendarTable = $('#calendar-table');

    /**
     * Format duration in seconds for consistency card.
     */
    function formatConsistencySeconds(seconds) {
        if (seconds === null || seconds === undefined) {
            return '--';
        }
        if (seconds < 60) {
            return seconds + ' s';
        }
        var mins = Math.floor(seconds / 60);
        var rem = seconds % 60;
        if (mins < 60) {
            return rem > 0 ? mins + ' min ' + rem + ' s' : mins + ' min';
        }
        var hours = Math.round(mins / 60);
        return 'about ' + hours + ' h';
    }

    /**
     * Format currency in Japanese Yen safely.
     */
    function formatYen(amount) {
        var num = parseInt(amount, 10);
        if (isNaN(num)) {
            return '¥0';
        }
        if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
            try {
                return new Intl.NumberFormat('ja-JP', { maximumFractionDigits: 0 }).format(num);
            } catch (e) {
                // fallback
            }
        }
        return '¥' + num;
    }

    /**
     * Parse days from URL hash or default to 14.
     */
    function readDaysFromHash() {
        var hash = window.location.hash || '';
        var match = hash.match(/days=(7|14|28)/);
        if (match && match[1]) {
            return parseInt(match[1], 10);
        }
        return 14;
    }

    /**
     * Update range selector buttons active class.
     */
    function updateRangeButtons(days) {
        $('.btn-range').each(function () {
            var $btn = $(this);
            var btnDays = parseInt($btn.attr('data-days'), 10);
            if (btnDays === days) {
                $btn.addClass('active');
            } else {
                $btn.removeClass('active');
            }
        });
        $calendarDaysDisplay.text(days);
    }

    // -------------------------------------------------------------
    // 1. Consistency Card Rendering
    // -------------------------------------------------------------
    function renderConsistency(cons) {
        if (!cons || cons.sample_size === 0) {
            $consistencyMedian.text('--');
            $consistencyAvg.text('--');
            $consistencyP95.text('--');
            $consistencyMax.text('--');
            $consistencySyncStatus.text('No completed updates in the last 24 hours.');
            return;
        }

        $consistencyMedian.text(formatConsistencySeconds(cons.median_seconds));
        $consistencyAvg.text(formatConsistencySeconds(cons.avg_seconds));

        if (cons.enough_for_p95) {
            $consistencyP95.text(formatConsistencySeconds(cons.p95_seconds));
        } else {
            $consistencyP95.text('Not enough data yet');
        }

        $consistencyMax.text(formatConsistencySeconds(cons.max_seconds));

        if (cons.unsynced_updates > 0) {
            var ageText = cons.oldest_unsynced_seconds !== null
                ? formatConsistencySeconds(cons.oldest_unsynced_seconds)
                : 'unknown';
            $consistencySyncStatus.text(cons.unsynced_updates + ' update(s) not yet fully synced (oldest ' + ageText + ')');
        } else {
            $consistencySyncStatus.text('Everything is in sync.');
        }
    }

    // -------------------------------------------------------------
    // 2. Deliveries Stacked Chart (Pure createElementNS)
    // -------------------------------------------------------------
    function renderDeliveriesChart(hourly) {
        $chartContainer.empty();
        $deliveriesHiddenTbody.empty();

        if (!hourly || hourly.length === 0) {
            var $empty = $('<div>', { 'class': 'empty-state' }).text('No delivery activity in the last 24 hours.');
            $chartContainer.append($empty);
            return;
        }

        var totalSuccess = 0;
        var totalFailed = 0;
        var maxTotal = 0;

        hourly.forEach(function (h) {
            totalSuccess += h.success;
            totalFailed += h.failed;
            var sum = h.success + h.failed;
            if (sum > maxTotal) {
                maxTotal = sum;
            }

            // Screen reader table row
            var $tr = $('<tr>');
            $tr.append($('<td>').text(h.hour_jst));
            $tr.append($('<td>').text(h.success));
            $tr.append($('<td>').text(h.failed));
            $deliveriesHiddenTbody.append($tr);
        });

        // Update container accessibility summary
        var summaryText = totalSuccess + ' delivered, ' + totalFailed + ' failed in the last 24 hours';
        $chartContainer.attr('aria-label', summaryText);

        // Chart dimensions
        var svgWidth = 600;
        var svgHeight = 220;
        var paddingLeft = 40;
        var paddingRight = 15;
        var paddingTop = 20;
        var paddingBottom = 35;
        var plotWidth = svgWidth - paddingLeft - paddingRight;
        var plotHeight = svgHeight - paddingTop - paddingBottom;

        var yMax = Math.max(maxTotal, 5);
        // Round yMax to a friendly step
        yMax = Math.ceil(yMax / 5) * 5;

        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + svgWidth + ' ' + svgHeight);
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svg.setAttribute('role', 'presentation');

        // Pattern for failed bars (so failure is never signaled by color alone)
        var defs = document.createElementNS(SVG_NS, 'defs');
        var pattern = document.createElementNS(SVG_NS, 'pattern');
        pattern.setAttribute('id', 'hatch-failed');
        pattern.setAttribute('width', '6');
        pattern.setAttribute('height', '6');
        pattern.setAttribute('patternUnits', 'userSpaceOnUse');
        pattern.setAttribute('patternTransform', 'rotate(45)');

        var patRect = document.createElementNS(SVG_NS, 'rect');
        patRect.setAttribute('width', '6');
        patRect.setAttribute('height', '6');
        patRect.setAttribute('fill', '#f87171');
        pattern.appendChild(patRect);

        var patLine = document.createElementNS(SVG_NS, 'line');
        patLine.setAttribute('x1', '0');
        patLine.setAttribute('y1', '0');
        patLine.setAttribute('x2', '0');
        patLine.setAttribute('y2', '6');
        patLine.setAttribute('stroke', '#7f1d1d');
        patLine.setAttribute('stroke-width', '2');
        pattern.appendChild(patLine);

        defs.appendChild(pattern);
        svg.appendChild(defs);

        // Grid lines (3 horizontal lines)
        for (var step = 0; step <= 2; step++) {
            var val = (yMax / 2) * step;
            var yPos = paddingTop + plotHeight - (val / yMax) * plotHeight;

            var gridLine = document.createElementNS(SVG_NS, 'line');
            gridLine.setAttribute('x1', paddingLeft.toString());
            gridLine.setAttribute('y1', yPos.toString());
            gridLine.setAttribute('x2', (paddingLeft + plotWidth).toString());
            gridLine.setAttribute('y2', yPos.toString());
            gridLine.setAttribute('stroke', 'rgba(71, 85, 105, 0.4)');
            gridLine.setAttribute('stroke-dasharray', '2,2');
            svg.appendChild(gridLine);

            var yLabel = document.createElementNS(SVG_NS, 'text');
            yLabel.setAttribute('x', (paddingLeft - 8).toString());
            yLabel.setAttribute('y', (yPos + 4).toString());
            yLabel.setAttribute('text-anchor', 'end');
            yLabel.setAttribute('fill', '#94a3b8');
            yLabel.setAttribute('font-size', '10');
            yLabel.setAttribute('font-family', 'var(--font-mono)');
            yLabel.textContent = val.toString();
            svg.appendChild(yLabel);
        }

        // Draw 24 hourly bars
        var numBars = hourly.length;
        var barSlot = plotWidth / numBars;
        var barWidth = Math.max(barSlot - 4, 3);

        hourly.forEach(function (h, idx) {
            var xPos = paddingLeft + (idx * barSlot) + (barSlot - barWidth) / 2;
            var succH = (h.success / yMax) * plotHeight;
            var failH = (h.failed / yMax) * plotHeight;

            // Success bar (at bottom)
            if (succH > 0) {
                var succRect = document.createElementNS(SVG_NS, 'rect');
                succRect.setAttribute('x', xPos.toString());
                succRect.setAttribute('y', (paddingTop + plotHeight - succH).toString());
                succRect.setAttribute('width', barWidth.toString());
                succRect.setAttribute('height', succH.toString());
                succRect.setAttribute('fill', '#34d399');
                succRect.setAttribute('rx', '1');

                var titleEl = document.createElementNS(SVG_NS, 'title');
                titleEl.textContent = h.hour_jst + ' - ' + h.success + ' delivered';
                succRect.appendChild(titleEl);

                svg.appendChild(succRect);
            }

            // Failed bar (stacked on top of success)
            if (failH > 0) {
                var failRect = document.createElementNS(SVG_NS, 'rect');
                failRect.setAttribute('x', xPos.toString());
                failRect.setAttribute('y', (paddingTop + plotHeight - succH - failH).toString());
                failRect.setAttribute('width', barWidth.toString());
                failRect.setAttribute('height', failH.toString());
                failRect.setAttribute('fill', 'url(#hatch-failed)');
                failRect.setAttribute('stroke', '#f87171');
                failRect.setAttribute('stroke-width', '1');
                failRect.setAttribute('rx', '1');

                var titleElFail = document.createElementNS(SVG_NS, 'title');
                titleElFail.textContent = h.hour_jst + ' - ' + h.failed + ' failed';
                failRect.appendChild(titleElFail);

                svg.appendChild(failRect);
            }

            // X-axis label every 4 hours
            if (idx % 4 === 0 || idx === numBars - 1) {
                var xLabel = document.createElementNS(SVG_NS, 'text');
                xLabel.setAttribute('x', (xPos + barWidth / 2).toString());
                xLabel.setAttribute('y', (paddingTop + plotHeight + 18).toString());
                xLabel.setAttribute('text-anchor', 'middle');
                xLabel.setAttribute('fill', '#94a3b8');
                xLabel.setAttribute('font-size', '10');
                xLabel.setAttribute('font-family', 'var(--font-mono)');
                xLabel.textContent = h.hour_jst;
                svg.appendChild(xLabel);
            }
        });

        $chartContainer[0].appendChild(svg);
    }

    // -------------------------------------------------------------
    // 3. Recent Activity List
    // -------------------------------------------------------------
    function renderRecentActivity(recent) {
        $recentActivityList.empty();

        if (!recent || recent.length === 0) {
            $recentActivityEmpty.removeClass('u-hidden');
            return;
        }

        $recentActivityEmpty.addClass('u-hidden');

        recent.forEach(function (item) {
            var $li = $('<li>', { 'class': 'recent-item' });

            // Detect if this is a newly arrived attempt
            var isNew = knownAttemptIds[item.attempt_id] === undefined && Object.keys(knownAttemptIds).length > 0;
            knownAttemptIds[item.attempt_id] = true;
            if (isNew) {
                $li.addClass('highlight-new');
                setTimeout(function () {
                    $li.removeClass('highlight-new');
                }, 1500);
            }

            // Left side: status icon, title line, and time line
            var $left = $('<div>', { 'class': 'recent-left' });
            var icon = '✔';
            var iconClass = 'badge-success';
            if (item.outcome === 'timeout') {
                icon = '⏱';
                iconClass = 'badge-warning';
            } else if (item.outcome === 'http_error') {
                icon = '⚠️';
                iconClass = 'badge-danger';
            } else if (item.outcome === 'rejected') {
                icon = '✖';
                iconClass = 'badge-danger';
            }

            var $iconBadge = $('<span>', { 'class': 'badge ' + iconClass }).text(icon + ' ' + item.label);
            var $metaMain = $('<div>', { 'class': 'recent-meta-main' });
            var $titleLine = $('<div>', { 'class': 'recent-title-line' }).text(item.channel + ' · ' + item.room_name);
            var $timeLine = $('<div>', { 'class': 'recent-time-line' }).text(item.when_jst + ' (' + item.seconds_ago + 's ago) · ' + item.duration_ms + 'ms');

            $metaMain.append($titleLine).append($timeLine);
            $left.append($iconBadge).append($metaMain);

            // Right side: Job link
            var $right = $('<div>', { 'class': 'recent-right' });
            var safeJobId = parseInt(item.job_id, 10);
            var safeChId = parseInt(item.channel_id, 10);
            var linkUrl = '/log.php';
            if (!isNaN(safeChId) && safeChId > 0) {
                linkUrl = '/log.php?channel_id=' + encodeURIComponent(safeChId);
            }

            var $jobLink = $('<a>', {
                'class': 'card-link',
                'href': linkUrl
            }).text('Job #' + (isNaN(safeJobId) ? '' : safeJobId));
            $right.append($jobLink);

            $li.append($left).append($right);
            $recentActivityList.append($li);
        });
    }

    // -------------------------------------------------------------
    // 4. Calendar Matrix Rendering
    // -------------------------------------------------------------
    function renderCalendar(data) {
        $calendarThead.empty();
        $calendarTbody.empty();

        if (!data || !data.rooms || data.rooms.length === 0) {
            $calendarEmpty.removeClass('u-hidden');
            $calendarTable.addClass('u-hidden');
            return;
        }

        $calendarEmpty.addClass('u-hidden');
        $calendarTable.removeClass('u-hidden');

        // Header row
        var $headTr = $('<tr>');
        $headTr.append($('<th>', { 'scope': 'col', 'class': 'col-sticky-room' }).text('Room'));

        data.dates.forEach(function (d) {
            var parts = d.date.split('-');
            var dayNum = parts.length === 3 ? parts[2] : d.date;
            var thClass = 'calendar-th' + (d.is_weekend ? ' is-weekend' : '');
            var $th = $('<th>', { 'scope': 'col', 'class': thClass });
            $th.text(dayNum + ' ' + d.weekday);
            $headTr.append($th);
        });
        $calendarThead.append($headTr);

        // Body rows per room
        data.rooms.forEach(function (room) {
            var $tr = $('<tr>');
            $tr.append($('<th>', { 'scope': 'row', 'class': 'col-sticky-room' }).text(room.room_name));

            room.cells.forEach(function (cell) {
                var $td = $('<td>', { 'class': 'calendar-cell' });

                var stateClass = 'cell-synced';
                var icon = '✔';
                if (cell.state === 'dead') {
                    stateClass = 'cell-dead';
                    icon = '✖';
                } else if (cell.state === 'failed') {
                    stateClass = 'cell-failed';
                    icon = '⚠';
                } else if (cell.state === 'syncing') {
                    stateClass = 'cell-syncing';
                    icon = '⟳';
                }

                $td.addClass(stateClass);
                if (cell.source === 'default') {
                    $td.addClass('cell-muted');
                    $td.attr('title', 'No changes (default values)');
                }

                var priceFormatted = formatYen(cell.price);
                var safeRoomId = parseInt(room.room_id, 10);
                var cellHref = '/log.php?room_id=' + encodeURIComponent(safeRoomId);
                if (cell.state !== 'synced') {
                    cellHref += '&status=unsynced';
                }

                // Accessible name for screen reader
                var ariaText = room.room_name + ', ' + cell.date + ': ' + cell.units + ' units, ' + priceFormatted;
                if (cell.channels && cell.channels.length > 0) {
                    ariaText += ', problem on ' + cell.channels.join(', ');
                }

                var $link = $('<a>', {
                    'class': 'calendar-cell-link',
                    'href': cellHref,
                    'aria-label': ariaText
                });

                var $unitsDiv = $('<div>', { 'class': 'cell-units' })
                    .append($('<span>', { 'class': 'cell-icon' }).text(icon))
                    .append(document.createTextNode(cell.units + ' units'));

                var $priceDiv = $('<div>', { 'class': 'cell-price' }).text(priceFormatted);

                $link.append($unitsDiv).append($priceDiv);
                $td.append($link);
                $tr.append($td);
            });

            $calendarTbody.append($tr);
        });
    }

    // -------------------------------------------------------------
    // Polling Chain 1: Insights API (15s)
    // -------------------------------------------------------------
    function fetchInsights() {
        if (!isInsightsActive) {
            return;
        }

        var thisRequestId = ++insightsRequestId;

        $.ajax({
            url: '/api/insights.php',
            method: 'GET',
            dataType: 'json',
            cache: false,
            timeout: 10000,
            success: function (res) {
                if (thisRequestId !== insightsRequestId) {
                    return; // Ignore out-of-order response
                }
                if (res && res.ok && res.data) {
                    insightsIntervalMs = baseInsightsIntervalMs;
                    $chartStaleIndicator.addClass('u-hidden');

                    renderConsistency(res.data.consistency);
                    renderDeliveriesChart(res.data.hourly);
                    renderRecentActivity(res.data.recent);
                }
            },
            error: function () {
                if (thisRequestId !== insightsRequestId) {
                    return;
                }
                // Back off: 15s -> 30s -> 60s
                insightsIntervalMs = Math.min(insightsIntervalMs * 2, maxInsightsIntervalMs);
                $chartStaleIndicator.removeClass('u-hidden');
            },
            complete: function () {
                if (thisRequestId !== insightsRequestId) {
                    return;
                }
                if (isInsightsActive) {
                    insightsTimerId = setTimeout(fetchInsights, insightsIntervalMs);
                }
            }
        });
    }

    // -------------------------------------------------------------
    // Polling Chain 2: Calendar API (15s)
    // -------------------------------------------------------------
    function fetchCalendar() {
        if (!isCalendarActive) {
            return;
        }

        var thisRequestId = ++calendarRequestId;

        $.ajax({
            url: '/api/calendar.php?days=' + encodeURIComponent(selectedDays),
            method: 'GET',
            dataType: 'json',
            cache: false,
            timeout: 10000,
            success: function (res) {
                if (thisRequestId !== calendarRequestId) {
                    return;
                }
                if (res && res.ok && res.data) {
                    calendarIntervalMs = baseCalendarIntervalMs;
                    $calendarStaleIndicator.addClass('u-hidden');
                    renderCalendar(res.data);
                }
            },
            error: function () {
                if (thisRequestId !== calendarRequestId) {
                    return;
                }
                // Back off: 15s -> 30s -> 60s
                calendarIntervalMs = Math.min(calendarIntervalMs * 2, maxCalendarIntervalMs);
                $calendarStaleIndicator.removeClass('u-hidden');
            },
            complete: function () {
                if (thisRequestId !== calendarRequestId) {
                    return;
                }
                if (isCalendarActive) {
                    calendarTimerId = setTimeout(fetchCalendar, calendarIntervalMs);
                }
            }
        });
    }

    // -------------------------------------------------------------
    // Calendar Range Buttons & Hash Navigation
    // -------------------------------------------------------------
    $('.btn-range').on('click', function () {
        var days = parseInt($(this).attr('data-days'), 10);
        if ([7, 14, 28].indexOf(days) !== -1 && days !== selectedDays) {
            selectedDays = days;
            window.location.hash = 'days=' + days;
            updateRangeButtons(days);
            window.SyncGuard.refreshCalendar();
        }
    });

    window.addEventListener('hashchange', function () {
        var days = readDaysFromHash();
        if (days !== selectedDays) {
            selectedDays = days;
            updateRangeButtons(days);
            window.SyncGuard.refreshCalendar();
        }
    });

    // Initialize days from hash
    selectedDays = readDaysFromHash();
    updateRangeButtons(selectedDays);

    // -------------------------------------------------------------
    // Tab Visibility Handling
    // -------------------------------------------------------------
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            isInsightsActive = false;
            isCalendarActive = false;
            if (insightsTimerId !== null) {
                clearTimeout(insightsTimerId);
                insightsTimerId = null;
            }
            if (calendarTimerId !== null) {
                clearTimeout(calendarTimerId);
                calendarTimerId = null;
            }
        } else {
            isInsightsActive = true;
            isCalendarActive = true;
            insightsIntervalMs = baseInsightsIntervalMs;
            calendarIntervalMs = baseCalendarIntervalMs;
            fetchInsights();
            fetchCalendar();
        }
    });

    // -------------------------------------------------------------
    // Public Refresh Exposure
    // -------------------------------------------------------------
    window.SyncGuard.refreshInsights = function () {
        if (insightsTimerId !== null) {
            clearTimeout(insightsTimerId);
            insightsTimerId = null;
        }
        insightsIntervalMs = baseInsightsIntervalMs;
        fetchInsights();
    };

    window.SyncGuard.refreshCalendar = function () {
        if (calendarTimerId !== null) {
            clearTimeout(calendarTimerId);
            calendarTimerId = null;
        }
        calendarIntervalMs = baseCalendarIntervalMs;
        fetchCalendar();
    };

    // Stagger initial fetches so all three requests don't hit the server at the exact same millisecond
    setTimeout(fetchInsights, 200);
    setTimeout(fetchCalendar, 400);
});
