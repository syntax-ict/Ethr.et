#!/bin/bash
set -euo pipefail

BACKUP_DIR="${BACKUP_PATH:-/var/backups/ethr}"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_NAME="ethr_backup_${TIMESTAMP}"
BACKUP_LOG="/var/log/ethr/backup_${TIMESTAMP}.log"
NOTIFY_EMAIL="${BACKUP_NOTIFY_EMAIL:-}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

mkdir -p "$BACKUP_DIR" /var/log/ethr

log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
  echo "$msg" | tee -a "$BACKUP_LOG"
}

notify_failure() {
  local step="$1"
  log "BACKUP FAILED at step: $step"
  if [ -n "$NOTIFY_EMAIL" ] && command -v mail &>/dev/null; then
    echo "Backup failed at: $step. See $BACKUP_LOG" | mail -s "ETHR Backup FAILED" "$NOTIFY_EMAIL"
  fi
  exit 1
}

log "=== ETHR Backup ==="

log ">> Backing up database..."
mysqldump -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" \
  --single-transaction --routines --triggers \
  2>>"$BACKUP_LOG" \
  | gzip > "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" || notify_failure "database dump"

DB_SIZE=$(du -h "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" | cut -f1)
log "Database backup: ${DB_SIZE}"

log ">> Backing up MinIO data..."
if command -v mc &>/dev/null; then
  mc mirror ethr/uploads "${BACKUP_DIR}/${BACKUP_NAME}_files/" --quiet 2>>"$BACKUP_LOG" || notify_failure "minio mirror"
  FILES_SIZE=$(du -sh "${BACKUP_DIR}/${BACKUP_NAME}_files/" 2>/dev/null | cut -f1)
  log "File backup: ${FILES_SIZE}"
else
  log "WARNING: MinIO client (mc) not found, skipping file backup"
fi

log ">> Backing up .env..."
cp "$(dirname "$0")/../api/.env" "${BACKUP_DIR}/${BACKUP_NAME}.env" || notify_failure "env backup"

log ">> Verifying backup integrity..."
if ! gunzip -t "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" 2>/dev/null; then
  notify_failure "backup verification (corrupt gzip)"
fi
log "Backup integrity verified"

log ">> Cleaning old backups (keeping ${RETENTION_DAYS} days)..."
deleted=$(find "$BACKUP_DIR" -name "ethr_backup_*" -mtime +"$RETENTION_DAYS" -delete -print 2>/dev/null | wc -l)
log "Removed $deleted old backup file(s)"

log "=== Backup complete: ${BACKUP_DIR}/${BACKUP_NAME} ==="
