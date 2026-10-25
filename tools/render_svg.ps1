# Render SVG diagrams to PNG using Playwright in Docker
$ErrorActionPreference = "Stop"
$repoRoot = (Resolve-Path "$PSScriptRoot\..").Path
Write-Host "Rendering architecture and job lifecycle diagrams via Docker Playwright..." -ForegroundColor Cyan
docker run --rm -v "${repoRoot}:/repo" -w /repo/tools/e2e mcr.microsoft.com/playwright:v1.49.0-noble node render_svg.mjs
if ($LASTEXITCODE -ne 0) {
    Write-Error "SVG rendering failed with exit code $LASTEXITCODE"
    exit $LASTEXITCODE
}
Write-Host "Diagrams rendered successfully." -ForegroundColor Green
