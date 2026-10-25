# SyncGuard Fast Browser Smoke Test Runner
# Executes automated headless Chromium tests inside Playwright Docker container

$ErrorActionPreference = "Stop"

Write-Host ">>> Preparing test environment..." -ForegroundColor Cyan
docker compose exec -T web php bin/reset_queue.php --yes
docker compose exec -T web php bin/mock_control.php reset
docker compose exec -T web php bin/seed_test_updates.php
docker compose exec -T web php bin/worker.php --once

Write-Host ">>> Running Browser Smoke Test via Docker Playwright..." -ForegroundColor Cyan
$repoRoot = (Resolve-Path "$PSScriptRoot\..\..").Path
$e2ePath = "$repoRoot\tools\e2e"

$exitCode = 0
try {
    docker run --rm --network syncguard_default -v "${e2ePath}:/e2e" -w /e2e mcr.microsoft.com/playwright:v1.49.0-noble node smoke.mjs
    $exitCode = $LASTEXITCODE
} catch {
    Write-Error "Smoke test runner failed: $_"
    $exitCode = 1
}

Write-Host ">>> Cleaning up test queue and resetting mocks..." -ForegroundColor Cyan
docker compose exec -T web php bin/reset_queue.php --yes
docker compose exec -T web php bin/mock_control.php reset
docker compose exec -T web php bin/rebuild_health.php

if ($exitCode -ne 0) {
    Write-Host ">>> Smoke tests FAILED with exit code $exitCode" -ForegroundColor Red
    exit $exitCode
} else {
    Write-Host ">>> Smoke tests PASSED successfully." -ForegroundColor Green
    exit 0
}
