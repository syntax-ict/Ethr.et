#!/usr/bin/env bash
set -euo pipefail

# Creates the MinIO bucket the application writes to.
#
# Nothing else in the deploy does this. MinIO does not create buckets on demand,
# so on a fresh stack every Storage:: call failed and GET /api/v1/health reported
# "storage":"unavailable". That never blocked a deploy, because HealthController
# only counts a service as failing when it reports "unhealthy" — "unavailable"
# still returns HTTP 200, so scripts/deploy.sh's health gate passed a stack whose
# file storage did not work. Payslip PDFs, document uploads and tenant backups
# would all fail at the first write.
#
# Idempotent: `mc mb -p` is a no-op if the bucket already exists, so this is safe
# to run on every deploy.
#
# Usage: ./scripts/init-storage.sh
# Prerequisites: the stack is running and minio is healthy; the root .env
# supplies MINIO_ACCESS_KEY / MINIO_SECRET_KEY.

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
BUCKET="${MINIO_BUCKET:-ethr}"

if [ -z "${MINIO_ACCESS_KEY:-}" ] || [ -z "${MINIO_SECRET_KEY:-}" ]; then
  # Not exported in the caller's shell — read them out of the root .env that
  # docker-compose.prod.yml already interpolates from.
  # shellcheck disable=SC1091
  set -a; . "$(dirname "$0")/../.env"; set +a
fi

echo "=== MinIO bucket setup ==="
echo "Bucket: ${BUCKET}"

docker compose -f "$COMPOSE_FILE" exec -T minio sh -c "
  mc alias set local http://127.0.0.1:9000 '${MINIO_ACCESS_KEY}' '${MINIO_SECRET_KEY}' >/dev/null
  mc mb -p local/${BUCKET}
  mc ls local
"

echo "=== Storage ready ==="
