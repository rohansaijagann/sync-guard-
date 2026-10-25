<?php
declare(strict_types=1);

/**
 * Sync Guard - Shared Application Footer Partial
 *
 * Slim, semantic footer included on all operational views (index.php, update.php, log.php).
 * Clarifies educational mock status and provides access to the raw JSON health probe.
 */
?>
<footer class="app-footer">
    <div class="footer-content">
        <span class="footer-copy">Sync Guard &middot; simplified demo with mock booking sites</span>
        <a href="/health.php" target="_blank" rel="noopener noreferrer" class="footer-status-link">System status</a>
    </div>
</footer>
