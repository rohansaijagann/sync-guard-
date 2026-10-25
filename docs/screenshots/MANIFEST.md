# Screenshots & Animation Manifest

This document catalogs every captured screenshot and animated walkthrough in `docs/screenshots/`, detailing the source page, simulated state, and capture method.

All screenshots are authentic, high-resolution (deviceScaleFactor: 2) captures of the running Sync Guard application executed inside an isolated Playwright Chromium container against `http://web`. Video conversion was processed using an official FFmpeg container.

---

## 1. Automated Story Gallery (01 – 26)

| File | Page / URL | State / Condition | Viewport |
| :--- | :--- | :--- | :--- |
| `01-dashboard-empty.png` | `/index.php` | Zero queue state; worker online heartbeat verified; all metrics zeroed. | 1440x900 @2x |
| `02-update-form-filled.png` | `/update.php` | Japanese room selected (富士の間), "Next 7 days" preset applied, units (5) and price (¥18,000) filled. | 1440x900 @2x |
| `03-update-form-validation.png` | `/update.php` | Client-side validation error summary displayed after attempting empty save. | 1440x900 @2x |
| `04-update-saved.png` | `/update.php` | Form submission success card with delivery link; new update registered. | 1440x900 @2x |
| `05-sync-log-pending.png` | `/log.php` | Real-time sync log showing 3 queued child jobs in `pending` state. | 1440x900 @2x |
| `06-dashboard-delivering.png` | `/index.php` | Active worker pass processing updates across channels. | 1440x900 @2x |
| `07-dashboard-healthy.png` | `/index.php` | All 3 channels reporting 100% success rate, healthy latency, zero rooms at risk (Full page capture). | 1440x900 @2x (Full) |
| `08-mock-site-a.png` | `/mock/site_a.php` | Site A partner server status: JSON payload inspection, received updates table, latency metric. | 1440x900 @2x |
| `09-mock-site-b.png` | `/mock/site_b.php` | Site B partner server status: XML endpoint diagnostics, HMAC signature verification panel open. | 1440x900 @2x |
| `10-mock-site-c.png` | `/mock/site_c.php` | Site C partner server status: Token authentication status, rate limit tracker, raw payload. | 1440x900 @2x |
| `11-dashboard-incident.png` | `/index.php` | Channel C outage injected: consecutive failures accumulating, channel marked DEGRADED, rooms flagged at risk. | 1440x900 @2x |
| `12-sync-log-failed.png` | `/log.php?status=failed` | Filtered sync log showing failed jobs with plain-language channel error messages. | 1440x900 @2x |
| `13-dashboard-critical.png` | `/index.php` | Exhausted retries: Site C marked DOWN, critical alert banner, dead-letter job counter incremented. | 1440x900 @2x |
| `14-sync-log-dead-details.png` | `/log.php?status=dead` | Expandable attempt history drawer open showing exponential backoff timestamps and error codes. | 1440x900 @2x |
| `15-retry-done.png` | `/log.php?status=dead` | Manual "Retry" action dispatched; dead job successfully requeued as `pending`. | 1440x900 @2x |
| `16-dashboard-recovered.png` | `/index.php` | Site C restored; queue drained; channel health recovered to GREEN; zero rooms at risk. | 1440x900 @2x |
| `17-dashboard-light.png` | `/index.php` | Clean light theme rendering (`data-theme="light"`), verifying WCAG contrast in day mode. | 1440x900 @2x |
| `18-dashboard-mobile.png` | `/index.php` | iPhone 14/15 viewport (390x844): single-column responsive layout, collapsibles, sticky worker status. | 390x844 @2x |
| `19-update-form-mobile.png` | `/update.php` | iPhone 14/15 viewport (390x844): touch-friendly date presets, unit stepper controls, sticky save bar. | 390x844 @2x |
| `20-sync-log-mobile.png` | `/log.php` | iPhone 14/15 viewport (390x844): horizontal scroll data table, sticky job ID column, compact badges. | 390x844 @2x |
| `21-dashboard-wall-mode.png` | `/index.php` | High-contrast TV wallboard mode: enlarged typography, suppressed controls, maximized readability. | 1440x900 @2x |
| `22-section-consistency.png` | `/index.php` | Channel inventory consistency audit table component: drift detection across OTAs. | Component |
| `23-section-calendar.png` | `/index.php` | 14-day interactive multi-room inventory & rate matrix component. | Component |
| `24-section-chart.png` | `/index.php` | Hourly delivery volume and success rate distribution sparkline chart component. | Component |
| `25-section-activity.png` | `/index.php` | Live operational activity feed component with correlation IDs and timestamps. | Component |
| `26-section-demo-panel.png` | `/index.php` | Chaos engineering simulation control drawer: channel outage and backoff overrides. | Component |

---

## 2. Walkthrough Animation Files

- **`failure-demo.mp4`** (302 KB): High-definition continuous H.264 MP4 recording showing the full failure lifecycle: normal dispatch $\rightarrow$ channel outage $\rightarrow$ exponential retry backoff $\rightarrow$ dead-letter transition $\rightarrow$ manual UI retry $\rightarrow$ verified recovery.
- **`failure-demo.gif`** (1.82 MB): 960px wide, 9 fps looping GIF optimized with a 128-color adaptive Bayer dither palette for embedding directly into GitHub READMEs without third-party hosts.
- **`failure-demo.webm`** (768 KB): Raw VP8 continuous capture output recorded by Playwright's headless browser engine.

---

## 3. Reproduction Pipeline

To re-capture the entire gallery and generate fresh animations from scratch, run the single-command automation pipeline:

```powershell
powershell -ExecutionPolicy Bypass -File tools/screenshots/run.ps1
```
