/**
 * Sync Guard - Mock Booking Partner Console Controller
 *
 * Implements:
 * 1. 5-second polling cycle using setTimeout after response completion (never setInterval)
 * 2. Tab visibility pausing and resumption
 * 3. Exponential error backoff (5s, 10s, 20s, 30s) with "May be out of date" banner
 * 4. Out-of-order response discarding via incrementing request IDs
 * 5. Safe DOM updates strictly using textContent and createElement
 * 6. Highlighting new rows briefly (respects prefers-reduced-motion)
 */

$(function () {
    'use strict';

    var pollIntervalMs = 5000;
    var baseIntervalMs = 5000;
    var maxIntervalMs = 30000;
    var timerId = null;
    var isPollingActive = true;
    var requestId = 0;
    var lastFetchTime = Date.now();

    // DOM Elements
    var $receivedCount = $('#stat-received-count');
    var $duplicateCount = $('#stat-duplicate-count');
    var $droppedCount = $('#stat-dropped-count');
    var $lastReceivedText = $('#stat-last-received');
    var $liveSecondsText = $('#live-seconds-text');
    var $staleNotice = $('#live-stale-notice');
    var $tbody = $('#mock-updates-tbody');
    var $tableCard = $('#mock-table-card');
    var $emptyState = $('#mock-empty-state');
    var $currentModeBadge = $('#current-mode-badge');

    // Known updates cache by idempotency key to detect new arrivals
    var seenKeys = {};
    $('#mock-updates-tbody tr.mock-row').each(function () {
        var k = $(this).attr('data-key');
        if (k) seenKeys[k] = true;
    });

    function formatSecondsAgo(sec) {
        if (sec === null || sec === undefined || isNaN(sec)) return 'Never';
        if (sec < 60) return sec + 's ago';
        var min = Math.floor(sec / 60);
        if (min < 60) return min + ' min ago';
        var hr = Math.floor(min / 60);
        return hr + ' hr ' + (min % 60) + ' min ago';
    }

    function formatYen(val) {
        if (val === null || val === undefined || val === '') return '—';
        var num = parseFloat(val);
        if (isNaN(num)) return '—';
        return '¥' + Math.round(num).toLocaleString('ja-JP');
    }

    function formatDates(from, to) {
        if (!from || !to) return '—';
        try {
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var p1 = from.split('-');
            var p2 = to.split('-');
            if (p1.length === 3 && p2.length === 3) {
                var m1 = months[parseInt(p1[1], 10) - 1] || p1[1];
                var m2 = months[parseInt(p2[1], 10) - 1] || p2[1];
                return parseInt(p1[2], 10) + ' ' + m1 + ' to ' + parseInt(p2[2], 10) + ' ' + m2;
            }
        } catch (e) {}
        return from + ' to ' + to;
    }

    // Room names mapping fallback
    var roomNames = {
        '1': 'シングルルーム (Single Room)',
        '2': 'ツインルーム (Twin Room)',
        '3': '和室 (Japanese-style Room)',
        '4': 'スイート (Suite)'
    };

    function renderRow(item, isNew) {
        var tr = document.createElement('tr');
        tr.className = 'mock-row' + (isNew ? ' updated-highlight' : '');
        var key = item.idempotency_key || '';
        tr.setAttribute('data-key', key);

        // 1. Time (HH:MM:SS JST with relative age)
        var tdTime = document.createElement('td');
        tdTime.setAttribute('data-label', 'Received At');
        var timeStr = '—';
        if (item.received_at) {
            var ts = new Date(item.received_at.indexOf('Z') === -1 && item.received_at.indexOf('+') === -1 ? item.received_at.replace(' ', 'T') + 'Z' : item.received_at).getTime();
            if (!isNaN(ts)) {
                var d = new Date(ts);
                var jstH = String((d.getUTCHours() + 9) % 24).padStart(2, '0');
                var jstM = String(d.getUTCMinutes()).padStart(2, '0');
                var jstS = String(d.getUTCSeconds()).padStart(2, '0');
                var elapsedSec = Math.max(0, Math.round((Date.now() - ts) / 1000));
                var ageText = elapsedSec < 60 ? elapsedSec + 's ago' : (elapsedSec < 3600 ? Math.floor(elapsedSec / 60) + ' min ago' : Math.floor(elapsedSec / 3600) + ' hr ago');
                timeStr = jstH + ':' + jstM + ':' + jstS + ' JST (' + ageText + ')';
            }
        } else if (item.received_at_jst) {
            timeStr = item.received_at_jst;
        }
        tdTime.textContent = timeStr;
        tr.appendChild(tdTime);

        // 2. Room
        var tdRoom = document.createElement('td');
        tdRoom.setAttribute('data-label', 'Room');
        var rName = item.room_name || roomNames[String(item.room_id)] || ('Room #' + (item.room_id || '—'));
        tdRoom.textContent = rName;
        tr.appendChild(tdRoom);

        // 3. Dates
        var tdDates = document.createElement('td');
        tdDates.setAttribute('data-label', 'Dates');
        tdDates.textContent = formatDates(item.date_from, item.date_to);
        tr.appendChild(tdDates);

        // 4. Units
        var tdUnits = document.createElement('td');
        tdUnits.setAttribute('data-label', 'Units');
        if (item.available_units !== null && item.available_units !== undefined && item.available_units !== '') {
            var u = parseInt(item.available_units, 10);
            tdUnits.textContent = u + ' ' + (u === 1 ? 'unit' : 'units');
        } else {
            tdUnits.textContent = '—';
        }
        tr.appendChild(tdUnits);

        // 5. Price
        var tdPrice = document.createElement('td');
        tdPrice.setAttribute('data-label', 'Rate');
        tdPrice.textContent = formatYen(item.price);
        tr.appendChild(tdPrice);

        // 6. Idempotency Key
        var tdKey = document.createElement('td');
        tdKey.setAttribute('data-label', 'Idempotency Key');
        var code = document.createElement('code');
        code.className = 'key-chip';
        code.title = key;
        code.textContent = key.length > 8 ? key.substring(0, 8) : key;
        tdKey.appendChild(code);

        if (item.hit_count && item.hit_count > 1) {
            var chip = document.createElement('span');
            chip.className = 'chip chip-muted';
            chip.textContent = 'duplicate x' + item.hit_count;
            tdKey.appendChild(chip);
        }

        // Raw XML details for Site B
        if (item.raw_xml) {
            var details = document.createElement('details');
            details.className = 'raw-xml-details';
            var summary = document.createElement('summary');
            summary.className = 'raw-xml-summary';
            summary.textContent = 'Show raw XML';
            var pre = document.createElement('pre');
            pre.className = 'raw-xml-pre';
            var codePre = document.createElement('code');
            codePre.textContent = item.raw_xml;
            pre.appendChild(codePre);
            details.appendChild(summary);
            details.appendChild(pre);
            tdKey.appendChild(details);
        }

        tr.appendChild(tdKey);
        return tr;
    }

    function fetchMockTelemetry() {
        if (timerId !== null) {
            clearTimeout(timerId);
            timerId = null;
        }

        var currentReqId = ++requestId;

        $.ajax({
            url: window.location.pathname + '?format=json',
            method: 'GET',
            dataType: 'json',
            timeout: 5000,
            cache: false,
            success: function (data) {
                if (currentReqId !== requestId) return;

                lastFetchTime = Date.now();
                pollIntervalMs = baseIntervalMs;
                $staleNotice.addClass('u-hidden');

                if (data && data.ok) {
                    // Update Stat Counters
                    if ($receivedCount.length && data.received_count !== undefined) {
                        $receivedCount.text(data.received_count);
                    }
                    if ($duplicateCount.length && data.duplicate_hits !== undefined) {
                        $duplicateCount.text(data.duplicate_hits);
                    }
                    if ($droppedCount.length && data.forgotten_count !== undefined) {
                        $droppedCount.text(data.forgotten_count);
                    }

                    // Mode update
                    if ($currentModeBadge.length && data.mode) {
                        $currentModeBadge.text(data.mode);
                    }

                    // Updates Table
                    var updates = Array.isArray(data.recent_updates) ? data.recent_updates : [];
                    if (updates.length > 0) {
                        $tableCard.removeClass('u-hidden');
                        $emptyState.addClass('u-hidden');

                        // Check latest update time for stat
                        var latest = updates[0];
                        if (latest && latest.received_at) {
                            var ts = new Date(latest.received_at + (latest.received_at.indexOf('Z') === -1 ? 'Z' : '')).getTime();
                            if (!isNaN(ts)) {
                                var elapsedSec = Math.max(0, Math.round((Date.now() - ts) / 1000));
                                $lastReceivedText.text(formatSecondsAgo(elapsedSec));
                            }
                        }

                        // Re-render rows if changed
                        var newArrival = false;
                        var frag = document.createDocumentFragment();
                        updates.forEach(function (up) {
                            var isNew = up.idempotency_key && !seenKeys[up.idempotency_key];
                            if (isNew) {
                                newArrival = true;
                                seenKeys[up.idempotency_key] = true;
                            }
                            frag.appendChild(renderRow(up, isNew));
                        });

                        var tbody = $tbody[0];
                        while (tbody.firstChild) {
                            tbody.removeChild(tbody.firstChild);
                        }
                        tbody.appendChild(frag);
                    } else {
                        $tableCard.addClass('u-hidden');
                        $emptyState.removeClass('u-hidden');
                        $lastReceivedText.text('Never');
                    }
                }
            },
            error: function () {
                if (currentReqId !== requestId) return;
                pollIntervalMs = Math.min(pollIntervalMs * 2, maxIntervalMs);
                $staleNotice.removeClass('u-hidden');
            },
            complete: function () {
                if (isPollingActive) {
                    timerId = setTimeout(fetchMockTelemetry, pollIntervalMs);
                }
            }
        });
    }

    // Tick relative seconds indicator every second
    function tickSeconds() {
        var secAgo = Math.max(0, Math.round((Date.now() - lastFetchTime) / 1000));
        $liveSecondsText.text('Updated ' + secAgo + 's ago');
        setTimeout(tickSeconds, 1000);
    }
    tickSeconds();

    // Tab visibility handling
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            isPollingActive = false;
            if (timerId !== null) {
                clearTimeout(timerId);
                timerId = null;
            }
        } else {
            isPollingActive = true;
            fetchMockTelemetry();
        }
    });

    // Initial fetch scheduled after 5 seconds
    timerId = setTimeout(fetchMockTelemetry, pollIntervalMs);
});
