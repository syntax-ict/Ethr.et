#!/usr/bin/env bash
set -euo pipefail

# E2E test runner — brings up the full stack, seeds test data, runs Playwright,
# then tears everything down. Intended for CI or local pre-merge validation.
#
# Usage:
#   ./scripts/run-e2e.sh              # run all E2E tests
#   ./scripts/run-e2e.sh auth.spec.ts # run a single spec

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Runs under its own compose project, and that is load-bearing rather than tidy.
#
# docker-compose.test.yml is only an overlay — it sets no project name — so
# `docker compose -f docker-compose.yml -f docker-compose.test.yml` resolved to
# the SAME project as a developer's running dev stack (`et`). The cleanup trap
# below then ran `down --volumes` against it, so simply invoking this script
# deleted et_mariadb_data, et_redis_data and et_minio_data: the seeded demo
# tenant and every MinIO upload, gone, as a side effect of running the tests.
#
# The migrate/seed steps had the same problem in the other direction.
# `MARIADB_DATABASE: ethr_test` only takes effect when the volume is first
# created, so against an existing dev volume that database does not exist and
# these commands operate on whatever the dev stack was using.
#
# An explicit -p gives the e2e run its own containers and its own volumes, so
# the teardown can only ever destroy data this script created.
PROJECT="${ETHR_E2E_PROJECT:-ethr-e2e}"
COMPOSE="docker compose -p $PROJECT -f docker-compose.yml -f docker-compose.test.yml"
SPEC="${1:-}"

cleanup() {
  echo "--- Tearing down E2E stack ($PROJECT) ---"
  cd "$PROJECT_ROOT"
  $COMPOSE --profile e2e down --volumes --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

echo "--- Building and starting E2E stack ($PROJECT) ---"
cd "$PROJECT_ROOT"
$COMPOSE up -d --build --wait

echo "--- Running migrations ---"
$COMPOSE exec -T api php artisan migrate --force

echo "--- Seeding test data ---"
$COMPOSE exec -T api php artisan db:seed --class=PermissionSeeder --force
$COMPOSE exec -T api php artisan db:seed --class=PlanSeeder --force
$COMPOSE exec -T api php artisan db:seed --class=TaxBracketSeeder --force
$COMPOSE exec -T api php artisan db:seed --class=DemoTenantSeeder --force

# The platform super admin is created by DatabaseSeeder, which this script does
# not call — it seeds the four tenant-facing seeders directly. So until now
# `superadmin@ethr.et` did not exist in the E2E stack at all, and admin.spec.ts
# authenticated as an account that was never seeded: those specs could not have
# passed here regardless of which host they targeted.
#
# Provisioned with the shipped command rather than by adding DatabaseSeeder to
# the list above, because DatabaseSeeder also re-runs DemoTenantSeeder. Its
# `min:12` password rule is why this is not the dev seed's `password`; the value
# matches SUPER_ADMIN_PASS in docker-compose.test.yml.
echo "--- Seeding platform super admin ---"
$COMPOSE exec -T api php artisan ethr:create-admin \
  --email="${E2E_SUPER_ADMIN_EMAIL:-superadmin@ethr.et}" \
  --password="${E2E_SUPER_ADMIN_PASSWORD:-e2e-super-admin-pw}" \
  --force --no-interaction

echo "--- Running Playwright tests ---"
if [ -n "$SPEC" ]; then
  $COMPOSE --profile e2e run --rm e2e npx playwright test "$SPEC"
else
  $COMPOSE --profile e2e run --rm e2e npx playwright test
fi

echo "--- E2E tests completed ---"
