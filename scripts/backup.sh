#!/bin/bash
set -euo pipefail

echo "=== ETHR Backup ==="

BACKUP_DIR="${BACKUP_PATH:-/var/backups/ethr}"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_NAME="ethr_backup_${TIMESTAMP}"

mkdir -p "$BACKUP_DIR"

echo ">> Backing up database..."
mysqldump -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}" \
  --single-transaction --routines --triggers \
  | gzip > "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz"

echo ">> Backing up MinIO data..."
if command -v mc &>/dev/null; then
  mc mirror ethr/uploads "${BACKUP_DIR}/${BACKUP_NAME}_files/" --quiet
else
  echo "   MinIO client (mc) not found, skipping file backup"
fi

echo ">> Backing up .env..."
cp "$(dirname "$0")/../api/.env" "${BACKUP_DIR}/${BACKUP_NAME}.env"

echo ">> Cleaning old backups (keeping 30 days)..."
find "$BACKUP_DIR" -name "ethr_backup_*" -mtime +30 -delete 2>/dev/null || true

echo "=== Backup complete: ${BACKUP_DIR}/${BACKUP_NAME} ==="
