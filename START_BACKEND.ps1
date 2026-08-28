# ETHR Backend Server (Laravel 8000)
# =====================================

Write-Host "ETHR Backend Starting..." -ForegroundColor Green
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Green

Set-Location "$PSScriptRoot\api"

Write-Host "📂 Working directory: $(Get-Location)" -ForegroundColor Cyan
Write-Host ""

# Ensure APP_KEY is set
if (-not $env:APP_KEY) {
    $env:APP_KEY = "base64:yDHUyiFUU0HVf6S09PXpkWctHSBxk2PXFPR4phVlgt0="
}

# Start Laravel dev server
Write-Host "🚀 Starting Laravel development server on http://localhost:8000" -ForegroundColor Yellow
Write-Host "📝 Logs will appear below..." -ForegroundColor Yellow
Write-Host ""

& php artisan serve --host=0.0.0.0 --port=8000

Write-Host ""
Write-Host "⛔ Backend server stopped." -ForegroundColor Red
Read-Host "Press Enter to exit"
