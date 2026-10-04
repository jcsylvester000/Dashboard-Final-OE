# OverEasy Dashboard - start everything for local development (Windows PowerShell).
# Usage (from this folder):   .\start-local.ps1
# If PowerShell blocks scripts: powershell -ExecutionPolicy Bypass -File .\start-local.ps1

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

Write-Host '1/4 Starting PostgreSQL + Valkey (Docker)...' -ForegroundColor Cyan
docker compose up -d

Write-Host '2/4 Waiting for PostgreSQL to accept connections...' -ForegroundColor Cyan
$ErrorActionPreference = 'Continue'   # pg_isready writes to stderr while starting
$ready = $false
for ($i = 0; $i -lt 30; $i++) {
    docker compose exec -T postgres pg_isready -q 2>$null
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Seconds 1
}
$ErrorActionPreference = 'Stop'
if (-not $ready) { throw 'PostgreSQL did not become ready. Run: docker compose logs postgres' }

Write-Host '3/4 Installing any new dependencies and running migrations...' -ForegroundColor Cyan
composer install --no-interaction
npm install --no-audit --no-fund
php artisan migrate --force

Write-Host '4/4 Starting the app (server + queue + Vite). Press Ctrl+C to stop.' -ForegroundColor Green
Write-Host '    Open http://localhost:8000' -ForegroundColor Green
composer dev
