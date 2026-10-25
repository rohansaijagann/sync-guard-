<?php
declare(strict_types=1);

/**
 * Sync Guard - Shared Application Header Partial
 *
 * Included by operational pages (index.php, log.php, update.php)
 * for uniform navigation, system health telemetry, theme switching, and wall mode.
 *
 * @var string $currentPage Active page identifier ('dashboard'|'update'|'log')
 */
$currentPage = $currentPage ?? 'dashboard';
?>
<!-- Application Header -->
<header class="app-header">
    <div class="header-brand">
        <a href="/" class="brand-link">
            <svg class="brand-shield" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                <path d="M9 12l2 2 4-4"></path>
            </svg>
            <span class="brand-text">Sync Guard</span>
            <?php if (!empty($isMockPage)): ?>
                <span class="badge badge-neutral mock-server-tag">Mock server</span>
            <?php endif; ?>
        </a>
        <span class="brand-tagline"><?= !empty($isMockPage) ? 'Partner simulation environment' : 'Hotel channel sync monitor' ?></span>
    </div>

    <nav class="main-nav" aria-label="Main Navigation">
        <a href="/" class="nav-link <?= ($currentPage === 'dashboard') ? 'active' : '' ?>" <?= ($currentPage === 'dashboard') ? 'aria-current="page"' : '' ?>>Dashboard</a>
        <a href="/update.php" class="nav-link <?= ($currentPage === 'update') ? 'active' : '' ?>" <?= ($currentPage === 'update') ? 'aria-current="page"' : '' ?>>Update inventory</a>
        <a href="/log.php" class="nav-link <?= ($currentPage === 'log') ? 'active' : '' ?>" <?= ($currentPage === 'log') ? 'aria-current="page"' : '' ?>>Sync log</a>
    </nav>

    <div class="header-controls">
        <span id="worker-pill" class="worker-pill pill-unknown" title="Worker heartbeat status">
            <span class="pill-dot" aria-hidden="true"></span>
            <span id="worker-text">Checking worker...</span>
        </span>

        <button type="button" id="theme-toggle" class="btn btn-icon btn-theme-toggle" aria-pressed="false" aria-label="Toggle dark/light theme" title="Toggle dark/light theme">
            <span id="theme-toggle-icon" aria-hidden="true">🌙</span>
        </button>

        <button type="button" id="wall-mode-toggle" class="btn btn-sm btn-wall-toggle" aria-pressed="false" aria-label="Toggle wall display mode" title="Fullscreen wallboard view">
            Wall mode
        </button>

        <span class="timestamp-pill" title="Last successful telemetry poll">
            <span class="refresh-pulse-dot" aria-hidden="true"></span>
            <span id="last-updated-text">--:--:-- JST</span>
        </span>
    </div>
</header>
