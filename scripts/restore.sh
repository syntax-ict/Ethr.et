#!/bin/bash
set -euo pipefail

echo "=== ETHR Restore ==="

if [ -z "${1:-}" ]; then
  echo "Usage: ./restore.sh <backup_name>"
  echo "Example: ./restore.sh ethr_backup_20260628_120000"
  exit 1
fi

BACKUP_DIR="${BACKUP_PATH:-/var/backups/ethr}"
BACKUP_NAME="$1"

echo ">> Restoring database from ${BACKUP_NAME}..."
gunzip -c "${BACKUP_DIR}/${BACKUP_NAME}_db.sql.gz" \
  | mysql -u "${DB_USERNAME}" -p"${DB_PASSWORD}" "${DB_DATABASE}"

echo ">> Restoring files..."
if [ -d "${BACKUP_DIR}/${BACKUP_NAME}_files/" ] && command -v mc &>/dev/null; then
  mc mirror "${BACKUP_DIR}/${BACKUP_NAME}_files/" ethr/uploads --overwrite --quiet
fi

echo ">> Clearing caches..."
cd "$(dirname "$0")/../api"
php artisan cache:clear
php artisan config:cache

echo "=== Restore complete ==="
