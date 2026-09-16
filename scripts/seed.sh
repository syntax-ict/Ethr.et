#!/bin/bash
set -euo pipefail

# Override with COMPOSE_FILE=docker-compose.lowmem.yml on the 4 GB tier —
# see that file's header for what it changes vs. this default.
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"

echo "=== ETHR Demo Seed ==="

cd "$(dirname "$0")/.."

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
