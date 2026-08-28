#!/bin/bash
set -euo pipefail

# Override with COMPOSE_FILE=docker-compose.lowmem.yml on the 4 GB tier —
# see that file's header for what it changes vs. this default.
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
BACKUP_DIR="${BACKUP_PATH:-/var/backups/ethr}"
RESTORE_LOG="/var/log/ethr/restore_$(date '+%Y%m%d_%H%M%S').log"

mkdir -p /var/log/ethr

log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
  echo "$msg" | tee -a "$RESTORE_LOG"
}

dc() {
  docker compose -f "$COMPOSE_FILE" "$@"
}

if [ -z "${1:-}" ]; then
  echo "Usage: ./restore.sh <backup_name>"
  echo "Example: ./restore.sh ethr_backup_20260628_120000"
  echo ""
  echo "Available backups:"
  ls -1t "$BACKUP_DIR"/ethr_backup_*_db.sql.gz 2>/dev/null | head -10 | sed 's|.*/||; s|_db.sql.gz||'
  exit 1
fi

BACKUP_NAME="$1"
cd "$(dirname "$0")/.."

log "=== ETHR Restore ==="
log "Restoring from: $BACKUP_NAME"

if [ ! -f "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" ]; then
  log "ERROR: Backup file not found: ${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz"
  exit 1
fi

log ">> Verifying backup integrity..."
if ! gunzip -t "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" 2>/dev/null; then
  log "ERROR: Backup file is corrupt"
  exit 1
fi
log "Backup integrity verified"

log ">> Stopping queue workers and scheduler..."
dc stop worker-realtime worker-notifications worker-heavy scheduler 2>&1 | tee -a "$RESTORE_LOG"

log ">> Restoring database from ${BACKUP_NAME}..."
gunzip -c "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" \
  | dc exec -T mariadb sh -c 'exec mysql -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' 2>>"$RESTORE_LOG"
log "Database restored"

log ">> Restoring files..."
if [ -f "${BACKUP_DIR}/${BACKUP_NAME}_files.tar.gz" ]; then
  docker run --rm --volumes-from ethr-minio -v "${BACKUP_DIR}:/backup" alpine \
    sh -c "rm -rf /data/* && tar xzf /backup/${BACKUP_NAME}_files.tar.gz -C /data" 2>>"$RESTORE_LOG"
  log "Files restored"
else
  log "Skipping file restore (no file backup found: ${BACKUP_NAME}_files.tar.gz)"
fi

log ">> Restoring production env file..."
if [ -f "${BACKUP_DIR}/${BACKUP_NAME}.env" ]; then
  cp "${BACKUP_DIR}/${BACKUP_NAME}.env" "api/.env.production"
  log "api/.env.production restored"
fi

log ">> Clearing and rebuilding caches..."
dc run --rm api php artisan cache:clear
dc run --rm api php artisan config:cache
dc run --rm api php artisan route:cache
dc run --rm api php artisan view:cache

log ">> Restarting services..."
dc restart api worker-realtime worker-notifications worker-heavy scheduler reverb

log "=== Restore complete ==="
