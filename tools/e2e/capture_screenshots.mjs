import { chromium } from 'playwright';
import path from 'path';

const BASE_URL = process.env.BASE_URL || 'http://web';
const SCREENSHOT_DIR = process.env.SCREENSHOT_DIR || '/screenshots';

async function capture() {
  console.log(`Starting Visual Review Screenshot Capture...`);
  console.log(`Target: ${BASE_URL}, Output: ${SCREENSHOT_DIR}`);

  const browser = await chromium.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });

  async function take(url, filename, viewport, theme = 'dark', scrollTarget = null) {
    const page = await browser.newPage({ viewport });
    // Set theme in localStorage before load
    await page.addInitScript((th) => {
      try {
        localStorage.setItem('syncguard_theme', th);
        document.documentElement.setAttribute('data-theme', th);
      } catch (e) {}
    }, theme);

    await page.goto(`${BASE_URL}${url}`, { waitUntil: 'networkidle', timeout: 15000 });
    // Wait for JS execution and clock/status polling
    await page.waitForTimeout(1000);

    if (scrollTarget) {
      if (typeof scrollTarget === 'number') {
        await page.evaluate((y) => window.scrollTo(0, y), scrollTarget);
      } else if (typeof scrollTarget === 'string') {
        await page.evaluate((sel) => {
          const el = document.querySelector(sel);
          if (el) el.scrollIntoView();
        }, scrollTarget);
      }
      await page.waitForTimeout(500);
    }

    const outPath = path.join(SCREENSHOT_DIR, filename);
    await page.screenshot({ path: outPath, fullPage: false });
    console.log(`Saved screenshot: ${filename} (${viewport.width}x${viewport.height}, theme: ${theme})`);
    await page.close();
  }

  const vpDesktop = { width: 1440, height: 900 };
  const vpMobile = { width: 390, height: 844 };

  // 1. Required final screenshots
  await take('/mock/site_a.php', 'mock-site-a.png', vpDesktop, 'dark');
  await take('/mock/site_b.php', 'mock-site-b.png', vpDesktop, 'dark');
  await take('/mock/site_c.php', 'mock-site-c.png', vpDesktop, 'dark');
  await take('/mock/site_a.php', 'mock-site-a-mobile.png', vpMobile, 'dark');
  await take('/log.php', 'log-fixed.png', vpDesktop, 'dark', '#main');

  // 2. Comparison reference screenshots for visual review
  await take('/mock/site_a.php', 'review-site-a-light.png', vpDesktop, 'light');
  await take('/mock/site_b.php', 'review-site-b-light.png', vpDesktop, 'light');
  await take('/mock/site_c.php', 'review-site-c-light.png', vpDesktop, 'light');
  await take('/', 'review-index-dark.png', vpDesktop, 'dark');
  await take('/update.php', 'review-update-dark.png', vpDesktop, 'dark');
  await take('/log.php', 'review-log-dark.png', vpDesktop, 'dark');

  await browser.close();
  console.log('Visual Review Screenshot Capture Complete!');
}

capture().catch((err) => {
  console.error('Screenshot capture failed:', err);
  process.exit(1);
});
