import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const BASE_URL = process.env.BASE_URL || 'http://web';
const SCREENSHOTS_DIR = '/repo/docs/screenshots';
const VIDEO_DIR = '/repo/docs/screenshots/video_tmp';

if (!fs.existsSync(SCREENSHOTS_DIR)) {
  fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}
if (!fs.existsSync(VIDEO_DIR)) {
  fs.mkdirSync(VIDEO_DIR, { recursive: true });
}

// Helpers to drive state via internal demo API over HTTP
async function callDemo(action, data = {}, retries = 2) {
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const resp = await fetch(`${BASE_URL}/api/demo.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'Connection': 'close'
        },
        body: JSON.stringify({ action, ...data })
      });
      if (!resp.ok) {
        const txt = await resp.text();
        throw new Error(`Demo action ${action} failed (${resp.status}): ${txt}`);
      }
      return await resp.json();
    } catch (err) {
      if (attempt === retries) throw err;
      await new Promise(r => setTimeout(r, 500));
    }
  }
}

async function resetQueue() {
  await callDemo('reset_queue');
  await callDemo('reset_modes');
}

async function setMock(site, mode) {
  await callDemo('set_mode', { site, mode });
}

async function workerOnce() {
  return await callDemo('run_worker');
}

async function fastForward() {
  return await callDemo('skip_wait');
}

async function submitUpdate(roomId = 1, units = 4, price = 15000, date = null, retries = 2) {
  const d = date || new Date(Date.now() + 86400000).toISOString().split('T')[0];
  const payload = {
    room_id: roomId,
    date: d,
    units: units,
    price: price,
    client_idempotency_key: 'cap_' + Math.random().toString(36).substring(2, 10)
  };
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      const resp = await fetch(`${BASE_URL}/api/inventory.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Connection': 'close'
        },
        body: JSON.stringify(payload)
      });
      return await resp.json();
    } catch (err) {
      if (attempt === retries) throw err;
      await new Promise(r => setTimeout(r, 500));
    }
  }
}

// Helper to inject temporary visible highlight ring on clicks
async function clickWithHighlight(page, selector) {
  const el = await page.waitForSelector(selector, { state: 'visible', timeout: 6000 });
  await el.evaluate(node => {
    const origOutline = node.style.outline;
    const origBoxShadow = node.style.boxShadow;
    node.style.outline = '3px solid #3b82f6';
    node.style.boxShadow = '0 0 14px rgba(59, 130, 246, 0.9)';
    node.style.transition = 'all 0.2s ease-in-out';
    setTimeout(() => {
      node.style.outline = origOutline;
      node.style.boxShadow = origBoxShadow;
    }, 500);
  });
  await new Promise(r => setTimeout(r, 200));
  await el.click();
  await new Promise(r => setTimeout(r, 400));
}

// Quality checks before capture: wait for stable content, fonts, and active worker
async function waitForStableContent(page, isMobile = false) {
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts.ready);
  try {
    await page.waitForFunction(() => {
      const pill = document.querySelector('#header-worker-status, #status-pill, .worker-pill');
      if (!pill) return true;
      const t = pill.innerText || '';
      return !t.includes('Checking') && !t.includes('--:--');
    }, { timeout: 4000 });
  } catch (e) {}
}

async function captureGallery() {
  console.log('>>> Starting Story Gallery Capture (01 to 26)...');

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  const baseOptions = {
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 2,
    locale: 'en-US',
    timezoneId: 'Asia/Tokyo',
    colorScheme: 'dark'
  };

  // 01-dashboard-empty.png: no data, worker online
  console.log('Capturing 01-dashboard-empty.png...');
  await resetQueue();
  await workerOnce();
  {
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/01-dashboard-empty.png` });
    await page.close();
  }

  // 02-update-form-filled.png: Japanese room chosen, date preset applied, units & price typed
  console.log('Capturing 02-update-form-filled.png...');
  {
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/update.php`);
    await waitForStableContent(page);
    await page.selectOption('#room_id', '1'); // Deluxe Ocean View / 富士の間
    await page.click('[data-preset="next7"]');
    await page.fill('#available_units', '5');
    await page.fill('#price', '18000');
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/02-update-form-filled.png` });
    await page.close();
  }

  // 03-update-form-validation.png: validation error summary after pressing Save empty
  console.log('Capturing 03-update-form-validation.png...');
  {
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/update.php`);
    await waitForStableContent(page);
    await page.fill('#available_units', '');
    await page.fill('#price', '');
    await page.click('#submit_btn');
    await page.waitForSelector('#form-error-summary:not(.u-hidden), .alert-error:not(.u-hidden)', { timeout: 4000 });
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/03-update-form-validation.png` });
    await page.close();
  }

  // 04-update-saved.png: success card and new row showing "Sending"
  console.log('Capturing 04-update-saved.png...');
  {
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/update.php`);
    await waitForStableContent(page);
    await page.selectOption('#room_id', '1');
    await page.click('[data-preset="tomorrow"]');
    await page.fill('#available_units', '4');
    await page.fill('#price', '16500');
    await page.click('#submit_btn');
    await page.waitForSelector('#result-card:not(.u-hidden)', { timeout: 4000 });
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/04-update-saved.png` });
    await page.close();
  }

  // 05-sync-log-pending.png: log.php showing 3 new jobs as pending
  console.log('Capturing 05-sync-log-pending.png...');
  {
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/log.php`);
    await waitForStableContent(page);
    await page.waitForSelector('#jobs-tbody tr', { timeout: 4000 });
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/05-sync-log-pending.png` });
    await page.close();
  }

  // 06-dashboard-delivering.png: after one worker pass
  console.log('Capturing 06-dashboard-delivering.png...');
  {
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/06-dashboard-delivering.png` });
    await page.close();
  }

  // 07-dashboard-healthy.png: site_c always_succeed, 3 updates delivered, full page
  console.log('Capturing 07-dashboard-healthy.png...');
  {
    await setMock('site_c', 'always_succeed');
    await setMock('site_b', 'normal');
    await submitUpdate(1, 3, 15000);
    await submitUpdate(2, 2, 22000);
    await submitUpdate(3, 4, 18500);
    await workerOnce();
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/07-dashboard-healthy.png`, fullPage: true });
    await page.close();
  }

  // 08, 09, 10: Mock booking sites
  console.log('Capturing 08, 09, 10 mock booking sites...');
  {
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/mock/site_a.php`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/08-mock-site-a.png` });

    await page.goto(`${BASE_URL}/mock/site_b.php`);
    await waitForStableContent(page);
    try {
      const details = await page.$('details');
      if (details) await details.click();
    } catch (e) {}
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/09-mock-site-b.png` });

    await page.goto(`${BASE_URL}/mock/site_c.php`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/10-mock-site-c.png` });
    await page.close();
  }

  // 11-dashboard-incident.png: site_c always_fail, 3 updates, one pass (C failing, at risk)
  console.log('Capturing 11-dashboard-incident.png...');
  {
    await setMock('site_c', 'always_fail');
    await submitUpdate(1, 1, 14000);
    await submitUpdate(2, 0, 21000);
    await submitUpdate(3, 2, 17500);
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/11-dashboard-incident.png` });
    await page.close();
  }

  // 12-sync-log-failed.png: log.php?status=failed with plain language messages
  console.log('Capturing 12-sync-log-failed.png...');
  {
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/log.php?status=failed`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/12-sync-log-failed.png` });
    await page.close();
  }

  // 13-dashboard-critical.png: fast forward + worker passes until at least one job dead
  console.log('Capturing 13-dashboard-critical.png...');
  {
    for (let i = 0; i < 5; i++) {
      await fastForward();
      await workerOnce();
    }
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/13-dashboard-critical.png` });
    await page.close();
  }

  // 14-sync-log-dead-details.png: log.php?status=dead with Details open
  console.log('Capturing 14-sync-log-dead-details.png...');
  {
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/log.php?status=dead`);
    await waitForStableContent(page);
    try {
      const detailsBtn = await page.$('.btn-details');
      if (detailsBtn) await detailsBtn.click();
    } catch (e) {}
    await new Promise(r => setTimeout(r, 400));
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/14-sync-log-dead-details.png` });
    await page.close();
  }

  // 15-retry-done.png: after clicking Retry on a dead job in UI
  console.log('Capturing 15-retry-done.png...');
  {
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/log.php?status=dead`);
    await waitForStableContent(page);
    try {
      const retryBtn = await page.$('.btn-retry');
      if (retryBtn) {
        await retryBtn.click();
        await new Promise(r => setTimeout(r, 600));
      }
    } catch (e) {}
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/15-retry-done.png` });
    await page.close();
  }

  // 16-dashboard-recovered.png: site_c always_succeed, delivered, recovered
  console.log('Capturing 16-dashboard-recovered.png...');
  {
    await setMock('site_c', 'always_succeed');
    await workerOnce();
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/16-dashboard-recovered.png` });
    await page.close();
  }

  // 17-dashboard-light.png: healthy state in light theme
  console.log('Capturing 17-dashboard-light.png...');
  {
    await workerOnce();
    const lightOptions = { ...baseOptions, colorScheme: 'light' };
    const page = await browser.newPage(lightOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await page.evaluate(() => {
      document.documentElement.setAttribute('data-theme', 'light');
      localStorage.setItem('syncguard_theme', 'light');
    });
    await waitForStableContent(page);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/17-dashboard-light.png` });
    await page.close();
  }

  // 18, 19, 20 Mobile Views (390x844)
  console.log('Capturing mobile screens (18, 19, 20)...');
  const mobileOptions = {
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    locale: 'en-US',
    timezoneId: 'Asia/Tokyo',
    colorScheme: 'dark'
  };

  {
    const page = await browser.newPage(mobileOptions);
    await workerOnce();
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page, true);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/18-dashboard-mobile.png` });

    await page.goto(`${BASE_URL}/update.php`);
    await waitForStableContent(page, true);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/19-update-form-mobile.png` });

    await page.goto(`${BASE_URL}/log.php`);
    await waitForStableContent(page, true);
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/20-sync-log-mobile.png` });
    await page.close();
  }

  // 21-dashboard-wall-mode.png: Wall mode on
  console.log('Capturing 21-dashboard-wall-mode.png...');
  {
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page);
    await page.evaluate(() => {
      document.body.classList.add('wall-mode');
      const toggle = document.querySelector('#wall-toggle');
      if (toggle) toggle.checked = true;
    });
    await new Promise(r => setTimeout(r, 400));
    await page.screenshot({ path: `${SCREENSHOTS_DIR}/21-dashboard-wall-mode.png` });
    await page.close();
  }

  // 22-26 Component sections that exist on dashboard
  console.log('Capturing component sections (22 to 26)...');
  {
    await workerOnce();
    const page = await browser.newPage(baseOptions);
    await page.goto(`${BASE_URL}/index.php`);
    await waitForStableContent(page);

    const consistencyEl = await page.$('#consistency-card, [aria-labelledby="consistency-heading"]');
    if (consistencyEl) {
      await consistencyEl.screenshot({ path: `${SCREENSHOTS_DIR}/22-section-consistency.png` });
    }

    const calendarEl = await page.$('#calendar-section');
    if (calendarEl) {
      await calendarEl.screenshot({ path: `${SCREENSHOTS_DIR}/23-section-calendar.png` });
    }

    const chartEl = await page.$('.chart-card');
    if (chartEl) {
      await chartEl.screenshot({ path: `${SCREENSHOTS_DIR}/24-section-chart.png` });
    }

    const activityEl = await page.$('.recent-activity-card');
    if (activityEl) {
      await activityEl.screenshot({ path: `${SCREENSHOTS_DIR}/25-section-activity.png` });
    }

    const demoPanel = await page.$('#demo-controls-panel');
    if (demoPanel) {
      await page.evaluate(() => {
        const p = document.querySelector('#demo-controls-panel');
        if (p) p.open = true;
      });
      await new Promise(r => setTimeout(r, 300));
      await demoPanel.screenshot({ path: `${SCREENSHOTS_DIR}/26-section-demo-panel.png` });
    }

    await page.close();
  }

  await browser.close();
  console.log('>>> Story gallery captured successfully.');
}

async function recordDemoAnimation() {
  console.log('>>> Starting Demo Video Recording (40-60s Playwright continuous story)...');

  await resetQueue();
  await setMock('site_b', 'normal');
  await setMock('site_c', 'always_succeed');
  await submitUpdate(1, 4, 15000);
  await workerOnce();
  await workerOnce();

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  const context = await browser.newContext({
    viewport: { width: 1280, height: 720 },
    deviceScaleFactor: 1,
    locale: 'en-US',
    timezoneId: 'Asia/Tokyo',
    colorScheme: 'dark',
    recordVideo: {
      dir: VIDEO_DIR,
      size: { width: 1280, height: 720 }
    }
  });

  const page = await context.newPage();

  // Step 1: Start at healthy dashboard
  await workerOnce();
  await page.goto(`${BASE_URL}/index.php`);
  await waitForStableContent(page);
  await new Promise(r => setTimeout(r, 1200));

  // Step 2: Navigate to Update inventory
  await clickWithHighlight(page, 'a[href*="update.php"]');
  await waitForStableContent(page);
  await new Promise(r => setTimeout(r, 800));

  // Step 3: Pick room, date preset, units, price, Save
  await page.selectOption('#room_id', '2'); // Superior Tatami Room
  await new Promise(r => setTimeout(r, 600));
  await clickWithHighlight(page, '[data-preset="weekend"]');
  await new Promise(r => setTimeout(r, 500));
  await page.fill('#available_units', '3');
  await new Promise(r => setTimeout(r, 400));
  await page.fill('#price', '21000');
  await new Promise(r => setTimeout(r, 600));
  await clickWithHighlight(page, '#submit_btn');
  await page.waitForSelector('#result-card:not(.u-hidden)', { timeout: 4000 });
  await new Promise(r => setTimeout(r, 1200));

  // Step 4: Back to dashboard to watch delivery
  await clickWithHighlight(page, 'a.brand-link, a.nav-link[href="/"]');
  await waitForStableContent(page);
  await workerOnce();
  await new Promise(r => setTimeout(r, 1200));

  // Step 5: Break site_c and save another update
  await setMock('site_c', 'always_fail');
  await clickWithHighlight(page, 'a[href*="update.php"]');
  await waitForStableContent(page);
  await page.selectOption('#room_id', '3'); // Traditional Suite
  await clickWithHighlight(page, '[data-preset="next7"]');
  await page.fill('#available_units', '2');
  await page.fill('#price', '32000');
  await clickWithHighlight(page, '#submit_btn');
  await page.waitForSelector('#result-card:not(.u-hidden)', { timeout: 4000 });
  await new Promise(r => setTimeout(r, 1000));

  // Step 6: Back to dashboard, worker pass shows C failing & rooms at risk
  await workerOnce();
  await clickWithHighlight(page, 'a.brand-link, a.nav-link[href="/"]');
  await waitForStableContent(page);
  await new Promise(r => setTimeout(r, 1800));

  // Step 7: Fast-forward until job is dead
  for (let i = 0; i < 4; i++) {
    await fastForward();
    await workerOnce();
  }
  await page.reload();
  await waitForStableContent(page);
  await new Promise(r => setTimeout(r, 2000));

  // Step 8: Open sync log & Details on dead job
  await clickWithHighlight(page, 'a[href*="log.php"]');
  await waitForStableContent(page);
  await new Promise(r => setTimeout(r, 800));
  try {
    await clickWithHighlight(page, '.btn-details');
    await new Promise(r => setTimeout(r, 1500));
  } catch (e) {}

  // Step 9: Fix Site C, click Retry
  await setMock('site_c', 'always_succeed');
  try {
    await clickWithHighlight(page, '.btn-retry');
    await new Promise(r => setTimeout(r, 1200));
  } catch (e) {}

  // Step 10: Deliver and verify recovered dashboard
  await workerOnce();
  await workerOnce();
  await clickWithHighlight(page, 'a.brand-link, a.nav-link[href="/"]');
  await waitForStableContent(page);
  await new Promise(r => setTimeout(r, 2000));

  await page.close();
  await context.close();
  await browser.close();

  // Find recorded video file and save to failure-demo.webm
  const videoFiles = fs.readdirSync(VIDEO_DIR).filter(f => f.endsWith('.webm'));
  if (videoFiles.length > 0) {
    const rawVideo = path.join(VIDEO_DIR, videoFiles[0]);
    console.log(`Recorded video captured: ${rawVideo}`);
    fs.copyFileSync(rawVideo, path.join(SCREENSHOTS_DIR, 'failure-demo.webm'));
  }

  await resetQueue();
  console.log('>>> Demo video recording complete.');
}

async function main() {
  const screenshots = fs.existsSync(SCREENSHOTS_DIR) ? fs.readdirSync(SCREENSHOTS_DIR).filter(f => f.endsWith('.png')) : [];
  if (screenshots.length >= 26) {
    console.log('>>> Gallery screenshots already exist (26 images). Skipping gallery and recording demo video...');
  } else {
    await captureGallery();
  }
  await recordDemoAnimation();
  console.log('>>> All screenshots and video capture completed successfully.');
}

main().catch(err => {
  console.error('Capture failed:', err);
  process.exit(1);
});
