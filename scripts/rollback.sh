#!/bin/bash
set -euo pipefail

DEPLOY_LOG="/var/log/ethr/rollback_$(date '+%Y%m%d_%H%M%S').log"
HEALTH_URL="${HEALTH_URL:-http://localhost/api/health}"

mkdir -p /var/log/ethr

log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
  echo "$msg" | tee -a "$DEPLOY_LOG"
}

STEPS=${1:-1}
cd "$(dirname "$0")/.."
CURRENT_COMMIT=$(git rev-parse HEAD)

log "=== ETHR Rollback ==="
log "Current commit: $CURRENT_COMMIT"
log "Rolling back $STEPS step(s)"

log ">> Rolling back $STEPS migration(s)..."
cd api
php artisan migrate:rollback --step="$STEPS" --force --no-interaction 2>&1 | tee -a "$DEPLOY_LOG"

log ">> Reverting to previous commit..."
cd ..
git checkout HEAD~"$STEPS"
ROLLBACK_COMMIT=$(git rev-parse HEAD)
log "Rolled back to: $ROLLBACK_COMMIT"

log ">> Reinstalling PHP dependencies..."
cd api
composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -5 | tee -a "$DEPLOY_LOG"

log ">> Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

log ">> Rebuilding frontend..."
cd ../src
npm ci --production 2>&1 | tail -5 | tee -a "$DEPLOY_LOG"
npm run build 2>&1 | tail -10 | tee -a "$DEPLOY_LOG"

log ">> Restarting services..."
cd ../api
php artisan horizon:terminate 2>/dev/null || true
cd ..
sudo supervisorctl restart ethr-worker:*
sudo supervisorctl restart ethr-reverb
sudo nginx -t && sudo systemctl reload nginx

log ">> Post-rollback health check..."
retries=10
for i in $(seq 1 $retries); do
  status=$(curl -s -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
  if [ "$status" = "200" ]; then
    log "Health check passed (attempt $i/$retries)"
    break
  fi
  log "Health check attempt $i/$retries — HTTP $status"
  sleep 5
done

if [ "$status" != "200" ]; then
  log "WARNING: Health check failed after rollback. Manual intervention required."
fi

log "=== Rollback complete: $CURRENT_COMMIT → $ROLLBACK_COMMIT ==="
