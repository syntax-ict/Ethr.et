#!/bin/bash
set -euo pipefail

# Override with COMPOSE_FILE=docker-compose.lowmem.yml on the 4 GB tier —
# see that file's header for what it changes vs. this default.
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
DEPLOY_LOG="/var/log/ethr/deploy_$(date '+%Y%m%d_%H%M%S').log"
# https, not http: the :80 server block redirects to https, and the check below
# reads the status code of the *first* response, so an http URL scored a
# permanent 301 and never a 200 — failing every deploy that had in fact
# succeeded (and, with ROLLBACK_ON_FAIL=true, rolling back a healthy release).
HEALTH_URL="${HEALTH_URL:-https://localhost/api/v1/health}"
NOTIFY_EMAIL="${DEPLOY_NOTIFY_EMAIL:-}"
ROLLBACK_ON_FAIL="${ROLLBACK_ON_FAIL:-false}"

mkdir -p /var/log/ethr

log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
  echo "$msg" | tee -a "$DEPLOY_LOG"
}

dc() {
  docker compose -f "$COMPOSE_FILE" "$@"
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
    # -k: the certificate is issued for ethr.et/*.ethr.et, so a request to
    # `localhost` fails hostname verification. This call is loopback-only and
    # is testing that nginx -> php-fpm -> Laravel answers, not the chain of
    # trust; TLS validity is certbot's job (see docs/DEPLOYMENT.md).
    status=$(curl -sk -o /dev/null -w "%{http_code}" "$HEALTH_URL" 2>/dev/null || echo "000")
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

log ">> Building images (api, worker-*, scheduler, reverb, frontend)..."
dc build --pull 2>&1 | tail -30 | tee -a "$DEPLOY_LOG" || notify_failure "docker compose build"

# Caching must precede migrate. These `run --rm` containers mount the same
# api_cache volume as the long-running services, and they carry the real
# environment from env_file, so this is the step that turns the image's
# unconfigured state into a working one. Running migrate first meant it
# executed against whatever config.php happened to be in the volume — on a
# first deploy, the build-time defaults (DB_HOST 127.0.0.1, empty password),
# which fails to reach the mariadb service and aborts the deploy.
log ">> Caching configuration..."
dc run --rm api php artisan config:cache || notify_failure "config:cache"
dc run --rm api php artisan route:cache || notify_failure "route:cache"
dc run --rm api php artisan view:cache || notify_failure "view:cache"
dc run --rm api php artisan event:cache || notify_failure "event:cache"

log ">> Running migrations..."
dc run --rm api php artisan migrate --force --no-interaction 2>&1 | tee -a "$DEPLOY_LOG" || notify_failure "migrate"

log ">> Recreating containers with new images..."
dc up -d --remove-orphans 2>&1 | tee -a "$DEPLOY_LOG" || notify_failure "docker compose up"

log ">> Restarting Nginx (refreshes cached upstream container IPs)..."
dc restart nginx 2>&1 | tee -a "$DEPLOY_LOG" || notify_failure "restart nginx"

log ">> Post-deploy health check..."
health_check || notify_failure "health check"

log "=== Deployment complete ==="
log "Deployed $PREV_COMMIT → $NEW_COMMIT"
