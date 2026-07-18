#!/bin/bash
set -euo pipefail

DEPLOY_LOG="/var/log/ethr/deploy_$(date '+%Y%m%d_%H%M%S').log"
HEALTH_URL="${HEALTH_URL:-http://localhost/api/health}"
NOTIFY_EMAIL="${DEPLOY_NOTIFY_EMAIL:-}"
ROLLBACK_ON_FAIL="${ROLLBACK_ON_FAIL:-false}"

mkdir -p /var/log/ethr

log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
  echo "$msg" | tee -a "$DEPLOY_LOG"
}

notify_failure() {
  local step="$1"
  log "DEPLOY FAILED at step: $step"
  if [ -n "$NOTIFY_EMAIL" ] && command -v mail &>/dev/null; then
    echo "Deployment failed at: $step. See $DEPLOY_LOG" | mail -s "ETHR Deploy FAILED" "$NOTIFY_EMAIL"
  fi
  if [ "$ROLLBACK_ON_FAIL" = "true" ]; then
    log "Auto-rollback enabled — reverting..."
    bash "$(dirname "$0")/rollback.sh" 1
  fi
  exit 1
}

health_check() {
  local retries=10
  local wait=5
  log "Running health check against $HEALTH_URL..."
  for i in $(seq 1 $retries); do
    status=$(curl -s -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
    if [ "$status" = "200" ]; then
      log "Health check passed (attempt $i/$retries)"
      return 0
    fi
    log "Health check attempt $i/$retries — HTTP $status, retrying in ${wait}s..."
    sleep $wait
  done
  log "Health check failed after $retries attempts"
  return 1
}

cd "$(dirname "$0")/.."
PREV_COMMIT=$(git rev-parse HEAD)

log "=== ETHR Deployment ==="
log "Starting from commit: $PREV_COMMIT"

log ">> Pulling latest code..."
git pull origin main || notify_failure "git pull"
NEW_COMMIT=$(git rev-parse HEAD)
log "Updated to commit: $NEW_COMMIT"

log ">> Installing PHP dependencies..."
cd api
composer install --no-dev --optimize-autoloader --no-interaction 2>&1 | tail -5 | tee -a "$DEPLOY_LOG" || notify_failure "composer install"

log ">> Running migrations..."
php artisan migrate --force --no-interaction 2>&1 | tee -a "$DEPLOY_LOG" || notify_failure "migrate"

log ">> Caching configuration..."
php artisan config:cache || notify_failure "config:cache"
php artisan route:cache || notify_failure "route:cache"
php artisan view:cache || notify_failure "view:cache"
php artisan event:cache || notify_failure "event:cache"

log ">> Installing frontend dependencies..."
cd ../src
npm ci --production 2>&1 | tail -5 | tee -a "$DEPLOY_LOG" || notify_failure "npm ci"

log ">> Building frontend..."
npm run build 2>&1 | tail -10 | tee -a "$DEPLOY_LOG" || notify_failure "npm build"

log ">> Restarting queue workers..."
cd ../api
php artisan horizon:terminate 2>/dev/null || true
cd ..
sudo supervisorctl restart ethr-worker:* 2>&1 | tee -a "$DEPLOY_LOG" || notify_failure "restart workers"

log ">> Restarting Reverb..."
sudo supervisorctl restart ethr-reverb 2>&1 | tee -a "$DEPLOY_LOG" || notify_failure "restart reverb"

log ">> Reloading Nginx..."
sudo nginx -t 2>&1 | tee -a "$DEPLOY_LOG" || notify_failure "nginx config test"
sudo systemctl reload nginx || notify_failure "nginx reload"

log ">> Post-deploy health check..."
health_check || notify_failure "health check"

log "=== Deployment complete ==="
log "Deployed $PREV_COMMIT → $NEW_COMMIT"
