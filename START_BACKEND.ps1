# ETHR Backend Server (Laravel 8000)
# =====================================

Write-Host "ETHR Backend Starting..." -ForegroundColor Green
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Green

Set-Location "$PSScriptRoot\api"

Write-Host "📂 Working directory: $(Get-Location)" -ForegroundColor Cyan
Write-Host ""

# Ensure APP_KEY is set.
#
# This used to hardcode a literal key, which meant a committed APP_KEY: it
# signs every signed URL and decrypts every `encrypted` cast, so anyone with
# repository access held it. It was only ever a local-development convenience,
# but nothing in the file said so, and any developer without APP_KEY set
# silently adopted it.
#
# Generate one instead. `key:generate` writes to api/.env, which is gitignored.
if (-not $env:APP_KEY) {
    if (-not (Test-Path ".env")) {
        Write-Host "No api/.env found - copying .env.example" -ForegroundColor Yellow
        Copy-Item ".env.example" ".env"
    }

    if (-not (Select-String -Path ".env" -Pattern "^APP_KEY=base64:" -Quiet)) {
        Write-Host "Generating a local APP_KEY..." -ForegroundColor Yellow
        & php artisan key:generate --ansi
    }
}

# Start Laravel dev server
Write-Host "🚀 Starting Laravel development server on http://localhost:8000" -ForegroundColor Yellow
Write-Host "📝 Logs will appear below..." -ForegroundColor Yellow
Write-Host ""

& php artisan serve --host=0.0.0.0 --port=8000

Write-Host ""
Write-Host "⛔ Backend server stopped." -ForegroundColor Red
Read-Host "Press Enter to exit"
