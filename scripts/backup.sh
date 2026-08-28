#!/bin/bash
set -euo pipefail

# Every artefact this script writes contains production secrets: the SQL dump
# holds the full dataset, and the env copy holds the DB root password, Redis
# password, MinIO keys, SMTP credentials and the Reverb app secret. Default
# 0644/0755 permissions would leave all of that readable by any local account,
# so restrict the mode of everything created from here on.
umask 077

# Override with COMPOSE_FILE=docker-compose.lowmem.yml on the 4 GB tier —
# see that file's header for what it changes vs. this default.
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
BACKUP_DIR="${BACKUP_PATH:-/var/backups/ethr}"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_NAME="ethr_backup_${TIMESTAMP}"
BACKUP_LOG="/var/log/ethr/backup_${TIMESTAMP}.log"
NOTIFY_EMAIL="${BACKUP_NOTIFY_EMAIL:-}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"

mkdir -p "$BACKUP_DIR" /var/log/ethr
# umask only constrains newly created paths — tighten a directory that an
# earlier run (or an operator) may already have created world-readable.
chmod 700 "$BACKUP_DIR"

log() {
  local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
  echo "$msg" | tee -a "$BACKUP_LOG"
}

dc() {
  docker compose -f "$COMPOSE_FILE" "$@"
}

notify_failure() {
  local step="$1"
  log "BACKUP FAILED at step: $step"
  if [ -n "$NOTIFY_EMAIL" ] && command -v mail &>/dev/null; then
    echo "Backup failed at: $step. See $BACKUP_LOG" | mail -s "ETHR Backup FAILED" "$NOTIFY_EMAIL"
  fi
  exit 1
}

cd "$(dirname "$0")/.."

log "=== ETHR Backup ==="

log ">> Backing up database..."
# Credentials come from the mariadb container's own environment
# (MARIADB_USER/MARIADB_PASSWORD/MARIADB_DATABASE, set from the root .env
# by docker-compose.prod.yml) — single-quoted so they expand inside the
# container, not on the host, so this script never needs DB secrets itself.
dc exec -T mariadb sh -c 'exec mysqldump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' \
  2>>"$BACKUP_LOG" | gzip > "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" || notify_failure "database dump"

DB_SIZE=$(du -h "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" | cut -f1)
log "Database backup: ${DB_SIZE}"

log ">> Backing up MinIO data..."
# --volumes-from mounts the same volume the ethr-minio container uses,
# without needing to know Compose's generated volume name (which varies
# by project name). No mc/credentials needed.
docker run --rm --volumes-from ethr-minio -v "${BACKUP_DIR}:/backup" alpine \
  tar czf "/backup/${BACKUP_NAME}_files.tar.gz" -C /data . \
  2>>"$BACKUP_LOG" || notify_failure "minio volume backup"
FILES_SIZE=$(du -h "${BACKUP_DIR}/${BACKUP_NAME}_files.tar.gz" 2>/dev/null | cut -f1)
log "File backup: ${FILES_SIZE}"

log ">> Backing up production env file..."
cp "api/.env.production" "${BACKUP_DIR}/${BACKUP_NAME}.env" || notify_failure "env backup"
# cp preserves nothing useful here: be explicit rather than relying on umask,
# since this single file is the most secret-dense artefact in the backup set.
chmod 600 "${BACKUP_DIR}/${BACKUP_NAME}.env"

log ">> Verifying backup integrity..."
if ! gunzip -t "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" 2>/dev/null; then
  notify_failure "backup verification (corrupt gzip)"
fi
log "Backup integrity verified"

log ">> Cleaning old backups (keeping ${RETENTION_DAYS} days)..."
deleted=$(find "$BACKUP_DIR" -name "ethr_backup_*" -mtime +"$RETENTION_DAYS" -delete -print 2>/dev/null | wc -l)
log "Removed $deleted old backup file(s)"

log "=== Backup complete: ${BACKUP_DIR}/${BACKUP_NAME} ==="
