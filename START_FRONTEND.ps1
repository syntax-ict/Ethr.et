# ETHR Frontend Server (Next.js on 3000)
# =========================================

Write-Host "ETHR Frontend Starting..." -ForegroundColor Green
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Green

Set-Location "$PSScriptRoot\src"

Write-Host "📂 Working directory: $(Get-Location)" -ForegroundColor Cyan
Write-Host ""

# Check .env.local exists
if (-not (Test-Path ".env.local")) {
    Write-Host "⚠️  .env.local not found, creating from defaults..." -ForegroundColor Yellow
    @"
NEXT_PUBLIC_API_URL=http://localhost:8000/api
NEXT_PUBLIC_REVERB_HOST=localhost
NEXT_PUBLIC_REVERB_PORT=8080
NEXT_PUBLIC_REVERB_SCHEME=http
NEXT_PUBLIC_REVERB_APP_KEY=ethr-reverb-key
"@ | Out-File -Encoding UTF8 ".env.local"
    Write-Host "✓ Created .env.local" -ForegroundColor Green
}

Write-Host ""
Write-Host "🚀 Starting Next.js development server on http://localhost:3000" -ForegroundColor Yellow
Write-Host "📝 Logs will appear below..." -ForegroundColor Yellow
Write-Host ""

& npm run dev

Write-Host ""
Write-Host "⛔ Frontend server stopped." -ForegroundColor Red
Read-Host "Press Enter to exit"
