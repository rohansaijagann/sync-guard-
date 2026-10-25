/**
 * Sync Guard - Shared Header Status Controller
 *
 * Implements:
 * 1. Global theme switching with localStorage persistence
 * 2. Operational Wall mode toggle with localStorage persistence
 * 3. 10-second polling for worker heartbeat and JST clock from /api/status.php
 * 4. Page visibility awareness (pauses polling when hidden)
 */

$(function () {
    'use strict';

    // 1. Theme Manager
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

    // 2. Wall Mode Manager
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

        $wallModeToggle.off('click.headerStatus').on('click.headerStatus', function () {
            var active = !$('body').hasClass('wall-mode');
            applyWallMode(active);
            try {
                localStorage.setItem('syncguard_wall_mode', String(active));
            } catch (e) {}
        });

        $(document).off('keydown.headerStatus').on('keydown.headerStatus', function (e) {
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

    // 3. Worker & Header Telemetry Poller
    var timerId = null;
    var isPollingActive = true;
    var pollIntervalMs = 10000;
    var requestId = 0;

    var $workerPill = $('#worker-pill');
    var $workerText = $('#worker-text');
    var $lastUpdatedText = $('#last-updated-text');

    function renderWorkerState(worker, generatedAtJst) {
        if (!$workerPill.length) return;

        $workerPill.removeClass('pill-online pill-stopped pill-unknown');

        if (!worker || typeof worker !== 'object') {
            $workerPill.addClass('pill-unknown');
            $workerText.text('Worker status unknown');
            $workerPill.attr('title', 'Worker status unknown');
        } else {
            var isOnline = worker.online === true || worker.status === 'running';
            if (isOnline) {
                $workerPill.addClass('pill-online');
                $workerText.text('Worker Online');
                $workerPill.attr('title', 'Worker running');
            } else {
                $workerPill.addClass('pill-stopped');
                $workerText.text('Worker not running');
                $workerPill.attr('title', 'Start it: docker compose --profile worker up -d worker');
            }
        }

        if (generatedAtJst && $lastUpdatedText.length) {
            var timeOnly = generatedAtJst.indexOf(' ') !== -1
                ? generatedAtJst.split(' ')[1]
                : generatedAtJst;
            $lastUpdatedText.text(timeOnly + ' JST');
        }
    }

    function fetchHeaderStatus() {
        if (timerId !== null) {
            clearTimeout(timerId);
            timerId = null;
        }

        var currentReqId = ++requestId;

        $.ajax({
            url: '/api/status.php',
            method: 'GET',
            dataType: 'json',
            timeout: 8000,
            cache: false,
            success: function (res) {
                if (currentReqId !== requestId) return;
                if (res && res.ok && res.data) {
                    renderWorkerState(res.data.worker || {}, res.data.generated_at_jst);
                } else {
                    renderWorkerState(null, null);
                }
            },
            error: function () {
                if (currentReqId !== requestId) return;
                renderWorkerState(null, null);
            },
            complete: function () {
                if (isPollingActive) {
                    timerId = setTimeout(fetchHeaderStatus, pollIntervalMs);
                }
            }
        });
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            isPollingActive = false;
            if (timerId !== null) {
                clearTimeout(timerId);
                timerId = null;
            }
        } else {
            isPollingActive = true;
            fetchHeaderStatus();
        }
    });

    try { initTheme(); } catch (e) { console.error('initTheme failed', e); }
    try { initWallMode(); } catch (e) { console.error('initWallMode failed', e); }

    // On dashboard (index.php), dashboard.js handles full status polling
    if (!$('#pipeline-container').length && $workerPill.length) {
        fetchHeaderStatus();
    }
});
