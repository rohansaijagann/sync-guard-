/**
 * Sync Guard - Operational Sync Log Controller
 *
 * Handles live filtering, pagination, expandable attempt histories,
 * and manual job retries with zero raw HTML insertion.
 *
 * WHY ZERO RAW HTML INSERTION (.text() ONLY):
 * External booking channels and hotel staff can submit arbitrary text, unexpected characters,
 * or even exploit payloads (<script>alert(1)</script>). Writing API-supplied content exclusively
 * using jQuery .text() or DOM textContent guarantees that all input is treated strictly as inert
 * characters by the browser engine, completely neutralizing Cross-Site Scripting (XSS) vectors.
 *
 * WHY OUT-OF-ORDER RESPONSES MUST BE IGNORED:
 * When users rapidly click pagination or switch filter dropdowns, multiple asynchronous HTTP
 * requests may be in flight simultaneously. An earlier, slower request could return after a newer,
 * faster request. Tracking an incrementing request counter (currentRequestId) ensures that any
 * response arriving out of sequence is safely discarded.
 *
 * WHY STATE LIVES IN THE URL:
 * Storing the current status, channel, room, and page index in window.location.search allows
 * operational links to be bookmarked, shared between staff members, and supports standard browser
 * navigation (Back/Forward buttons via window.history.pushState and popstate event listeners).
 *
 * WHY DETAILS HISTORY LOADS LAZILY:
 * Most users scan the log table to verify high-level job statuses and error messages. Fetching
 * full attempt histories eagerly for every row on a 50-row page would issue massive payloads.
 * Fetching attempt telemetry on demand only when a user expands a specific row minimizes bandwidth.
 *
 * WHY RETRY RELOADS DATA FROM THE SERVER:
 * When a user clicks Retry, background worker daemons may concurrently claim or update the job.
 * Rather than optimistically updating local UI state, re-fetching the current page from the database
 * guarantees that staff see the true, authoritative queue state.
 */

// NOTE: Authentication and CSRF protection are scheduled for production hardening.

$(function () {
    'use strict';

    // ========================================================================
    // 1. Theme & Wall Mode Managers
    // ========================================================================
    function initTheme() {
        var $themeToggle = $('#theme-toggle');
        var $themeToggleIcon = $('#theme-toggle-icon');
        if (!$themeToggle.length) return;
        if (window.__syncguard_theme_initialized) return;
        window.__syncguard_theme_initialized = true;

        var storedTheme = null;
        try {
            storedTheme = localStorage.getItem('syncguard_theme');
        } catch (e) {}
        var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
        var activeTheme = storedTheme || (prefersLight ? 'light' : 'dark');
        applyTheme(activeTheme);

        $themeToggle.on('click', function () {
            var current = $('html').attr('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try {
                localStorage.setItem('syncguard_theme', next);
            } catch (e) {}
        });

        function applyTheme(theme) {
            $('html').attr('data-theme', theme);
            var isLight = theme === 'light';
            $themeToggle.attr('aria-pressed', isLight ? 'true' : 'false');
            $themeToggleIcon.text(isLight ? '☀️' : '🌙');
        }
    }

    function initWallMode() {
        var $wallModeToggle = $('#wall-mode-toggle');
        if (!$wallModeToggle.length) return;
        if (window.__syncguard_wall_initialized) return;
        window.__syncguard_wall_initialized = true;

        var isWall = false;
        try {
            isWall = localStorage.getItem('syncguard_wall_mode') === 'true';
        } catch (e) {}
        applyWallMode(isWall);

        $wallModeToggle.on('click', function () {
            var active = !$('body').hasClass('wall-mode');
            applyWallMode(active);
            try {
                localStorage.setItem('syncguard_wall_mode', String(active));
            } catch (e) {}
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('body').hasClass('wall-mode')) {
                applyWallMode(false);
                try {
                    localStorage.setItem('syncguard_wall_mode', 'false');
                } catch (err) {}
            }
        });

        function applyWallMode(active) {
            $('body').toggleClass('wall-mode', active);
            $wallModeToggle.attr('aria-pressed', active ? 'true' : 'false');
        }
    }

    // ========================================================================
    // SVG Illustrations Generator
    // ========================================================================
    function initIllustrations() {
        var SVG_NS = 'http://www.w3.org/2000/svg';

        function createSvg(width, height, viewBox) {
            var svg = document.createElementNS(SVG_NS, 'svg');
            svg.setAttribute('width', width);
            svg.setAttribute('height', height);
            svg.setAttribute('viewBox', viewBox);
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '2');
            svg.setAttribute('stroke-linecap', 'round');
            svg.setAttribute('stroke-linejoin', 'round');
            return svg;
        }

        var errContainer = document.getElementById('error-illustration');
        if (errContainer && !errContainer.hasChildNodes()) {
            var errSvg = createSvg('48', '48', '0 0 24 24');
            errSvg.setAttribute('stroke', 'var(--color-danger)');
            var path = document.createElementNS(SVG_NS, 'path');
            path.setAttribute('d', 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z');
            var line1 = document.createElementNS(SVG_NS, 'line');
            line1.setAttribute('x1', '12'); line1.setAttribute('y1', '9'); line1.setAttribute('x2', '12'); line1.setAttribute('y2', '13');
            var line2 = document.createElementNS(SVG_NS, 'line');
            line2.setAttribute('x1', '12'); line2.setAttribute('y1', '17'); line2.setAttribute('x2', '12.01'); line2.setAttribute('y2', '17');
            errSvg.appendChild(path);
            errSvg.appendChild(line1);
            errSvg.appendChild(line2);
            errContainer.appendChild(errSvg);
        }

        var emptyFilterContainer = document.getElementById('empty-filtered-illustration');
        if (emptyFilterContainer && !emptyFilterContainer.hasChildNodes()) {
            var filterSvg = createSvg('48', '48', '0 0 24 24');
            filterSvg.setAttribute('stroke', 'var(--text-muted)');
            var circle = document.createElementNS(SVG_NS, 'circle');
            circle.setAttribute('cx', '11'); circle.setAttribute('cy', '11'); circle.setAttribute('r', '8');
            var line = document.createElementNS(SVG_NS, 'line');
            line.setAttribute('x1', '21'); line.setAttribute('y1', '21'); line.setAttribute('x2', '16.65'); line.setAttribute('y2', '16.65');
            filterSvg.appendChild(circle);
            filterSvg.appendChild(line);
            emptyFilterContainer.appendChild(filterSvg);
        }

        var emptySysContainer = document.getElementById('empty-system-illustration');
        if (emptySysContainer && !emptySysContainer.hasChildNodes()) {
            var sysSvg = createSvg('48', '48', '0 0 24 24');
            sysSvg.setAttribute('stroke', 'var(--text-muted)');
            var rect = document.createElementNS(SVG_NS, 'rect');
            rect.setAttribute('x', '2'); rect.setAttribute('y', '3'); rect.setAttribute('width', '20'); rect.setAttribute('height', '18'); rect.setAttribute('rx', '2');
            var line1 = document.createElementNS(SVG_NS, 'line');
            line1.setAttribute('x1', '8'); line1.setAttribute('y1', '12'); line1.setAttribute('x2', '16'); line1.setAttribute('y2', '12');
            sysSvg.appendChild(rect);
            sysSvg.appendChild(line1);
            emptySysContainer.appendChild(sysSvg);
        }
    }

    // Request sequence tracking
    var activeRequestId = 0;

    // Cache of loaded attempt histories keyed by job_id
    var attemptsCache = {};

    // Set of currently expanded job IDs
    var openDetailsJobIds = {};

    // Current filter and pagination state
    var state = {
        status: 'all',
        channel_id: '',
        room_id: '',
        per_page: 25,
        page: 1
    };

    // DOM Elements
    var $filterStatus = $('#filter-status');
    var $filterChannel = $('#filter-channel');
    var $filterRoom = $('#filter-room');
    var $filterPerPage = $('#filter-per-page');
    var $btnResetFilters = $('#btn-reset-filters');
    var $btnRefresh = $('#btn-refresh');
    var $resultsSummary = $('#results-summary');
    var $loadedAtText = $('#loaded-at-text');
    var $tableSection = $('#table-section');
    var $jobsTbody = $('#jobs-tbody');
    var $loadingState = $('#loading-state');
    var $errorState = $('#error-state');
    var $btnRetryFetch = $('#btn-retry-fetch');
    var $emptyFilteredState = $('#empty-filtered-state');
    var $emptySystemState = $('#empty-system-state');
    var $btnEmptyReset = $('#btn-empty-reset');
    var $paginationNav = $('#pagination-nav');
    var $btnPrevPage = $('#btn-prev-page');
    var $btnNextPage = $('#btn-next-page');
    var $pageNumbers = $('#page-numbers');
    var $pageInfo = $('#page-info');
    var $liveAnnouncer = $('#live-announcer');
    var $urlParamNotice = $('#url-param-notice');
    var $btnDismissNotice = $('#btn-dismiss-notice');

    // Templates
    var jobRowTemplate = document.getElementById('job-row-template');
    var detailsRowTemplate = document.getElementById('details-row-template');
    var attemptRowTemplate = document.getElementById('attempt-row-template');

    /**
     * Announce a message to screen readers via aria-live region.
     */
    function announce(message) {
        $liveAnnouncer.text(message);
    }

    /**
     * Parse query parameters from URL string into state object.
     */
    function parseUrlParams() {
        var params = new URLSearchParams(window.location.search);
        var s = params.get('status');
        var c = params.get('channel_id');
        var r = params.get('room_id');
        var pp = params.get('per_page');
        var p = params.get('page');

        if (s !== null && s !== '') {
            state.status = s;
        }
        if (c !== null && c !== '') {
            state.channel_id = c;
        }
        if (r !== null && r !== '') {
            state.room_id = r;
        }
        if (pp !== null && pp !== '') {
            var ppNum = parseInt(pp, 10);
            if (ppNum === 10 || ppNum === 25 || ppNum === 50) {
                state.per_page = ppNum;
            }
        }
        if (p !== null && p !== '') {
            var pNum = parseInt(p, 10);
            if (pNum >= 1) {
                state.page = pNum;
            }
        }

        $('.status-tab').removeClass('active').attr('aria-pressed', 'false');
        $('.status-tab[data-status="' + state.status + '"]').addClass('active').attr('aria-pressed', 'true');
    }

    /**
     * Sync state object into URL search query string using pushState.
     */
    function syncUrlParams(replace) {
        var params = new URLSearchParams();
        if (state.status !== 'all') {
            params.set('status', state.status);
        }
        if (state.channel_id !== '') {
            params.set('channel_id', state.channel_id);
        }
        if (state.room_id !== '') {
            params.set('room_id', state.room_id);
        }
        if (state.per_page !== 25) {
            params.set('per_page', String(state.per_page));
        }
        if (state.page > 1) {
            params.set('page', String(state.page));
        }

        var newQuery = params.toString();
        var newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '');

        if (replace) {
            window.history.replaceState({ ...state }, '', newUrl);
        } else if (window.location.search !== (newQuery ? '?' + newQuery : '')) {
            window.history.pushState({ ...state }, '', newUrl);
        }
    }

    /**
     * Format currency amount into Japanese Yen display string (e.g. "¥12,000").
     */
    function formatYen(amount) {
        if (amount === null || amount === undefined) {
            return null;
        }
        return '¥' + amount.toLocaleString('ja-JP');
    }

    /**
     * Populate channel and room options dropdowns.
     */
    function updateDropdownOptions(options) {
        if (!options) {
            return;
        }

        // Channels select
        if (options.channels && $filterChannel.children('option').length <= 1) {
            options.channels.forEach(function (ch) {
                var safeId = parseInt(ch.id, 10);
                if (!isNaN(safeId) && safeId > 0) {
                    var label = ch.name + (ch.active ? '' : ' (Paused)');
                    var $opt = $('<option>', { value: String(safeId) }).text(label);
                    $filterChannel.append($opt);
                }
            });
        }

        // Rooms select
        if (options.rooms && $filterRoom.children('option').length <= 1) {
            options.rooms.forEach(function (rm) {
                var safeId = parseInt(rm.id, 10);
                if (!isNaN(safeId) && safeId > 0) {
                    var $opt = $('<option>', { value: String(safeId) }).text(rm.name);
                    $filterRoom.append($opt);
                }
            });
        }
    }

    /**
     * Update status select option labels with counts.
     */
    function updateStatusOptionCounts(counts) {
        if (!counts) {
            return;
        }

        var statusMap = {
            'all': 'All',
            'unsynced': 'Unsynced',
            'pending': 'Pending',
            'in_progress': 'In Progress',
            'failed': 'Failed',
            'dead': 'Dead',
            'success': 'Success'
        };

        $filterStatus.find('option').each(function () {
            var val = $(this).val();
            var baseLabel = statusMap[val] || val;
            var cnt = counts[val] !== undefined ? counts[val] : 0;
            $(this).text(baseLabel + ' (' + cnt + ')');
        });

        // Update status tabs count badges and active states
        $('.status-tab').each(function () {
            var tabStatus = $(this).attr('data-status');
            var cnt = counts[tabStatus] !== undefined ? counts[tabStatus] : 0;
            $(this).find('.tab-count').text(String(cnt));
            if (tabStatus === state.status) {
                $(this).addClass('active').attr('aria-pressed', 'true');
            } else {
                $(this).removeClass('active').attr('aria-pressed', 'false');
            }
        });
    }

    /**
     * Get badge visual styling, icon, and label for job status.
     */
    function getStatusBadgeConfig(status) {
        switch (status) {
            case 'success':
                return { badgeClass: 'badge-success', icon: '●', label: 'Success' };
            case 'pending':
                return { badgeClass: 'badge-info', icon: '⏳', label: 'Pending' };
            case 'in_progress':
                return { badgeClass: 'badge-active', icon: '⚙️', label: 'In Progress' };
            case 'failed':
                return { badgeClass: 'badge-warning', icon: '⚠️', label: 'Failed' };
            case 'dead':
                return { badgeClass: 'badge-danger', icon: '✖', label: 'Dead' };
            default:
                return { badgeClass: 'badge-neutral', icon: '—', label: status };
        }
    }

    /**
     * Build and render attempt history table rows.
     */
    function renderAttemptRows($tbody, attempts) {
        $tbody.empty();
        if (!attempts || attempts.length === 0) {
            var $emptyRow = $('<tr>').append($('<td>', { colspan: '6', 'class': 'text-muted', 'style': 'text-align: center; padding: 1rem;' }).text('No delivery attempts recorded yet.'));
            $tbody.append($emptyRow);
            return;
        }

        attempts.forEach(function (att) {
            var fragment = attemptRowTemplate.content.cloneNode(true);
            var $row = $(fragment).find('.attempt-row');

            $row.find('.att-id').text('#' + att.id);
            $row.find('.att-started').text(att.started_at_jst);
            $row.find('.att-duration').text(att.duration_ms + ' ms');
            $row.find('.att-http').text(att.http_status !== null ? String(att.http_status) : '—');

            var $badge = $row.find('.att-badge');
            if (att.outcome === 'success') {
                $badge.addClass('badge-success');
            } else if (att.outcome === 'timeout') {
                $badge.addClass('badge-warning');
            } else if (att.outcome === 'http_error') {
                $badge.addClass('badge-danger');
            } else {
                $badge.addClass('badge-neutral');
            }
            $badge.text(att.outcome_label);

            $row.find('.att-message').text(att.message || '—');

            $tbody.append($row);
        });
    }

    /**
     * Load attempt history via AJAX for an expandable job row.
     */
    function loadAttemptHistory(jobId, $detailsRow, forceReload) {
        var $loading = $detailsRow.find('.attempts-loading');
        var $error = $detailsRow.find('.attempts-error');
        var $notice = $detailsRow.find('.attempts-truncated-notice');
        var $tbody = $detailsRow.find('.attempts-tbody');

        if (!forceReload && attemptsCache[jobId]) {
            var cached = attemptsCache[jobId];
            if (cached.truncated) {
                $notice.show();
            } else {
                $notice.hide();
            }
            renderAttemptRows($tbody, cached.attempts);
            return;
        }

        $loading.show();
        $error.hide();
        $notice.hide();

        var safeJobId = parseInt(jobId, 10);
        if (isNaN(safeJobId) || safeJobId <= 0) {
            $loading.hide();
            $error.text('Invalid job ID.').show();
            return;
        }

        $.ajax({
            url: '/api/attempts.php?job_id=' + encodeURIComponent(safeJobId),
            method: 'GET',
            dataType: 'json',
            cache: false,
            success: function (res) {
                $loading.hide();
                if (res && res.ok && res.data) {
                    attemptsCache[jobId] = res.data;
                    if (res.data.truncated) {
                        $notice.show();
                    } else {
                        $notice.hide();
                    }
                    renderAttemptRows($tbody, res.data.attempts);
                } else {
                    $error.show();
                }
            },
            error: function () {
                $loading.hide();
                $error.show();
            }
        });
    }

    /**
     * Render the paginated jobs table.
     */
    function renderJobsTable(data) {
        $jobsTbody.empty();

        var items = data.items || [];
        if (items.length === 0) {
            $tableSection.hide();
            $paginationNav.hide();

            if (data.counts && data.counts.all === 0) {
                $emptySystemState.removeClass('log-state-hidden u-hidden').show();
                $emptyFilteredState.hide();
            } else {
                $emptyFilteredState.removeClass('log-state-hidden u-hidden').show();
                $emptySystemState.hide();
            }
            return;
        }

        $emptyFilteredState.hide();
        $emptySystemState.hide();
        $tableSection.removeClass('log-state-hidden u-hidden').show();

        items.forEach(function (job) {
            var jobId = job.job_id;
            var safeJobId = parseInt(jobId, 10);

            // Job Main Row
            var jobFragment = jobRowTemplate.content.cloneNode(true);
            var $row = $(jobFragment).find('.job-row');
            $row.attr('id', 'job-row-' + safeJobId);

            // Highlighting by status
            if (job.status === 'failed') {
                $row.addClass('row-failed');
            } else if (job.status === 'dead') {
                $row.addClass('row-dead');
            } else if (job.status === 'success') {
                $row.addClass('row-success');
            }

            // Cell contents
            $row.find('.job-id-text').text('#' + safeJobId);

            $row.find('.site-name').text(job.channel.name);
            if (!job.channel.active) {
                $row.find('.badge-paused').show();
            }

            $row.find('.room-name').text(job.room.name);
            $row.find('.dates-text').text(job.date_from + ' → ' + job.date_to);

            // Change summary (units and yen)
            var changeParts = [];
            if (job.available_units !== null) {
                changeParts.push(job.available_units + ' unit' + (job.available_units === 1 ? '' : 's'));
            }
            if (job.price !== null) {
                changeParts.push(formatYen(job.price));
            }
            $row.find('.change-text').text(changeParts.length > 0 ? changeParts.join(', ') : '—');

            // Status badge
            var badgeConfig = getStatusBadgeConfig(job.status);
            var $statusBadge = $row.find('.status-badge');
            $statusBadge.addClass(badgeConfig.badgeClass);
            $statusBadge.find('.badge-icon').text(badgeConfig.icon);
            $statusBadge.find('.badge-text').text(badgeConfig.label);

            // Tries
            var maxAttempts = job.max_attempts || 3;
            var attemptCount = job.attempt_count || 0;
            $row.find('.tries-text').text(attemptCount + ' / ' + maxAttempts);
            var pct = Math.min(100, Math.round((attemptCount / Math.max(1, maxAttempts)) * 100));
            $row.find('.tries-fill').css('width', pct + '%');

            // Plain-language message
            $row.find('.message-text').text(job.message);

            // Actions: Details & Retry
            var $btnDetails = $row.find('.btn-details');
            $btnDetails.attr('id', 'btn-details-' + safeJobId);
            $btnDetails.attr('aria-controls', 'details-row-' + safeJobId);

            var $btnRetry = $row.find('.btn-retry');
            $btnRetry.attr('id', 'btn-retry-' + safeJobId);
            $btnRetry.attr('data-job-id', String(safeJobId));

            if (job.can_retry) {
                $btnRetry.removeClass('log-state-hidden u-hidden').show();
            } else {
                $btnRetry.hide();
            }

            // Details Sub-Row
            var detailsFragment = detailsRowTemplate.content.cloneNode(true);
            var $detailsRow = $(detailsFragment).find('.details-row');
            $detailsRow.attr('id', 'details-row-' + safeJobId);
            $detailsRow.attr('aria-labelledby', 'btn-details-' + safeJobId);

            // Populate static detail info
            $detailsRow.find('.detail-update-info').text(
                'Update #' + job.update_id + ' (UUID: ' + job.correlation_id + ')'
            );

            var detailInventory = (job.available_units !== null ? job.available_units + ' available units' : 'Units unchanged')
                + ' | ' + (job.price !== null ? formatYen(job.price) : 'Rate unchanged')
                + ' (' + job.date_from + ' to ' + job.date_to + ')';
            $detailsRow.find('.detail-inventory-info').text(detailInventory);

            if (job.last_error) {
                $detailsRow.find('.detail-last-error').text('"' + job.last_error + '"');
            } else {
                $detailsRow.find('.detail-last-error').text('None recorded');
            }

            // Check if details row was previously open
            if (openDetailsJobIds[safeJobId]) {
                $row.addClass('details-open');
                $btnDetails.attr('aria-expanded', 'true').text('Hide Details');
                $detailsRow.removeClass('log-state-hidden u-hidden').show();
                loadAttemptHistory(safeJobId, $detailsRow, true);
            }

            // Append both rows to table body
            $jobsTbody.append($row);
            $jobsTbody.append($detailsRow);
        });

        // Render Pagination Controls
        renderPagination(data.pagination);
    }

    /**
     * Render pagination controls and page numbers.
     */
    function renderPagination(pagination) {
        if (!pagination || pagination.total_pages <= 1) {
            $paginationNav.hide();
            return;
        }

        $paginationNav.show();
        var curPage = pagination.page;
        var totalPages = pagination.total_pages;

        $pageInfo.text('Page ' + curPage + ' of ' + totalPages);

        $btnPrevPage.prop('disabled', curPage <= 1);
        $btnNextPage.prop('disabled', curPage >= totalPages);

        $pageNumbers.empty();

        // Calculate visible page range around current page
        var startPage = Math.max(1, curPage - 2);
        var endPage = Math.min(totalPages, curPage + 2);

        if (startPage > 1) {
            $pageNumbers.append(createPageButton(1, curPage));
            if (startPage > 2) {
                $pageNumbers.append($('<span>', { 'class': 'page-ellipsis' }).text('…'));
            }
        }

        for (var p = startPage; p <= endPage; p++) {
            $pageNumbers.append(createPageButton(p, curPage));
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                $pageNumbers.append($('<span>', { 'class': 'page-ellipsis' }).text('…'));
            }
            $pageNumbers.append(createPageButton(totalPages, curPage));
        }
    }

    /**
     * Helper to create a page number button safely.
     */
    function createPageButton(pageNum, activePage) {
        var $btn = $('<button>', {
            type: 'button',
            'class': 'btn-page-num' + (pageNum === activePage ? ' active' : ''),
            'data-page': String(pageNum),
            'aria-label': 'Go to page ' + pageNum
        }).text(String(pageNum));

        if (pageNum === activePage) {
            $btn.attr('aria-current', 'page');
        }

        return $btn;
    }

    /**
     * Primary AJAX fetch function for jobs log.
     */
    function fetchJobs(preserveUrl) {
        activeRequestId++;
        var reqId = activeRequestId;

        $loadingState.removeClass('log-state-hidden u-hidden').show();
        $errorState.hide();

        var params = new URLSearchParams();
        params.set('status', state.status);
        if (state.channel_id !== '') {
            params.set('channel_id', state.channel_id);
        }
        if (state.room_id !== '') {
            params.set('room_id', state.room_id);
        }
        params.set('per_page', String(state.per_page));
        params.set('page', String(state.page));

        $.ajax({
            url: '/api/jobs.php?' + params.toString(),
            method: 'GET',
            dataType: 'json',
            cache: false,
            timeout: 10000,
            success: function (res) {
                // Ignore out-of-order responses from earlier requests
                if (reqId !== activeRequestId) {
                    return;
                }

                $loadingState.hide();

                if (res && res.ok && res.data) {
                    var data = res.data;

                    // Update UI controls
                    $filterStatus.val(data.filters.status);
                    $filterChannel.val(data.filters.channel_id !== null ? String(data.filters.channel_id) : '');
                    $filterRoom.val(data.filters.room_id !== null ? String(data.filters.room_id) : '');
                    $filterPerPage.val(String(data.pagination.per_page));

                    updateDropdownOptions(data.options);
                    updateStatusOptionCounts(data.counts);

                    // Results summary string
                    var total = data.pagination.total;
                    var start = total > 0 ? (data.pagination.page - 1) * data.pagination.per_page + 1 : 0;
                    var end = Math.min(start + data.pagination.per_page - 1, total);
                    $resultsSummary.text('Showing ' + start + ' to ' + end + ' of ' + total + ' jobs');

                    $loadedAtText.text('Loaded at ' + (data.generated_at_jst.split(' ')[1] || '') + ' JST');

                    renderJobsTable(data);

                    if (!preserveUrl) {
                        syncUrlParams(false);
                    }
                } else {
                    $tableSection.hide();
                    $paginationNav.hide();
                    $emptyFilteredState.hide();
                    $emptySystemState.hide();
                    $errorState.removeClass('log-state-hidden u-hidden').show();
                    $resultsSummary.text('Could not load jobs');
                }
            },
            error: function (xhr) {
                // Ignore out-of-order responses
                if (reqId !== activeRequestId) {
                    return;
                }

                $loadingState.hide();
                $tableSection.hide();
                $paginationNav.hide();
                $emptyFilteredState.hide();
                $emptySystemState.hide();

                // If URL contained invalid filters (HTTP 422), alert user and reset to defaults
                if (xhr.status === 422) {
                    $urlParamNotice.show();
                    announce('Some filters in this link were not valid and were ignored.');
                    state = {
                        status: 'all',
                        channel_id: '',
                        room_id: '',
                        per_page: 25,
                        page: 1
                    };
                    syncUrlParams(true);
                    fetchJobs(true);
                    return;
                }

                $errorState.removeClass('log-state-hidden u-hidden').show();
                $resultsSummary.text('Could not load jobs');
            }
        });
    }

    /**
     * Handle manual Retry button clicks.
     */
    function handleRetryClick($btn) {
        var rawId = $btn.attr('data-job-id');
        var safeJobId = parseInt(rawId, 10);
        if (isNaN(safeJobId) || safeJobId <= 0) {
            return;
        }

        $btn.prop('disabled', true).text('Retrying...');

        $.ajax({
            url: '/api/retry.php',
            method: 'POST',
            data: { job_id: safeJobId },
            dataType: 'json',
            success: function (res) {
                if (res && res.ok) {
                    var successMsg = 'Job #' + safeJobId + ' queued for retry.';
                    announce(successMsg);

                    // Invalidate cached attempts for this job
                    delete attemptsCache[safeJobId];

                    // Reload current page to display updated state
                    fetchJobs(true);
                } else {
                    var errMsg = (res && res.error) ? res.error : 'Could not retry. Please try again.';
                    announce(errMsg);
                    fetchJobs(true);
                }
            },
            error: function (xhr) {
                if (xhr.status === 409 || xhr.status === 404) {
                    var errorResponse = xhr.responseJSON;
                    var msg = (errorResponse && errorResponse.error)
                        ? errorResponse.error
                        : 'Job cannot be retried.';
                    announce(msg);
                    fetchJobs(true);
                } else {
                    $btn.prop('disabled', false).text('Retry');
                    announce('Could not retry. Please try again.');
                }
            }
        });
    }

    /**
     * Toggle expandable Details row.
     */
    function toggleDetails($btn) {
        var controlsId = $btn.attr('aria-controls');
        var $detailsRow = $('#' + controlsId);
        var $mainRow = $btn.closest('.job-row');
        var jobIdStr = controlsId.replace('details-row-', '');
        var safeJobId = parseInt(jobIdStr, 10);

        if ($detailsRow.is(':visible')) {
            $detailsRow.hide();
            $mainRow.removeClass('details-open');
            $btn.attr('aria-expanded', 'false').text('Details');
            delete openDetailsJobIds[safeJobId];
        } else {
            $detailsRow.removeClass('log-state-hidden u-hidden').show();
            $mainRow.addClass('details-open');
            $btn.attr('aria-expanded', 'true').text('Hide Details');
            openDetailsJobIds[safeJobId] = true;
            loadAttemptHistory(safeJobId, $detailsRow, false);
        }
    }

    // ========================================================================
    // Event Listeners
    // ========================================================================

    // Automatic filter application on change
    $filterStatus.on('change', function () {
        state.status = $(this).val();
        state.page = 1;
        fetchJobs(false);
    });

    $filterChannel.on('change', function () {
        state.channel_id = $(this).val();
        state.page = 1;
        fetchJobs(false);
    });

    $filterRoom.on('change', function () {
        state.room_id = $(this).val();
        state.page = 1;
        fetchJobs(false);
    });

    $filterPerPage.on('change', function () {
        var val = parseInt($(this).val(), 10);
        state.per_page = (val === 10 || val === 25 || val === 50) ? val : 25;
        state.page = 1;
        fetchJobs(false);
    });

    // Reset filters
    function resetAllFilters() {
        state = {
            status: 'all',
            channel_id: '',
            room_id: '',
            per_page: 25,
            page: 1
        };
        $filterStatus.val('all');
        $filterChannel.val('');
        $filterRoom.val('');
        $filterPerPage.val('25');
        $('.status-tab').removeClass('active').attr('aria-pressed', 'false');
        $('.status-tab[data-status="all"]').addClass('active').attr('aria-pressed', 'true');
        fetchJobs(false);
    }

    function initStatusTabs() {
        $('.status-tab').on('click', function () {
            var targetStatus = $(this).attr('data-status');
            if (!targetStatus) return;
            state.status = targetStatus;
            state.page = 1;

            $('.status-tab').removeClass('active').attr('aria-pressed', 'false');
            $(this).addClass('active').attr('aria-pressed', 'true');
            $filterStatus.val(targetStatus);

            fetchJobs(false);
        });
    }

    $btnResetFilters.on('click', resetAllFilters);
    $btnEmptyReset.on('click', resetAllFilters);

    // Refresh button
    $btnRefresh.on('click', function () {
        fetchJobs(true);
    });

    $btnRetryFetch.on('click', function () {
        fetchJobs(true);
    });

    $btnDismissNotice.on('click', function () {
        $urlParamNotice.hide();
    });

    // Pagination Click Handlers
    $btnPrevPage.on('click', function () {
        if (state.page > 1) {
            state.page--;
            fetchJobs(false);
        }
    });

    $btnNextPage.on('click', function () {
        state.page++;
        fetchJobs(false);
    });

    $pageNumbers.on('click', '.btn-page-num', function () {
        var targetPage = parseInt($(this).attr('data-page'), 10);
        if (!isNaN(targetPage) && targetPage !== state.page) {
            state.page = targetPage;
            fetchJobs(false);
        }
    });

    // Table Action Delegations
    $jobsTbody.on('click', '.btn-details', function () {
        toggleDetails($(this));
    });

    $jobsTbody.on('click', '.btn-retry', function () {
        handleRetryClick($(this));
    });

    // Browser History popstate Handler (Back / Forward navigation)
    window.addEventListener('popstate', function () {
        parseUrlParams();
        fetchJobs(true);
    });

    // ========================================================================
    // Independent Module Initialization
    // ========================================================================
    try { initTheme(); } catch (err) { console.error('initTheme failed', err); }
    try { initWallMode(); } catch (err) { console.error('initWallMode failed', err); }
    try { initIllustrations(); } catch (err) { console.error('initIllustrations failed', err); }
    try { initStatusTabs(); } catch (err) { console.error('initStatusTabs failed', err); }
    try { parseUrlParams(); } catch (err) { console.error('parseUrlParams failed', err); }
    try { fetchJobs(true); } catch (err) { console.error('fetchJobs failed', err); }
});
