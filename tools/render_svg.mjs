import { chromium } from 'playwright';
import fs from 'fs';

async function renderSvg(svgPath, pngPath, width, height) {
    const svgContent = fs.readFileSync(svgPath, 'utf8');
    const browser = await chromium.launch();
    const page = await browser.newPage({
        viewport: { width, height },
        deviceScaleFactor: 2
    });
    await page.setContent(`<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  html, body { margin: 0; padding: 0; overflow: hidden; background: #f8fafc; width: ${width}px; height: ${height}px; }
  svg { display: block; width: 100%; height: 100%; }
</style>
</head>
<body>
${svgContent}
</body>
</html>`);
    await page.screenshot({ path: pngPath });
    await browser.close();
    console.log(`Successfully rendered ${pngPath} (${width}x${height} @2x)`);
}

async function main() {
    await renderSvg('/repo/docs/images/architecture.svg', '/repo/docs/images/architecture.png', 1600, 900);
    await renderSvg('/repo/docs/images/job-lifecycle.svg', '/repo/docs/images/job-lifecycle.png', 1200, 600);
}

main().catch(err => {
    console.error(err);
    process.exit(1);
});
