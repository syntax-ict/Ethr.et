#!/bin/bash
set -euo pipefail

# ┌──────────────────────────────────────────────────────────────────────────┐
# │ RETIRED 2026-09-27. This script cannot run, and it is not a restore path.│
# └──────────────────────────────────────────────────────────────────────────┘
#
# See `scripts/backup.sh` for the full reasoning; this is its other half and the
# same three facts apply. In short: it drove `docker-compose.prod.yml`, removed in
# `3db9904`; it was held on the false grounds that it was "a working path"; and it
# refuses rather than being deleted because the Stage 6 host restore rehearsal
# that authorises the deletion has not happened.
#
# This one is the more clearly unrepointable of the two. It stops
# `worker-realtime`, `worker-notifications` and `worker-heavy` — the dev stack in
# `docker-compose.yml` has a single `worker` — and restores files through a
# container named `ethr-minio`, which no compose file in this repository creates.
#
# Use `php artisan ethr:restore`. Note what that does and does not have behind it:
# it is rehearsed in CI against MariaDB (`backup-rehearsal-mysql` in
# `.github/workflows/gates.yml`) and has NEVER been rehearsed on the Ethio Telecom
# host. `docs/audit/BASELINE.md` §15f/§15g record that this path already carried a
# defect which made a dump replayable only on a permissive engine, while every
# test of it was green — so rehearse a full restore on the host before cutover,
# not after.

cat >&2 <<'RETIRED'
scripts/restore.sh is RETIRED and does nothing.

  It drove docker-compose.prod.yml, removed in 3db9904 (2026-09-27).
  It is NOT a VPS rollback path — there is no longer anything to roll back to.

Use instead:
  production        php artisan ethr:restore      (Stage 6: rehearse on the HOST
                                                   before cutover — CI has only
                                                   ever rehearsed on MariaDB)
  local dev         cd api && php artisan ethr:restore

See docs/deployment/BACKUP-RESTORE.md and docs/deployment/VPS-DECOMMISSION.md.
RETIRED
exit 64

# ─── unreachable below this line: the VPS implementation, kept as a record ───

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
