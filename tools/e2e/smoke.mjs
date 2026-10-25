import { chromium } from 'playwright';

const BASE_URL = process.env.BASE_URL || 'http://web';

async function runSmokeTests() {
  const startTime = Date.now();
  console.log(`\n===============================================================`);
  console.log(` SyncGuard Fast Browser Smoke Test Suite`);
  console.log(` Target: ${BASE_URL} (Playwright headless Chromium)`);
  console.log(`===============================================================\n`);

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  let totalTests = 0;
  let passedTests = 0;
  let failedTests = 0;

  async function test(name, fn) {
    totalTests++;
    const t0 = Date.now();
    try {
      await fn();
      const elapsed = ((Date.now() - t0) / 1000).toFixed(2);
      console.log(`  [PASS] Test ${totalTests}: ${name} (${elapsed}s)`);
      passedTests++;
    } catch (err) {
      const elapsed = ((Date.now() - t0) / 1000).toFixed(2);
      console.error(`  [FAIL] Test ${totalTests}: ${name} (${elapsed}s)`);
      console.error(`         ↳ Error: ${err.message}`);
      failedTests++;
    }
  }

  const context = await browser.newContext();

  function attachErrorTracking(page) {
    const errors = [];
    const httpFailures = [];
    page.on('pageerror', err => errors.push(`[PageError] ${err.message}`));
    page.on('console', msg => {
      if (msg.type() === 'error') errors.push(`[ConsoleError] ${msg.text()}`);
    });
    page.on('response', resp => {
      if (resp.status() >= 400 && !page._allowHttpErrors) {
        httpFailures.push(`${resp.status()} ${resp.url()}`);
      }
    });
    return { errors, httpFailures };
  }

  // --------------------------------------------------------------------------
  // TEST 1: Page Load & Asset Hygiene across all routes
  // --------------------------------------------------------------------------
  await test('All core pages load with 0 errors and valid jQuery', async () => {
    const routes = ['/', '/update.php', '/log.php'];
    for (const route of routes) {
      const page = await context.newPage();
      const tracker = attachErrorTracking(page);
      const resp = await page.goto(`${BASE_URL}${route}`, { waitUntil: 'domcontentloaded', timeout: 15000 });
      if (resp.status() !== 200) {
        throw new Error(`Expected HTTP 200 for ${route}, got ${resp.status()}`);
      }
      await page.waitForTimeout(500);

      const jqVersion = await page.evaluate(() => typeof window.jQuery !== 'undefined' ? window.jQuery.fn.jquery : null);
      if (!jqVersion) {
        throw new Error(`jQuery not loaded on ${route}`);
      }

      if (tracker.errors.length > 0) {
        throw new Error(`Errors on ${route}: ${tracker.errors.join('; ')}`);
      }
      if (tracker.httpFailures.length > 0) {
        throw new Error(`Failed requests on ${route}: ${tracker.httpFailures.join('; ')}`);
      }
      await page.close();
    }
  });

  // --------------------------------------------------------------------------
  // TEST 2: Theme and Wall Mode Switching & Persistence
  // --------------------------------------------------------------------------
  await test('Theme toggle and Wall mode toggle with localStorage persistence', async () => {
    const page = await context.newPage();
    attachErrorTracking(page);
    await page.goto(`${BASE_URL}/update.php`, { waitUntil: 'domcontentloaded' });

    const initialTheme = await page.evaluate(() => document.documentElement.getAttribute('data-theme') || 'dark');
    const targetTheme = initialTheme === 'dark' ? 'light' : 'dark';

    await page.click('#theme-toggle');
    const toggledTheme = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
    if (toggledTheme !== targetTheme) {
      throw new Error(`Theme toggle failed: expected ${targetTheme}, got ${toggledTheme}`);
    }

    await page.reload({ waitUntil: 'domcontentloaded' });
    const reloadedTheme = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
    if (reloadedTheme !== targetTheme) {
      throw new Error(`Theme persistence failed: expected ${targetTheme} after reload, got ${reloadedTheme}`);
    }

    const wallModeBtn = await page.$('#wall-mode-toggle');
    if (wallModeBtn) {
      await wallModeBtn.click();
      const hasWallClass = await page.evaluate(() => document.body.classList.contains('wall-mode'));
      if (!hasWallClass) {
        throw new Error('Wall mode click did not add .wall-mode to body');
      }
      await wallModeBtn.click();
      const removedWallClass = await page.evaluate(() => !document.body.classList.contains('wall-mode'));
      if (!removedWallClass) {
        throw new Error('Wall mode second click did not remove .wall-mode from body');
      }
    }
    await page.close();
  });

  // --------------------------------------------------------------------------
  // TEST 3: update.php Interactive Controls (Room, Presets, Stepper, Price IME)
  // --------------------------------------------------------------------------
  await test('update.php room helper, date presets, stepper, and IME digits conversion', async () => {
    const page = await context.newPage();
    attachErrorTracking(page);
    await page.goto(`${BASE_URL}/update.php`, { waitUntil: 'domcontentloaded' });

    await page.selectOption('#room_id', '1');
    const helperText = await page.textContent('#room_helper_text');
    if (!helperText || !helperText.includes('rooms in total')) {
      throw new Error(`Room helper text failed to update: got "${helperText}"`);
    }
    const allRoomsBtnText = await page.textContent('#btn-all-rooms');
    if (!allRoomsBtnText.includes('(')) {
      throw new Error(`All rooms button text missing capacity count: got "${allRoomsBtnText}"`);
    }

    await page.click('button[data-preset="next7"]');
    const fromVal = await page.inputValue('#date_from');
    const toVal = await page.inputValue('#date_to');
    if (!fromVal || !toVal || fromVal >= toVal) {
      throw new Error(`Next 7 days preset failed: from=${fromVal}, to=${toVal}`);
    }
    const dateSummary = await page.textContent('#date_live_summary');
    if (!dateSummary.includes('7 days')) {
      throw new Error(`Date live summary failed to reflect 7 days: got "${dateSummary}"`);
    }

    await page.click('#btn-sold-out');
    let unitsVal = await page.inputValue('#available_units');
    if (unitsVal !== '0') {
      throw new Error(`Sold out button failed: expected "0", got "${unitsVal}"`);
    }

    await page.click('#btn-units-minus');
    unitsVal = await page.inputValue('#available_units');
    if (unitsVal !== '0') {
      throw new Error(`Stepper minus on 0 decreased below 0: got "${unitsVal}"`);
    }

    await page.click('#btn-units-plus');
    unitsVal = await page.inputValue('#available_units');
    if (unitsVal !== '1') {
      throw new Error(`Stepper plus failed: expected "1", got "${unitsVal}"`);
    }

    await page.click('#btn-all-rooms');
    const allRoomsVal = await page.inputValue('#available_units');
    if (parseInt(allRoomsVal, 10) <= 0) {
      throw new Error(`All rooms button failed to populate max capacity: got "${allRoomsVal}"`);
    }

    await page.fill('#price', '１２０００');
    await page.dispatchEvent('#price', 'blur');
    const formattedPrice = await page.inputValue('#price');
    if (formattedPrice !== '12,000') {
      throw new Error(`Full-width IME normalization failed: expected "12,000", got "${formattedPrice}"`);
    }

    await page.close();
  });

  // --------------------------------------------------------------------------
  // TEST 4: update.php Form Submission, Client Validation & Idempotency
  // --------------------------------------------------------------------------
  await test('update.php client validation, AJAX submit, and result card display', async () => {
    const page = await context.newPage();
    attachErrorTracking(page);
    await page.goto(`${BASE_URL}/update.php`, { waitUntil: 'domcontentloaded' });

    // Submit empty form -> validation error summary
    await page.click('#submit_btn');
    const errorSummaryVisible = await page.isVisible('#form-error-summary');
    if (!errorSummaryVisible) {
      throw new Error('Expected #form-error-summary to be visible on empty submit');
    }
    const currentUrl = page.url();
    if (!currentUrl.includes('/update.php') || currentUrl.includes('/api/')) {
      throw new Error(`Form navigated away from update.php on validation failure: ${currentUrl}`);
    }

    // Fill valid form
    await page.selectOption('#room_id', '1');
    await page.click('button[data-preset="tomorrow"]');
    await page.fill('#available_units', '5');
    await page.fill('#price', '15000');
    await page.dispatchEvent('#price', 'blur');

    // Submit form
    await page.click('#submit_btn');

    // Wait for result-card
    await page.waitForSelector('#result-card:not(.u-hidden)', { timeout: 10000 });
    const resultText = await page.textContent('#result-message');
    if (!resultText.includes('Saved')) {
      throw new Error(`Expected result card to say Saved, got "${resultText}"`);
    }

    // URL must still be on update.php
    if (!page.url().includes('/update.php')) {
      throw new Error(`Page submitted as plain form post to ${page.url()}`);
    }

    await page.close();
  });

  // --------------------------------------------------------------------------
  // TEST 5: log.php Table Rendering, Status Tabs, Details & Retry
  // --------------------------------------------------------------------------
  await test('log.php table loads, status tabs filter rows, and Details expands history', async () => {
    const page = await context.newPage();
    attachErrorTracking(page);
    await page.goto(`${BASE_URL}/log.php`, { waitUntil: 'domcontentloaded' });

    // Wait for table to load rows
    await page.waitForSelector('#jobs-tbody tr.job-row', { timeout: 10000 });
    const rowCount = await page.evaluate(() => document.querySelectorAll('#jobs-tbody tr.job-row').length);
    if (rowCount === 0) {
      throw new Error('No jobs rendered in log table');
    }

    const summaryText = await page.textContent('#results-summary');
    if (!summaryText.includes('Showing') || summaryText.includes('Loading')) {
      throw new Error(`Results summary unexpected: "${summaryText}"`);
    }

    // Test Status Tabs click
    const unsyncedTab = await page.$('.status-tab[data-status="unsynced"]');
    if (unsyncedTab) {
      await unsyncedTab.click();
      await page.waitForTimeout(500);
      if (!page.url().includes('status=unsynced')) {
        throw new Error(`Status tab click failed to update URL to status=unsynced: ${page.url()}`);
      }
    }

    // Test Reset filters
    await page.click('#btn-reset-filters');
    await page.waitForTimeout(500);
    const resetTabActive = await page.evaluate(() => document.querySelector('.status-tab[data-status="all"]').classList.contains('active'));
    if (!resetTabActive) {
      throw new Error('Reset filters did not re-activate "All" status tab');
    }

    // Test Details row expansion
    const firstDetailsBtn = await page.$('#jobs-tbody .btn-details');
    if (firstDetailsBtn) {
      await firstDetailsBtn.click();
      await page.waitForTimeout(500);
      const isExpanded = await page.evaluate(() => {
        const row = document.querySelector('#jobs-tbody tr.details-row');
        return row && window.getComputedStyle(row).display !== 'none';
      });
      if (!isExpanded) {
        throw new Error('Clicking Details did not expand details row');
      }
    }

    await page.close();
  });

  // --------------------------------------------------------------------------
  // TEST 6: log.php Error / Failure Drill
  // --------------------------------------------------------------------------
  await test('log.php displays graceful error state and Retry button when API fails', async () => {
    const page = await context.newPage();
    page._allowHttpErrors = true;

    // Intercept /api/jobs.php and force 500 error
    await page.route('**/api/jobs.php*', route => route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ ok: false, error: 'Database unavailable' })
    }));

    await page.goto(`${BASE_URL}/log.php`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#error-state', { state: 'visible', timeout: 10000 });

    const errorTitle = await page.textContent('#error-title');
    if (!errorTitle.includes('Could not load the sync log')) {
      throw new Error(`Expected error title "Could not load the sync log.", got "${errorTitle}"`);
    }

    const retryBtnVisible = await page.isVisible('#btn-retry-fetch');
    if (!retryBtnVisible) {
      throw new Error('Expected #btn-retry-fetch to be visible in error state');
    }

    const loadingHidden = await page.evaluate(() => {
      const el = document.getElementById('loading-state');
      return el.classList.contains('u-hidden') || window.getComputedStyle(el).display === 'none';
    });
    if (!loadingHidden) {
      throw new Error('Loading state was not hidden when error occurred');
    }

    await page.close();
  });

  // --------------------------------------------------------------------------
  // TEST 7: Mock Booking Sites (Site A, Site B, Site C) Hygiene & Japanese Names
  // --------------------------------------------------------------------------
  await test('Mock pages load with 0 errors, render counters, and display Japanese room names', async () => {
    const mockSites = [
      { route: '/mock/site_a.php', name: 'Site A' },
      { route: '/mock/site_b.php', name: 'Site B' },
      { route: '/mock/site_c.php', name: 'Site C' },
    ];

    for (const site of mockSites) {
      const page = await context.newPage();
      const tracker = attachErrorTracking(page);
      const resp = await page.goto(`${BASE_URL}${site.route}`, { waitUntil: 'domcontentloaded', timeout: 15000 });
      if (resp.status() !== 200) {
        throw new Error(`Expected HTTP 200 for ${site.route}, got ${resp.status()}`);
      }

      await page.waitForTimeout(600);

      if (tracker.errors.length > 0) {
        throw new Error(`Errors on ${site.route}: ${tracker.errors.join('; ')}`);
      }
      if (tracker.httpFailures.length > 0) {
        throw new Error(`Failed requests on ${site.route}: ${tracker.httpFailures.join('; ')}`);
      }

      // Check counters exist and are rendered
      const receivedVal = await page.textContent('#stat-received-count');
      const duplicateVal = await page.textContent('#stat-duplicate-count');
      if (receivedVal === null || duplicateVal === null) {
        throw new Error(`Counters missing on ${site.route}`);
      }

      // Check row with Japanese room name is rendered
      const hasJapaneseRow = await page.evaluate(() => {
        const rows = document.querySelectorAll('#mock-updates-tbody tr.mock-row');
        for (const r of rows) {
          const txt = r.textContent || '';
          if (txt.includes('シングルルーム') || txt.includes('ツインルーム') || txt.includes('和室') || txt.includes('スイート')) {
            return true;
          }
        }
        return false;
      });

      if (!hasJapaneseRow) {
        throw new Error(`No Japanese room name row rendered on ${site.route}`);
      }

      // Worker pill on mock page shows real state (not "Checking" or "--:--")
      const pillText = await page.textContent('#worker-text');
      if (!pillText || pillText.includes('Checking') || pillText.includes('--:--')) {
        throw new Error(`Worker pill on ${site.route} is stuck on "${pillText}"`);
      }

      await page.close();
    }
  });

  // --------------------------------------------------------------------------
  // TEST 8: Shared Header Worker Pill & Sticky Header Clearance on log.php
  // --------------------------------------------------------------------------
  await test('log.php worker pill shows real state and toolbar is not covered by sticky header after scroll', async () => {
    const page = await context.newPage();
    attachErrorTracking(page);
    await page.goto(`${BASE_URL}/log.php`, { waitUntil: 'domcontentloaded' });

    // Wait for header-status.js to poll status.php
    await page.waitForTimeout(1000);

    const pillText = await page.textContent('#worker-text');
    if (!pillText || pillText.includes('Checking')) {
      throw new Error(`Worker pill on log.php is stuck on "${pillText}"`);
    }

    // Scroll to #main
    await page.evaluate(() => {
      const el = document.getElementById('main');
      if (el) el.scrollIntoView();
    });
    await page.waitForTimeout(400);

    // Bounding box verification: toolbar card must not be covered by sticky header
    const overlapCheck = await page.evaluate(() => {
      const header = document.querySelector('.app-header');
      const toolbar = document.querySelector('.log-toolbar-card');
      if (!header || !toolbar) return { covered: true, msg: 'elements not found' };

      const hRect = header.getBoundingClientRect();
      const tRect = toolbar.getBoundingClientRect();

      // The top of the toolbar should be at or below the bottom of the sticky header
      const covered = tRect.top < hRect.bottom;
      return {
        covered,
        headerBottom: hRect.bottom,
        toolbarTop: tRect.top
      };
    });

    if (overlapCheck.covered) {
      throw new Error(`Log toolbar is covered by sticky header: toolbarTop (${overlapCheck.toolbarTop}px) < headerBottom (${overlapCheck.headerBottom}px)`);
    }

    await page.close();
  });

  await browser.close();

  const totalDuration = ((Date.now() - startTime) / 1000).toFixed(2);
  console.log(`\n===============================================================`);
  console.log(` Results: ${passedTests}/${totalTests} Passed (${failedTests} Failed) in ${totalDuration}s`);
  console.log(`===============================================================\n`);

  if (failedTests > 0) {
    process.exit(1);
  }
}

runSmokeTests().catch(err => {
  console.error('Smoke test suite crashed:', err);
  process.exit(1);
});
