#!/bin/bash
set -euo pipefail

BACKUP_DIR="${BACKUP_PATH:-/var/backups/ethr}"
RESTORE_LOG="/var/log/ethr/restore_$(date '+%Y%m%d_%H%M%S').log"

mkdir -p /var/log/ethr

log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
  echo "$msg" | tee -a "$RESTORE_LOG"
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

log ">> Stopping queue workers..."
cd "$(dirname "$0")/../api"
php artisan horizon:terminate 2>/dev/null || true
sleep 3

log ">> Restoring database from ${BACKUP_NAME}..."
gunzip -c "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" \
  | mysql -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" 2>>"$RESTORE_LOG"
log "Database restored"

log ">> Restoring files..."
if [ -d "${BACKUP_DIR}/${BACKUP_NAME}_files/" ] && command -v mc &>/dev/null; then
  mc mirror "${BACKUP_DIR}/${BACKUP_NAME}_files/" ethr/uploads --overwrite --quiet 2>>"$RESTORE_LOG"
  log "Files restored"
else
  log "Skipping file restore (no file backup or mc not installed)"
fi

log ">> Restoring .env..."
if [ -f "${BACKUP_DIR}/${BACKUP_NAME}.env" ]; then
  cp "${BACKUP_DIR}/${BACKUP_NAME}.env" "$(dirname "$0")/../api/.env"
  log ".env restored"
fi

log ">> Clearing caches..."
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

log ">> Restarting services..."
cd ..
sudo supervisorctl restart ethr-worker:*
sudo supervisorctl restart ethr-reverb

log "=== Restore complete ==="
