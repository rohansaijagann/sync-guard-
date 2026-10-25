/**
 * Sync Guard - Operations Console Dashboard Controller
 *
 * Implements:
 * 1. Independent setTimeout polling chain for /api/status.php (5s, backoff on error, pause on hidden tab)
 * 2. Hero Status Banner with single-sentence priority hierarchy
 * 3. Dynamic Pipeline Diagram (inline SVG built with createElementNS and updated in-place)
 * 4. Site Cards (donut rings, latency bars, failure dots, compact chips, sparklines)
 * 5. At-Risk Room Panel (severity grouping, top-5 collapse, empty state illustration)
 * 6. Calm State-Change Toast Notifications (max 3, auto-dismiss 6s)
 * 7. Dark/Light Theme Switching (persisted in localStorage with try/catch)
 * 8. Wall Mode (fullscreen operational wallboard)
 * 9. Native Dialog handling for Retry Dead Jobs
 * 10. Gated Demo Controls AJAX Handlers
 *
 * STRICT SECURITY INVARIANT:
 * Zero raw HTML injection. Everything is written via textContent/.text() or setAttribute.
 * All DOM constructed safely with createElement or createElementNS.
 */

window.SyncGuard = window.SyncGuard || {};

$(function () {
    'use strict';

    var pollIntervalMs = 5000;
    var baseIntervalMs = 5000;
    var maxIntervalMs = 30000;
    var timerId = null;
    var lastSuccessfulFetchTime = 0;
    var isPollingActive = true;
    var statusRequestId = 0;
    var lastLiveMessage = '';

    // State tracking for calm toasts & sparklines
    var isFirstLoad = true;
    var previousSiteStatuses = {};
    var previousDeadCount = null;
    var previousWorkerOnline = null;
    var sparklineHistory = {}; // channel_id -> [number, ...] (max 20)
    var isAtRiskExpanded = false;

    // DOM Elements: Core Header & Status
    var $workerPill = $('#worker-pill');
    var $workerText = $('#worker-text');
    var $lastUpdatedText = $('#last-updated-text');
    var $themeToggle = $('#theme-toggle');
    var $themeToggleIcon = $('#theme-toggle-icon');
    var $wallModeToggle = $('#wall-mode-toggle');
    var $liveAnnouncer = $('#status-live-announcer');
    var $toastContainer = $('#toast-container');
    var $staleBanner = $('#stale-banner');
    var $staleMessage = $('#stale-message');
    var $attentionBanner = $('#attention-banner');
    var $attentionCount = $('#attention-count');

    // Hero Banner Elements
    var $heroBanner = $('#hero-banner');
    var $heroIcon = $('#hero-icon');
    var $heroTitle = $('#hero-title');
    var $heroSecondary = $('#hero-secondary');
    var $heroDeadBtn = $('#hero-dead-btn');
    var $heroDeadCount = $('#hero-dead-count');

    // Pipeline & Sites Elements
    var $pipelineContainer = $('#pipeline-container');
    var $pipelineSrList = $('#pipeline-sr-list');
    var $channelCardsContainer = $('#channel-cards-container');
    var $overallCard = $('#overall-card');

    // At-Risk Elements
    var $atRiskEmpty = $('#at-risk-empty');
    var $atRiskList = $('#at-risk-list');
    var $atRiskHeaderCount = $('#at-risk-header-count');
    var $btnShowAllAtRisk = $('#btn-show-all-at-risk');

    // Retry Dialog Elements
    var $btnRetryAllDead = $('#btn-retry-all-dead');
    var $retryDeadDialog = $('#retry-dead-dialog');
    var $dialogDeadCount = $('#dialog-dead-count');
    var $dialogCancelBtn = $('#dialog-cancel-btn');

    // Demo Controls Elements
    var $demoPanel = $('#demo-controls-panel');
    var $demoModeSiteB = $('#demo-mode-site-b');
    var $demoModeSiteC = $('#demo-mode-site-c');
    var $btnApplySiteB = $('#btn-apply-site-b');
    var $btnApplySiteC = $('#btn-apply-site-c');
    var $btnDemoReset = $('#btn-demo-reset');
    var $btnDemoSubmitUpdate = $('#btn-demo-submit-update');
    var $btnDemoRunWorker = $('#btn-demo-run-worker');
    var $btnDemoSkipWait = $('#btn-demo-skip-wait');
    var $demoStatusModes = $('#demo-status-modes');
    var $demoWorkerHint = $('#demo-worker-hint');

    var SVG_NS = 'http://www.w3.org/2000/svg';

    // ========================================================================
    // 1. THEME & WALL MODE MANAGERS
    // ========================================================================
    function initTheme() {
        if (window.__syncguard_theme_initialized) return;
        window.__syncguard_theme_initialized = true;

        var storedTheme = null;
        try {
            storedTheme = localStorage.getItem('syncguard_theme');
        } catch (e) {
            // Storage access blocked or restricted
        }
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
    }

    function applyTheme(theme) {
        $('html').attr('data-theme', theme);
        var isLight = theme === 'light';
        $themeToggle.attr('aria-pressed', isLight ? 'true' : 'false');
        $themeToggleIcon.text(isLight ? '☀️' : '🌙');
    }

    function initWallMode() {
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
    }

    function applyWallMode(active) {
        $('body').toggleClass('wall-mode', active);
        $wallModeToggle.attr('aria-pressed', active ? 'true' : 'false');
    }

    // ========================================================================
    // 2. ACCESSIBILITY & TOASTS
    // ========================================================================
    function announceLive(message) {
        if (!message || message === lastLiveMessage) {
            return;
        }
        lastLiveMessage = message;
        $liveAnnouncer.text(message);
    }

    function showToast(message, type) {
        if (isFirstLoad || !message) {
            return;
        }
        var toastEl = document.createElement('div');
        toastEl.className = 'toast toast-' + (type || 'healthy');
        toastEl.setAttribute('role', 'status');

        var textSpan = document.createElement('span');
        textSpan.textContent = message;
        toastEl.appendChild(textSpan);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'toast-close-btn';
        closeBtn.setAttribute('aria-label', 'Dismiss notification');
        closeBtn.textContent = '×';
        closeBtn.onclick = function () {
            if (toastEl.parentNode) {
                toastEl.parentNode.removeChild(toastEl);
            }
        };
        toastEl.appendChild(closeBtn);

        // Keep at most 3 visible toasts
        var currentToasts = $toastContainer.children();
        if (currentToasts.length >= 3) {
            currentToasts.first().remove();
        }

        $toastContainer.append(toastEl);

        setTimeout(function () {
            if (toastEl.parentNode) {
                toastEl.parentNode.removeChild(toastEl);
            }
        }, 6000);
    }

    function formatSecondsAgo(seconds) {
        if (seconds === null || seconds === undefined) {
            return 'Never';
        }
        if (seconds < 60) {
            return seconds + 's ago';
        }
        var mins = Math.floor(seconds / 60);
        if (mins < 60) {
            return mins + 'm ago';
        }
        var hours = Math.floor(mins / 60);
        return hours + 'h ' + (mins % 60) + 'm ago';
    }

    function formatNextAttempt(seconds) {
        if (seconds === null || seconds === undefined) {
            return null;
        }
        if (seconds <= 0) {
            return 'retrying now';
        }
        if (seconds < 60) {
            return 'retrying in ' + seconds + 's';
        }
        var mins = Math.ceil(seconds / 60);
        return 'retrying in about ' + mins + ' min';
    }

    function getStatusBadgeProps(status) {
        switch (status) {
            case 'healthy':
                return { badgeClass: 'badge-healthy', symbol: '●', label: 'Healthy' };
            case 'recovering':
                return { badgeClass: 'badge-recovering', symbol: '↻', label: 'Recovering' };
            case 'degraded':
                return { badgeClass: 'badge-degraded', symbol: '▲', label: 'Degraded' };
            case 'down':
                return { badgeClass: 'badge-down', symbol: '✖', label: 'Down' };
            case 'paused':
                return { badgeClass: 'badge-paused', symbol: '⏸', label: 'Paused' };
            default:
                return { badgeClass: 'badge-neutral', symbol: '—', label: 'No Data' };
        }
    }

    // ========================================================================
    // 3. HERO BANNER CONTROLLER
    // ========================================================================
    function renderHeroBanner(data) {
        if (typeof window.computeHeroState !== 'function') {
            return;
        }

        var state = window.computeHeroState(data);
        $heroBanner.removeClass('hero-healthy hero-recovering hero-degraded hero-down hero-nodata');
        $heroBanner.addClass('hero-' + state.status);

        $heroIcon.text(state.icon);
        $heroTitle.text(state.headline);
        $heroSecondary.text(state.secondary);

        if (state.showDeadButton) {
            $heroDeadCount.text(state.deadCount);
            $heroDeadBtn.removeClass('u-hidden');
        } else {
            $heroDeadBtn.addClass('u-hidden');
        }

        // Live announcer receives only changes to the primary headline
        announceLive(state.headline);

        // Update browser tab title
        if (state.deadCount > 0) {
            document.title = '(' + state.deadCount + ') Sync Guard 🛡️ - Operational Dashboard';
        } else {
            document.title = 'Sync Guard 🛡️ - Operational Dashboard';
        }
    }

    // ========================================================================
    // 4. PIPELINE DIAGRAM (Inline SVG, Updated In-Place)
    // ========================================================================
    var pipelineSvgInitialized = false;

    function initPipelineSvg() {
        if (pipelineSvgInitialized || !$pipelineContainer.length) {
            return;
        }

        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'pipeline-svg');
        svg.setAttribute('viewBox', '0 0 920 160');
        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
        svg.setAttribute('id', 'pipeline-svg-root');

        // Node 1: Staff Update
        var gStaff = createPipelineNode('pipe-staff-box', 20, 50, 130, 60, 'Staff Update', 'Hotel System', 'pipe-staff-val');
        svg.appendChild(gStaff);

        // Connector 1 -> 2
        var l1 = createSvgLine(150, 80, 200, 80, 'pipe-line-1');
        svg.appendChild(l1);

        // Node 2: Queue
        var gQueue = createPipelineNode('pipe-queue-box', 200, 50, 130, 60, 'Queue', '0 pending', 'pipe-queue-val');
        svg.appendChild(gQueue);

        // Connector 2 -> 3
        var l2 = createSvgLine(330, 80, 380, 80, 'pipe-line-2');
        svg.appendChild(l2);

        // Node 3: Worker
        var gWorker = createPipelineNode('pipe-worker-box', 380, 50, 130, 60, 'Worker Daemon', 'Checking...', 'pipe-worker-val');
        svg.appendChild(gWorker);

        // Branching Connectors to 3 Sites
        var lSiteA = createSvgPath('M 510 80 C 580 80, 590 35, 660 35', 'pipe-line-site-1');
        var lSiteB = createSvgPath('M 510 80 L 660 80', 'pipe-line-site-2');
        var lSiteC = createSvgPath('M 510 80 C 580 80, 590 125, 660 125', 'pipe-line-site-3');
        svg.appendChild(lSiteA);
        svg.appendChild(lSiteB);
        svg.appendChild(lSiteC);

        // Site Nodes (3)
        var gSiteA = createSiteNode(1, 660, 15, 230, 40, 'Site A', 'pipe-site-1');
        var gSiteB = createSiteNode(2, 660, 60, 230, 40, 'Site B', 'pipe-site-2');
        var gSiteC = createSiteNode(3, 660, 105, 230, 40, 'Site C', 'pipe-site-3');
        svg.appendChild(gSiteA);
        svg.appendChild(gSiteB);
        svg.appendChild(gSiteC);

        $pipelineContainer[0].appendChild(svg);
        pipelineSvgInitialized = true;
    }

    function createSvgLine(x1, y1, x2, y2, id) {
        var line = document.createElementNS(SVG_NS, 'line');
        line.setAttribute('x1', x1);
        line.setAttribute('y1', y1);
        line.setAttribute('x2', x2);
        line.setAttribute('y2', y2);
        line.setAttribute('id', id);
        line.setAttribute('class', 'pipe-line');
        return line;
    }

    function createSvgPath(d, id) {
        var path = document.createElementNS(SVG_NS, 'path');
        path.setAttribute('d', d);
        path.setAttribute('id', id);
        path.setAttribute('class', 'pipe-line');
        return path;
    }

    function createPipelineNode(boxId, x, y, w, h, titleText, valText, valId) {
        var g = document.createElementNS(SVG_NS, 'g');
        var rect = document.createElementNS(SVG_NS, 'rect');
        rect.setAttribute('id', boxId);
        rect.setAttribute('class', 'pipe-node-box');
        rect.setAttribute('x', x);
        rect.setAttribute('y', y);
        rect.setAttribute('width', w);
        rect.setAttribute('height', h);

        var title = document.createElementNS(SVG_NS, 'text');
        title.setAttribute('class', 'pipe-node-title');
        title.setAttribute('x', x + w / 2);
        title.setAttribute('y', y + 22);
        title.textContent = titleText;

        var val = document.createElementNS(SVG_NS, 'text');
        val.setAttribute('id', valId);
        val.setAttribute('class', 'pipe-node-val');
        val.setAttribute('x', x + w / 2);
        val.setAttribute('y', y + 44);
        val.textContent = valText;

        g.appendChild(rect);
        g.appendChild(title);
        g.appendChild(val);
        return g;
    }

    function createSiteNode(channelId, x, y, w, h, name, prefix) {
        var g = document.createElementNS(SVG_NS, 'g');
        var rect = document.createElementNS(SVG_NS, 'rect');
        rect.setAttribute('id', prefix + '-box');
        rect.setAttribute('class', 'pipe-node-box pipe-site-healthy');
        rect.setAttribute('x', x);
        rect.setAttribute('y', y);
        rect.setAttribute('width', w);
        rect.setAttribute('height', h);

        var nameText = document.createElementNS(SVG_NS, 'text');
        nameText.setAttribute('id', prefix + '-name');
        nameText.setAttribute('class', 'pipe-node-val');
        nameText.setAttribute('x', x + 16);
        nameText.setAttribute('y', y + 25);
        nameText.setAttribute('text-anchor', 'start');
        nameText.textContent = name;

        var statusText = document.createElementNS(SVG_NS, 'text');
        statusText.setAttribute('id', prefix + '-stat');
        statusText.setAttribute('class', 'pipe-node-title');
        statusText.setAttribute('x', x + w - 16);
        statusText.setAttribute('y', y + 25);
        statusText.setAttribute('text-anchor', 'end');
        statusText.textContent = '● 100%';

        g.appendChild(rect);
        g.appendChild(nameText);
        g.appendChild(statusText);
        return g;
    }

    function updatePipelineSvg(data) {
        initPipelineSvg();

        var summary = data.summary || {};
        var worker = data.worker || {};
        var channels = Array.isArray(data.channels) ? data.channels : [];
        var pendingCount = parseInt(summary.pending_jobs || 0, 10);
        var workerOnline = worker.online === true || worker.status === 'running';

        // Update Queue text
        var qEl = document.getElementById('pipe-queue-val');
        if (qEl) {
            qEl.textContent = pendingCount + ' pending';
        }

        // Update Worker text
        var wEl = document.getElementById('pipe-worker-val');
        var wBox = document.getElementById('pipe-worker-box');
        if (wEl && wBox) {
            wEl.textContent = workerOnline ? 'Online' : 'Stopped';
            wBox.setAttribute('class', 'pipe-node-box ' + (workerOnline ? 'pipe-site-healthy' : 'pipe-site-down'));
        }

        // Update Channels
        for (var i = 0; i < channels.length; i++) {
            var ch = channels[i];
            var prefix = 'pipe-site-' + ch.id;
            var box = document.getElementById(prefix + '-box');
            var name = document.getElementById(prefix + '-name');
            var stat = document.getElementById(prefix + '-stat');
            var line = document.getElementById('pipe-line-site-' + ch.id);

            var st = String(ch.status || 'no_data').toLowerCase();
            var props = getStatusBadgeProps(st);
            var rateStr = typeof ch.success_rate === 'number' ? ch.success_rate.toFixed(0) + '%' : '--%';

            if (name) name.textContent = ch.name || ('Site ' + ch.id);
            if (stat) stat.textContent = props.symbol + ' ' + rateStr;
            if (box) box.setAttribute('class', 'pipe-node-box pipe-site-' + st);
            if (line) {
                var isFailing = (st === 'degraded' || st === 'down');
                line.setAttribute('class', 'pipe-line' + (isFailing ? ' pipe-line-failing' : ''));
            }
        }
    }

    // ========================================================================
    // 5. SITE CARDS (Donut SVG, Latency Bar, Failure Dots, Sparklines)
    // ========================================================================
    function renderChannelCards(channels) {
        if (!Array.isArray(channels)) return;

        for (var i = 0; i < channels.length; i++) {
            var ch = channels[i];
            var cardId = 'channel-card-' + ch.id;
            var $card = $('#' + cardId);

            // Record sparkline history in memory
            if (!sparklineHistory[ch.id]) {
                sparklineHistory[ch.id] = [];
            }
            if (typeof ch.success_rate === 'number') {
                sparklineHistory[ch.id].push(ch.success_rate);
                if (sparklineHistory[ch.id].length > 20) {
                    sparklineHistory[ch.id].shift();
                }
            }

            if (!$card.length) {
                $card = buildChannelCardSkeleton(ch.id);
                $channelCardsContainer.append($card);
            }

            updateChannelCard($card, ch);
        }
    }

    function buildChannelCardSkeleton(id) {
        var $c = $('<div>')
            .attr('id', 'channel-card-' + id)
            .addClass('channel-card card-healthy');

        // Head
        var $head = $('<div>').addClass('channel-head');
        var $title = $('<h3>').addClass('channel-name').text('Site ' + id);
        var $badge = $('<span>').addClass('badge badge-healthy').append(
            $('<span>').addClass('badge-symbol').text('●'),
            $('<span>').addClass('badge-text').text('Healthy')
        );
        $head.append($title, $badge);

        // Visual Row (Donut + Latency)
        var $visRow = $('<div>').addClass('channel-visual-row');
        var $donutCont = $('<div>').addClass('donut-container');
        var donutSvg = createDonutSvg(id);
        $donutCont.append(donutSvg);

        var $latCont = $('<div>').addClass('latency-container');
        var $latMeta = $('<div>').addClass('latency-meta').append(
            $('<span>').addClass('latency-val').text('-- ms'),
            $('<span>').text('5s timeout')
        );
        var $latTrack = $('<div>').addClass('latency-bar-track').append(
            $('<div>').addClass('latency-bar-fill')
        );
        $latCont.append($latMeta, $latTrack);
        $visRow.append($donutCont, $latCont);

        // Failure Dots Row
        var $failRow = $('<div>').addClass('failure-dots-row').append(
            $('<span>').text('Consecutive failures:'),
            $('<div>').addClass('failure-dots')
        );
        for (var d = 0; d < 5; d++) {
            $failRow.find('.failure-dots').append($('<span>').addClass('failure-dot'));
        }

        // Compact Chips Row
        var $chips = $('<div>').addClass('channel-chips').append(
            $('<span>').addClass('chip chip-pending').append('Pending: ', $('<strong>').addClass('ch-pending').text('0')),
            $('<span>').addClass('chip chip-failed').append('Failed: ', $('<strong>').addClass('ch-failed').text('0')),
            $('<span>').addClass('chip chip-dead').append('Dead: ', $('<strong>').addClass('ch-dead').text('0'))
        );

        // Sparkline Row
        var $sparkRow = $('<div>').addClass('sparkline-container').append(
            $('<span>').addClass('sparkline-label').text('since you opened this page'),
            $('<div>').addClass('sparkline-svg-wrapper')
        );

        // Footer
        var $foot = $('<div>').addClass('channel-card-footer').append(
            $('<span>').addClass('last-delivery-text').text('Last delivery: Never'),
            $('<a>').addClass('card-action-link').attr('href', '/log.php?channel_id=' + id).text('View jobs →')
        );

        $c.append($head, $visRow, $failRow, $chips, $sparkRow, $foot);
        return $c;
    }

    function createDonutSvg(id) {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'donut-svg');
        svg.setAttribute('viewBox', '0 0 64 64');

        var circleTrack = document.createElementNS(SVG_NS, 'circle');
        circleTrack.setAttribute('class', 'donut-track');
        circleTrack.setAttribute('cx', '32');
        circleTrack.setAttribute('cy', '32');
        circleTrack.setAttribute('r', '26');

        var circleFill = document.createElementNS(SVG_NS, 'circle');
        circleFill.setAttribute('id', 'donut-fill-' + id);
        circleFill.setAttribute('class', 'donut-fill');
        circleFill.setAttribute('cx', '32');
        circleFill.setAttribute('cy', '32');
        circleFill.setAttribute('r', '26');
        circleFill.setAttribute('stroke-dasharray', '163.36');
        circleFill.setAttribute('stroke-dashoffset', '0');

        var text = document.createElementNS(SVG_NS, 'text');
        text.setAttribute('id', 'donut-text-' + id);
        text.setAttribute('class', 'donut-text');
        text.setAttribute('x', '32');
        text.setAttribute('y', '-27');
        text.textContent = '100%';

        svg.appendChild(circleTrack);
        svg.appendChild(circleFill);
        svg.appendChild(text);
        return svg;
    }

    function updateChannelCard($card, ch) {
        var status = String(ch.status || 'no_data').toLowerCase();
        var props = getStatusBadgeProps(status);

        // Card Border Class
        $card.removeClass('card-healthy card-recovering card-degraded card-down card-paused')
             .addClass('card-' + status);

        // Name & Badge
        $card.find('.channel-name').text(ch.name || ('Site ' + ch.id));
        var $badge = $card.find('.badge');
        $badge.attr('class', 'badge ' + props.badgeClass);
        $badge.find('.badge-symbol').text(props.symbol);
        $badge.find('.badge-text').text(props.label);

        // Donut
        var rate = typeof ch.success_rate === 'number' ? ch.success_rate : 100;
        var dashOffset = 163.36 * (1 - Math.max(0, Math.min(100, rate)) / 100);
        var fillEl = document.getElementById('donut-fill-' + ch.id);
        var textEl = document.getElementById('donut-text-' + ch.id);
        if (fillEl) fillEl.setAttribute('stroke-dashoffset', dashOffset.toFixed(2));
        if (textEl) textEl.textContent = rate.toFixed(0) + '%';

        // Latency
        var avgMs = typeof ch.avg_response_time_ms === 'number' ? Math.round(ch.avg_response_time_ms) : 0;
        $card.find('.latency-val').text(avgMs + ' ms');
        var latPct = Math.min(100, (avgMs / 5000) * 100);
        $card.find('.latency-bar-fill').css('width', latPct.toFixed(1) + '%');

        // Failure Dots (up to 5)
        var failures = parseInt(ch.consecutive_failures || 0, 10);
        var $dots = $card.find('.failure-dot');
        $dots.each(function (idx) {
            $(this).toggleClass('dot-failed', idx < failures);
        });

        // Queue counts
        var q = ch.queue_jobs || {};
        $card.find('.ch-pending').text(q.pending || 0);
        $card.find('.ch-failed').text(q.failed || 0);
        $card.find('.ch-dead').text(q.dead || 0);

        // Sparkline Polyline
        renderSparklineSvg($card.find('.sparkline-svg-wrapper')[0], ch.id);

        // Last success / delivery
        var deliveryText = 'Last success: ' + formatSecondsAgo(ch.seconds_since_last_success);
        if (status === 'recovering' && typeof ch.recent_streak === 'number') {
            deliveryText = 'Recovering: ' + ch.recent_streak + ' of 5 OK';
        }
        $card.find('.last-delivery-text').text(deliveryText);
    }

    function renderSparklineSvg(wrapperEl, channelId) {
        if (!wrapperEl) return;
        var history = sparklineHistory[channelId] || [];
        if (history.length < 2) {
            wrapperEl.textContent = '';
            return;
        }

        var w = 120;
        var h = 24;
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'sparkline-svg');
        svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);

        var points = [];
        for (var i = 0; i < history.length; i++) {
            var x = (i / (history.length - 1)) * (w - 4) + 2;
            var rate = Math.max(0, Math.min(100, history[i]));
            var y = (h - 4) - (rate / 100) * (h - 8) + 2;
            points.push(x.toFixed(1) + ',' + y.toFixed(1));
        }

        var polyline = document.createElementNS(SVG_NS, 'polyline');
        polyline.setAttribute('class', 'sparkline-line');
        polyline.setAttribute('points', points.join(' '));
        svg.appendChild(polyline);

        wrapperEl.textContent = '';
        wrapperEl.appendChild(svg);
    }

    // ========================================================================
    // 6. AT-RISK ROOMS PANEL (Severity Grouping & Top-5 Collapse)
    // ========================================================================
    function renderAtRiskRooms(rooms) {
        if (!Array.isArray(rooms) || rooms.length === 0) {
            $atRiskEmpty.removeClass('u-hidden');
            $atRiskList.empty();
            $atRiskHeaderCount.text('0').attr('class', 'badge badge-neutral');
            $btnShowAllAtRisk.addClass('u-hidden');
            return;
        }

        $atRiskEmpty.addClass('u-hidden');
        $atRiskHeaderCount.text(rooms.length).attr('class', 'badge badge-down');
        $atRiskList.empty();

        // Sort by severity: dead first, then failed, then in_progress
        var sorted = rooms.slice().sort(function (a, b) {
            var score = function (r) {
                var s = String(r.status).toLowerCase();
                if (s === 'dead') return 3;
                if (s === 'failed') return 2;
                return 1;
            };
            return score(b) - score(a);
        });

        for (var i = 0; i < sorted.length; i++) {
            var r = sorted[i];
            var $item = $('<div>').addClass('at-risk-item');
            if (i >= 5 && !isAtRiskExpanded) {
                $item.addClass('u-hidden at-risk-overflow');
            }

            var $left = $('<div>').addClass('at-risk-item-left');
            var $title = $('<span>').addClass('at-risk-room').text(r.room_name || ('Room #' + r.room_id));
            var dateSpan = (r.date_from || '') + ' to ' + (r.date_to || '');
            var $span = $('<span>').addClass('at-risk-span').text(dateSpan + ' · ' + (r.channel_name || 'Channel'));
            $left.append($title, $span);

            var $right = $('<div>').addClass('at-risk-item-right');
            var msg = r.status === 'dead' ? 'Needs staff attention' : (formatNextAttempt(r.seconds_until_retry) || 'Retrying soon');
            var $msg = $('<span>').addClass('at-risk-msg').text(msg);
            var $link = $('<a>').addClass('card-action-link').attr('href', '/log.php?room_id=' + r.room_id + '&status=unsynced').text('View jobs →');
            $right.append($msg, $link);

            $item.append($left, $right);
            $atRiskList.append($item);
        }

        if (sorted.length > 5) {
            $btnShowAllAtRisk.removeClass('u-hidden');
            $btnShowAllAtRisk.text(isAtRiskExpanded ? 'Show top 5 only' : 'Show all ' + sorted.length + ' rooms');
            $btnShowAllAtRisk.attr('aria-expanded', isAtRiskExpanded ? 'true' : 'false');
        } else {
            $btnShowAllAtRisk.addClass('u-hidden');
        }
    }

    $btnShowAllAtRisk.on('click', function () {
        isAtRiskExpanded = !isAtRiskExpanded;
        if (isAtRiskExpanded) {
            $('.at-risk-overflow').removeClass('u-hidden');
            $btnShowAllAtRisk.text('Show top 5 only').attr('aria-expanded', 'true');
        } else {
            $('.at-risk-overflow').addClass('u-hidden');
            $btnShowAllAtRisk.text('Show all rooms').attr('aria-expanded', 'false');
        }
    });

    // ========================================================================
    // 7. OVERALL & WORKER TELEMETRY
    // ========================================================================
    function renderOverallCard(summary, status) {
        if (!summary) return;
        var props = getStatusBadgeProps(status);

        var $badge = $overallCard.find('.overall-status-badge');
        $badge.attr('class', 'badge overall-status-badge ' + props.badgeClass);
        $badge.find('.badge-symbol').text(props.symbol);
        $badge.find('.badge-text').text(props.label);

        var sRate = typeof summary.success_rate === 'number' ? summary.success_rate.toFixed(1) + '%' : '--%';
        $overallCard.find('.overall-success-rate').text(sRate);

        var avgMs = typeof summary.avg_response_time_ms === 'number' ? Math.round(summary.avg_response_time_ms) + ' ms' : '-- ms';
        $overallCard.find('.overall-avg-ms').text(avgMs);

        $overallCard.find('.cnt-pending').text(summary.pending_jobs || 0);
        $overallCard.find('.cnt-in-progress').text(summary.in_progress_jobs || 0);
        $overallCard.find('.cnt-failed').text(summary.failed_jobs || 0);
        $overallCard.find('.cnt-dead').text(summary.dead_jobs || 0);
        $overallCard.find('.cnt-success').text(summary.success_jobs || 0);
    }

    function renderWorkerState(worker) {
        $workerPill.removeClass('pill-online pill-stopped pill-unknown');
        var isOnline = worker.online === true || worker.status === 'running';

        if (isOnline) {
            $workerPill.addClass('pill-online');
            $workerText.text('Worker Online');
            if ($demoWorkerHint.length) $demoWorkerHint.addClass('u-hidden');
        } else {
            $workerPill.addClass('pill-stopped');
            var elapsed = worker.seconds_since_last_pass !== null
                ? ' (' + formatSecondsAgo(worker.seconds_since_last_pass) + ')'
                : '';
            $workerText.text('Worker Stopped' + elapsed);
            if ($demoWorkerHint.length) $demoWorkerHint.removeClass('u-hidden');
        }
    }

    // ========================================================================
    // 8. STATE CHANGE DETECTOR & TOAST EMITTER
    // ========================================================================
    function detectStateChanges(data) {
        var channels = Array.isArray(data.channels) ? data.channels : [];
        var deadCount = parseInt(data.attention_needed || 0, 10);
        var workerOnline = data.worker && (data.worker.online === true || data.worker.status === 'running');

        if (!isFirstLoad) {
            // 1. Channel status transitions
            for (var i = 0; i < channels.length; i++) {
                var ch = channels[i];
                var oldSt = previousSiteStatuses[ch.id];
                var newSt = String(ch.status || 'no_data').toLowerCase();
                if (oldSt && oldSt !== newSt) {
                    var chName = ch.name || ('Site ' + ch.id);
                    var msg = chName + ' transitioned from ' + oldSt + ' to ' + newSt;
                    showToast(msg, newSt);
                }
                previousSiteStatuses[ch.id] = newSt;
            }

            // 2. Dead job change
            if (previousDeadCount !== null && deadCount > previousDeadCount) {
                var diff = deadCount - previousDeadCount;
                var deadMsg = diff === 1
                    ? '1 job has exhausted all delivery retries and is dead.'
                    : diff + ' jobs have exhausted retries and require staff attention.';
                showToast(deadMsg, 'down');
            }

            // 3. Worker state transitions
            if (previousWorkerOnline !== null && workerOnline !== previousWorkerOnline) {
                var wMsg = workerOnline
                    ? 'The background worker daemon resumed operations.'
                    : 'The background worker daemon stopped running!';
                showToast(wMsg, workerOnline ? 'healthy' : 'down');
            }
        } else {
            for (var j = 0; j < channels.length; j++) {
                previousSiteStatuses[channels[j].id] = String(channels[j].status || 'no_data').toLowerCase();
            }
            isFirstLoad = false;
        }

        previousDeadCount = deadCount;
        previousWorkerOnline = workerOnline;
    }

    // ========================================================================
    // 9. RETRY ALL DEAD MODAL DIALOG
    // ========================================================================
    function initRetryDialog() {
        var dialogEl = $retryDeadDialog[0];

        $btnRetryAllDead.add($heroDeadBtn).on('click', function () {
            if (dialogEl && typeof dialogEl.showModal === 'function') {
                $dialogDeadCount.text($attentionCount.text() || '0');
                dialogEl.showModal();
            }
        });

        $dialogCancelBtn.on('click', function () {
            if (dialogEl && dialogEl.open) {
                dialogEl.close();
            }
        });

        $retryDeadDialog.find('form').on('submit', function (e) {
            e.preventDefault();
            if (dialogEl) dialogEl.close();

            $.ajax({
                url: '/api/retry_dead.php',
                method: 'POST',
                dataType: 'json',
                success: function (res) {
                    if (res.ok) {
                        var retried = (res.data && res.data.retried) ? res.data.retried : 0;
                        announceLive('Retried ' + retried + ' dead jobs.');
                        showToast('Retried ' + retried + ' dead jobs.', 'healthy');
                        fetchStatus();
                        if (window.SyncGuard.refreshInsights) window.SyncGuard.refreshInsights();
                        if (window.SyncGuard.refreshCalendar) window.SyncGuard.refreshCalendar();
                    }
                },
                error: function () {
                    announceLive('Failed to retry dead jobs.');
                    showToast('Failed to retry dead jobs.', 'down');
                }
            });
        });
    }

    // ========================================================================
    // 10. GATED DEMO CONTROLS HANDLERS
    // ========================================================================
    function initDemoControls() {
        if (!$demoPanel.length) return;

        function sendDemoAction(actionPayload, $btn) {
            if ($btn) $btn.prop('disabled', true);
            $.ajax({
                url: '/api/demo.php',
                method: 'POST',
                contentType: 'application/json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                data: JSON.stringify(actionPayload),
                dataType: 'json',
                success: function (res) {
                    if (res.ok) {
                        var msg = (res.data && res.data.message) || 'Action succeeded.';
                        announceLive(msg);
                        showToast(msg, 'healthy');
                        fetchStatus();
                        if (window.SyncGuard.refreshInsights) window.SyncGuard.refreshInsights();
                        if (window.SyncGuard.refreshCalendar) window.SyncGuard.refreshCalendar();
                    }
                },
                error: function (xhr) {
                    var err = (xhr.responseJSON && xhr.responseJSON.error) || 'Demo action failed.';
                    announceLive(err);
                    showToast(err, 'down');
                },
                complete: function () {
                    if ($btn) $btn.prop('disabled', false);
                }
            });
        }

        $btnApplySiteB.on('click', function () {
            sendDemoAction({ action: 'set_mode', site: 'site_b', mode: $demoModeSiteB.val() }, $(this));
        });
        $btnApplySiteC.on('click', function () {
            sendDemoAction({ action: 'set_mode', site: 'site_c', mode: $demoModeSiteC.val() }, $(this));
        });
        $btnDemoReset.on('click', function () {
            sendDemoAction({ action: 'reset_modes' }, $(this));
        });
        $btnDemoSubmitUpdate.on('click', function () {
            sendDemoAction({ action: 'submit_demo_update' }, $(this));
        });
        $btnDemoRunWorker.on('click', function () {
            sendDemoAction({ action: 'run_worker' }, $(this));
        });
        $btnDemoSkipWait.on('click', function () {
            sendDemoAction({ action: 'skip_wait' }, $(this));
        });
    }

    // ========================================================================
    // 11. MAIN STATUS POLLING CYCLE
    // ========================================================================
    function fetchStatus() {
        if (timerId !== null) {
            clearTimeout(timerId);
            timerId = null;
        }

        var currentReqId = ++statusRequestId;

        $.ajax({
            url: '/api/status.php',
            method: 'GET',
            dataType: 'json',
            timeout: 8000,
            cache: false,
            success: function (response) {
                if (currentReqId !== statusRequestId) return; // Ignore out-of-order responses

                if (response && response.ok && response.data) {
                    var data = response.data;
                    lastSuccessfulFetchTime = Date.now();
                    pollIntervalMs = baseIntervalMs; // Reset backoff
                    $staleBanner.addClass('u-hidden');

                    // 1. Detect state changes & trigger toasts
                    detectStateChanges(data);

                    // 2. Render Hero Banner
                    renderHeroBanner(data);

                    // 3. Render Pipeline Diagram
                    updatePipelineSvg(data);

                    // 4. Render Channel Cards
                    renderChannelCards(data.channels);

                    // 5. Render Overall & Worker State
                    renderOverallCard(data.summary, data.overall_status);
                    renderWorkerState(data.worker || {});

                    // 6. Render At-Risk Rooms
                    renderAtRiskRooms(data.rooms_at_risk);

                    // 7. Attention needed banner
                    var deadCount = parseInt(data.attention_needed || 0, 10);
                    $attentionCount.text(deadCount);
                    if (deadCount > 0) {
                        $attentionBanner.removeClass('u-hidden');
                    } else {
                        $attentionBanner.addClass('u-hidden');
                    }

                    // 8. Timestamp
                    $lastUpdatedText.text(data.generated_at_jst || '--:--:-- JST');
                }
            },
            error: function () {
                if (currentReqId !== statusRequestId) return;

                // Backoff: 5s -> 10s -> 20s -> 30s
                pollIntervalMs = Math.min(pollIntervalMs * 2, maxIntervalMs);
                $staleBanner.removeClass('u-hidden');
                $staleMessage.text("Can't reach the server. Retrying in " + Math.round(pollIntervalMs / 1000) + 's...');
            },
            complete: function () {
                if (isPollingActive) {
                    timerId = setTimeout(fetchStatus, pollIntervalMs);
                }
            }
        });
    }

    // Visibility change pauses polling when tab is hidden
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            isPollingActive = false;
            if (timerId !== null) {
                clearTimeout(timerId);
                timerId = null;
            }
        } else {
            isPollingActive = true;
            fetchStatus();
        }
    });

    // ========================================================================
    // INITIALIZATION
    // ========================================================================
    initTheme();
    initWallMode();
    initPipelineSvg();
    initRetryDialog();
    initDemoControls();

    // Export immediate refresh trigger for other pollers
    window.SyncGuard.refreshStatus = fetchStatus;

    // Start status polling chain
    fetchStatus();
});
