# ETHR — Local Development Launcher
# Starts both backend and frontend servers in separate windows
# ============================================================

Write-Host "╔════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║  ETHR Local Development Environment  ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

$projectRoot = $PSScriptRoot

# Start Backend (Laravel on :8000)
Write-Host "🚀 Starting Backend Server (Laravel :8000)..." -ForegroundColor Green
Start-Process powershell.exe -ArgumentList "-NoExit", "-Command", "cd '$projectRoot'; .\START_BACKEND.ps1"
Start-Sleep -Seconds 2

# Start Frontend (Next.js on :3000)
Write-Host "🚀 Starting Frontend Server (Next.js :3000)..." -ForegroundColor Green
Start-Process powershell.exe -ArgumentList "-NoExit", "-Command", "cd '$projectRoot'; .\START_FRONTEND.ps1"
Start-Sleep -Seconds 2

Write-Host ""
Write-Host "╔════════════════════════════════════════╗" -ForegroundColor Green
Write-Host "║           ✅ Servers Started           ║" -ForegroundColor Green
Write-Host "╚════════════════════════════════════════╝" -ForegroundColor Green
Write-Host ""
Write-Host "📱 Frontend:  http://localhost:3000" -ForegroundColor Yellow
Write-Host "🔧 Backend:   http://localhost:8000" -ForegroundColor Yellow
Write-Host "💾 Database:  SQLite (api/database/database.sqlite)" -ForegroundColor Yellow
Write-Host ""
Write-Host "✋ Keep this window open. Close it to see server status summaries." -ForegroundColor Cyan
Write-Host ""
Write-Host "Press Ctrl+C to close this launcher, or close backend/frontend windows individually." -ForegroundColor Gray
Write-Host ""

# Keep launcher window open
while ($true) {
    Start-Sleep -Seconds 10
}
