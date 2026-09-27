#!/bin/bash
set -euo pipefail

# REPOINTED 2026-09-27 — this defaulted to `docker-compose.prod.yml`, removed in
# `3db9904`, so the script failed at its first call.
#
# Unlike `backup.sh` and `restore.sh`, which are retired beside it, this one is
# genuinely repointable: the only service it touches is `api`, and the local
# development stack has one. So it keeps working, as a LOCAL DEVELOPMENT utility.
#
# It is NOT a deployment step and never was a rollback path. On Bronze/Plesk there
# is no Docker and no shell: demo data would go in through Plesk Git's *additional
# deployment actions*, which is a documented route nobody has exercised
# (`docs/deployment/VPS-DECOMMISSION.md` §3b.2a marks this row ⚠ for exactly that
# reason).
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"

cd "$(dirname "$0")/.."

if [ ! -f "$COMPOSE_FILE" ]; then
  echo "ERROR: compose file not found: $COMPOSE_FILE" >&2
  echo "       The VPS production stack was removed in 3db9904; the local stack is" >&2
  echo "       docker-compose.yml. Override with COMPOSE_FILE=... if you need another." >&2
  exit 66
fi

echo "=== ETHR Demo Seed (local development) ==="

echo ">> Running demo tenant seeder..."
# DemoTenantSeeder calls fake() (DemoTenantSeeder.php:136), and fakerphp/faker is
# a require-dev package that `composer install --no-dev` leaves out of the
# production image. Without this guard the seeder runs far enough to create the
# tenant row and then dies with "Call to undefined function ...\fake()", leaving a
# half-populated tenant that resolves at {sub}.ethr.et but has no data behind it.
# Fail before writing anything rather than partway through.
if ! docker compose -f "$COMPOSE_FILE" exec -T api php -r \
     'exit(function_exists("fake") ? 0 : 1);' 2>/dev/null; then
  echo "ERROR: fakerphp/faker is not installed in the api image (--no-dev)." >&2
  echo "       DemoTenantSeeder cannot run here and would leave a partial tenant." >&2
  echo "       Seed demo data from a dev image, or replace fake() with static values." >&2
  exit 1
fi

docker compose -f "$COMPOSE_FILE" exec api php artisan db:seed --class=DemoTenantSeeder --force

echo "=== Demo tenant created ==="
echo "URL:      https://demo.<your-domain>"
echo "Login:    admin@demo.ethr.et"
echo "Password: password"
