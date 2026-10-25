# SyncGuard Screenshot & Animation Automation Runner
# Budget safety rule: 360s timeout

$ErrorActionPreference = "Stop"
$repoRoot = (Resolve-Path "$PSScriptRoot\..\..").Path
$screenshotsDir = "$repoRoot\docs\screenshots"

Write-Host "================================================================" -ForegroundColor Cyan
Write-Host " SyncGuard Story Gallery & Demo Animation Capture Pipeline" -ForegroundColor Cyan
Write-Host " Running on Playwright & FFmpeg Docker Containers" -ForegroundColor Cyan
Write-Host "================================================================" -ForegroundColor Cyan

# 1. Reset state before capture
Write-Host ">>> Resetting queue and mock channels to clean state..." -ForegroundColor Cyan
docker compose exec -T web php bin/reset_queue.php --yes
docker compose exec -T web php bin/mock_control.php reset
docker compose exec -T web php bin/rebuild_health.php

# 2. Run Playwright capture inside container
Write-Host ">>> Running Playwright capture.mjs..." -ForegroundColor Cyan
docker run --rm --network syncguard_default -v "${repoRoot}:/repo" -v "${repoRoot}\tools\e2e\node_modules:/repo/tools/screenshots/node_modules" -w /repo/tools/screenshots mcr.microsoft.com/playwright:v1.49.0-noble node capture.mjs

if ($LASTEXITCODE -ne 0) {
    Write-Error "Playwright capture script failed with exit code $LASTEXITCODE"
    exit $LASTEXITCODE
}

# 3. Convert video to GIF and MP4 via FFmpeg container
$webmFile = "$screenshotsDir\failure-demo.webm"
if (Test-Path $webmFile) {
    Write-Host ">>> Converting failure-demo.webm to failure-demo.mp4 and failure-demo.gif via jrottenberg/ffmpeg..." -ForegroundColor Cyan
    
    # Generate MP4 (H.264, no audio, faststart)
    docker run --rm -v "${screenshotsDir}:/media" -w /media jrottenberg/ffmpeg:latest -i failure-demo.webm -c:v libx264 -pix_fmt yuv420p -an -movflags +faststart -y failure-demo.mp4

    # Generate optimized GIF (960px width, 9 fps, 128 colors palette, loop forever)
    docker run --rm -v "${screenshotsDir}:/media" -w /media jrottenberg/ffmpeg:latest -i failure-demo.webm -vf "fps=9,scale=960:-1:flags=lanczos,split[s0][s1];[s0]palettegen=max_colors=128:reserve_transparent=0[p];[s1][p]paletteuse=dither=bayer:bayer_scale=3" -loop 0 -y failure-demo.gif
    
    Write-Host ">>> Video conversion complete." -ForegroundColor Green
} else {
    Write-Warning "failure-demo.webm was not found; skipping video conversion."
}

# 4. Clean up temporary video files
$videoTmp = "$screenshotsDir\video_tmp"
if (Test-Path $videoTmp) {
    Remove-Item -Recurse -Force $videoTmp
}

# 5. Reset to clean state after run
Write-Host ">>> Restoring clean data state..." -ForegroundColor Cyan
docker compose exec -T web php bin/reset_queue.php --yes
docker compose exec -T web php bin/mock_control.php reset
docker compose exec -T web php bin/rebuild_health.php

Write-Host ">>> Capture run completed successfully." -ForegroundColor Green
