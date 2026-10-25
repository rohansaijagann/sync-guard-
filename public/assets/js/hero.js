/**
 * Sync Guard - Hero Headline State Calculator
 *
 * Evaluates operational telemetry and selects the single most urgent,
 * plain-language headline for hotel front-desk staff.
 *
 * Priority order (strict):
 * 1. Worker stopped
 * 2. Dead jobs requiring attention
 * 3. Channel down
 * 4. Channel degraded
 * 5. Channel recovering
 * 6. All healthy / default
 */
(function (root, factory) {
    if (typeof exports === 'object' && typeof module === 'object') {
        module.exports = factory();
    } else {
        root.computeHeroState = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * Compute hero banner display model.
     *
     * @param {Object} data Telemetry data from /api/status.php
     * @return {Object} Display model
     */
    return function computeHeroState(data) {
        if (!data || typeof data !== 'object') {
            return {
                headline: 'Connecting to monitoring service...',
                secondary: 'Waiting for initial telemetry data.',
                status: 'no_data',
                icon: '—',
                showDeadButton: false,
                deadCount: 0
            };
        }

        var worker = data.worker || {};
        var workerOnline = worker.online === true || worker.status === 'running';
        var summary = data.summary || {};
        var deadJobs = parseInt(data.attention_needed || summary.dead_jobs || 0, 10);
        var overallStatus = String(data.overall_status || 'no_data').toLowerCase();
        var channels = Array.isArray(data.channels) ? data.channels : [];
        var roomsAtRisk = Array.isArray(data.rooms_at_risk) ? data.rooms_at_risk.length : 0;

        // Build secondary metrics line
        var successRate = typeof summary.success_rate === 'number' ? summary.success_rate.toFixed(1) + '%' : '--%';
        var pendingJobs = parseInt(summary.pending_jobs || 0, 10);
        var failedJobs = parseInt(summary.failed_jobs || 0, 10);
        var secondary = successRate + ' success rate · ' + pendingJobs + ' pending · ' + failedJobs + ' failed';

        // 1. Worker stopped (highest priority)
        if (!workerOnline) {
            return {
                headline: 'The background worker is not running. Updates are not being sent.',
                secondary: secondary,
                status: 'down',
                icon: '✖',
                showDeadButton: deadJobs > 0,
                deadCount: deadJobs
            };
        }

        // 2. Dead jobs requiring staff attention
        if (deadJobs > 0) {
            return {
                headline: deadJobs === 1
                    ? '1 job has exhausted all delivery retries and needs attention.'
                    : deadJobs + ' jobs have exhausted all delivery retries and need attention.',
                secondary: secondary,
                status: 'down',
                icon: '✖',
                showDeadButton: true,
                deadCount: deadJobs
            };
        }

        // Helper to locate first channel matching status
        var findChannelWithStatus = function (statusName) {
            for (var i = 0; i < channels.length; i++) {
                if (String(channels[i].status).toLowerCase() === statusName) {
                    return channels[i].name || ('Channel #' + channels[i].id);
                }
            }
            return null;
        };

        // 3. Channel down
        var downChannel = findChannelWithStatus('down');
        if (downChannel || overallStatus === 'down') {
            var channelName = downChannel || 'Booking channel';
            var atRiskNote = roomsAtRisk > 0
                ? (roomsAtRisk === 1 ? '1 room may show old availability. ' : roomsAtRisk + ' rooms may show old availability. ')
                : '';
            return {
                headline: channelName + ' is down. ' + atRiskNote + 'Check the sync log.',
                secondary: secondary,
                status: 'down',
                icon: '✖',
                showDeadButton: false,
                deadCount: 0
            };
        }

        // 4. Channel degraded
        var degradedChannel = findChannelWithStatus('degraded');
        if (degradedChannel || overallStatus === 'degraded') {
            var dName = degradedChannel || 'A booking site';
            var dRisk = roomsAtRisk > 0
                ? (roomsAtRisk === 1 ? '1 room may still show old availability there.' : roomsAtRisk + ' rooms may still show old availability there.')
                : 'Delivery delays detected.';
            return {
                headline: dName + ' is degraded. ' + dRisk,
                secondary: secondary,
                status: 'degraded',
                icon: '⚠',
                showDeadButton: false,
                deadCount: 0
            };
        }

        // 5. Channel recovering
        var recoveringChannel = findChannelWithStatus('recovering');
        if (recoveringChannel || overallStatus === 'recovering') {
            var rName = recoveringChannel || 'A booking site';
            var rRisk = roomsAtRisk > 0
                ? (roomsAtRisk === 1 ? '1 room may still show old availability there.' : roomsAtRisk + ' rooms may still show old availability there.')
                : 'Deliveries are succeeding again.';
            return {
                headline: rName + ' is recovering. ' + rRisk,
                secondary: secondary,
                status: 'recovering',
                icon: '⟳',
                showDeadButton: false,
                deadCount: 0
            };
        }

        // 6. All healthy / no data
        var activeChannelCount = channels.length || 3;
        return {
            headline: 'All ' + activeChannelCount + ' sites are in sync.',
            secondary: secondary,
            status: 'healthy',
            icon: '✔',
            showDeadButton: false,
            deadCount: 0
        };
    };
});
