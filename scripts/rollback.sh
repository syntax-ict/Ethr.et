#!/bin/bash
set -euo pipefail

# Override with COMPOSE_FILE=docker-compose.lowmem.yml on the 4 GB tier —
# see that file's header for what it changes vs. this default.
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
DEPLOY_LOG="/var/log/ethr/rollback_$(date '+%Y%m%d_%H%M%S').log"
# https, not http — see the same note in deploy.sh. Over http the :80 block
# answers 301 and this check never observes a 200, so a rollback that had
# actually restored service still reported itself failed.
HEALTH_URL="${HEALTH_URL:-https://localhost/api/v1/health}"

mkdir -p /var/log/ethr

log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
  echo "$msg" | tee -a "$DEPLOY_LOG"
}

dc() {
  docker compose -f "$COMPOSE_FILE" "$@"
}

STEPS=${1:-1}
cd "$(dirname "$0")/.."
CURRENT_COMMIT=$(git rev-parse HEAD)

log "=== ETHR Rollback ==="
log "Current commit: $CURRENT_COMMIT"
log "Rolling back $STEPS step(s)"

log ">> Rolling back $STEPS migration(s)..."
dc run --rm api php artisan migrate:rollback --step="$STEPS" --force --no-interaction 2>&1 | tee -a "$DEPLOY_LOG"

log ">> Reverting to previous commit..."
git checkout HEAD~"$STEPS"
ROLLBACK_COMMIT=$(git rev-parse HEAD)
log "Rolled back to: $ROLLBACK_COMMIT"

log ">> Rebuilding images..."
dc build --pull 2>&1 | tail -30 | tee -a "$DEPLOY_LOG"

log ">> Caching configuration..."
dc run --rm api php artisan config:cache
dc run --rm api php artisan route:cache
dc run --rm api php artisan view:cache
dc run --rm api php artisan event:cache

log ">> Recreating containers..."
dc up -d --remove-orphans 2>&1 | tee -a "$DEPLOY_LOG"

log ">> Restarting Nginx (refreshes cached upstream container IPs)..."
dc restart nginx

log ">> Post-rollback health check..."
retries=10
status="000"
for i in $(seq 1 $retries); do
  # -k: loopback request against a cert issued for ethr.et/*.ethr.et.
  status=$(curl -sk -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
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
