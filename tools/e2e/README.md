# SyncGuard End-to-End Browser Smoke Test

Fast, automated browser smoke test suite verifying real browser JavaScript execution, asset loading, form interactions, live table rendering, status tab filtering, and failure drills.

## Running the Smoke Test

### Prerequisites
- Docker engine running with the `syncguard_default` network active.
- Playwright Docker image: `mcr.microsoft.com/playwright:v1.49.0-noble`.

### Quick Run (PowerShell)
```powershell
powershell -ExecutionPolicy Bypass -File .\tools\e2e\run.ps1
```

### Manual Docker Command
```powershell
docker run --rm --network syncguard_default -v "${PWD}\tools\e2e:/e2e" -w /e2e mcr.microsoft.com/playwright:v1.49.0-noble node smoke.mjs
```

## What Is Tested
1. **Asset & Console Hygiene**: Visits `/`, `/update.php`, `/log.php`. Asserts 0 console errors, 0 uncaught exceptions, 0 failed HTTP requests (no 404s like missing jQuery), and valid jQuery version.
2. **Theme & Wall Mode**: Verifies dark/light theme toggle, DOM attribute reflection (`data-theme`), and `localStorage` persistence across page reloads. Verifies operational Wall mode body toggle.
3. **Form Controls & IME Digits Normalization**: Selects room and verifies capacity text; tests date presets and live summary calculation; tests units steppers and bounds ("Sold out (0)", "All rooms (N)"); enters full-width Japanese IME digits (`１２０００`) and verifies normalization to `12,000` on blur.
4. **Form Submission & Idempotency**: Empty form submission validates client-side without page navigation; valid form submits via AJAX (preventing raw JSON display), displays the success card, and maintains UUID idempotency keys.
5. **Log Table & Status Tabs**: Verifies jobs table loads rows within 5 seconds; status tabs filter by status and sync with URL query parameters; filter reset works; expandable Details row reveals attempt telemetry.
6. **Failure & Error Drill**: Intercepts `/api/jobs.php` with 500 error; verifies error state card displays with Retry button instead of remaining permanently stuck on "Loading".
