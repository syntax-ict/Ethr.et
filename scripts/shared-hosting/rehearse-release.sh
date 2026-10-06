#!/usr/bin/env bash
#
# Rehearse a release the way the host runs it: Plesk Git writes the tree into
# <APP_ROOT>, then the owner runs artisan from the Laravel Toolkit. This does
# both against a throwaway copy, and asserts what would be expensive to learn
# live:
#
#   1. the tree boots: api/public/index.php (the document root) answers
#      /api/v1/ping through PHP's built-in server, with the release's vendor/;
#   2. the Toolkit sequence works on a fresh .env made from the template:
#      key:generate, migrate, ethr:create-admin;
#   3. a SECOND deploy over the first (Plesk Git writing the next release)
#      leaves .env and its APP_KEY alone, and migrate runs clean again.
#      Plesk Git does not track .env, and nothing in a release may carry one.
#
# SQLite stands in for the host's MySQL, so this proves the tree and the
# sequence, not the host's grants (G0-F) or its PHP (M2).
#
# Usage: scripts/shared-hosting/rehearse-release.sh <release-dir>
#
set -euo pipefail

REL="$(cd "${1:?usage: rehearse-release.sh <release-dir>}" && pwd)"
WORK="$(mktemp -d)"
SERVER_PID=""
cleanup() {
  # `wait` on a killed server returns 143, and under `set -e` that would become
  # the script's exit status after a passing rehearsal (CI, 2026-10-06).
  if [ -n "$SERVER_PID" ]; then kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; fi
  rm -rf "$WORK" 2>/dev/null || true
}
trap cleanup EXIT

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok   %s\n' "$1"; }

APP="$WORK/home/ethr"
API="$APP/api"
mkdir -p "$WORK/home"
cp -R "$REL" "$APP"
artisan() { (cd "$API" && php artisan "$@" --no-interaction); }

# The owner's File Manager / Toolkit .env step: the template as shipped. Its
# `APP_KEY=   # REQUIRED` line is left alone on purpose.
ENV="$API/.env"
cp "$API/.env.shared-hosting.example" "$ENV"
touch "$WORK/db.sqlite"
# Git Bash's /tmp is invisible to a native Windows PHP; give it a mixed path.
DB_FILE="$WORK/db.sqlite"
command -v cygpath >/dev/null 2>&1 && DB_FILE="$(cygpath -m "$DB_FILE")"
sed -i -e 's#^DB_CONNECTION=.*#DB_CONNECTION=sqlite#' \
       -e "s#^DB_DATABASE=.*#DB_DATABASE=$DB_FILE#" "$ENV"
grep -q '^DB_CONNECTION=sqlite' "$ENV" || fail "could not set DB_CONNECTION in the rehearsal .env"
grep -q "^DB_DATABASE=$DB_FILE" "$ENV" || fail "could not set DB_DATABASE in the rehearsal .env"

# 2 — the Toolkit sequence.
artisan key:generate --force >/dev/null || fail "key:generate failed"
grep -qE '^APP_KEY=base64:' "$ENV" || fail "key:generate did not write APP_KEY"
KEY1="$(grep '^APP_KEY=' "$ENV")"
artisan migrate --force >"$WORK/migrate1" 2>&1 || { cat "$WORK/migrate1"; fail "first migrate failed"; }
# The catalog seed runs from the RELEASE's vendor/, which has no dev packages, so
# this also proves ProductionSeeder needs none (DemoTenantSeeder needs faker).
artisan db:seed --class=ProductionSeeder --force >"$WORK/seed1" 2>&1 || { cat "$WORK/seed1"; fail "ProductionSeeder failed"; }
artisan ethr:create-admin --email=owner@example.et --password=Rehearsal-Passw0rd-123 --force >/dev/null \
  || fail "ethr:create-admin failed"
PERMS="$(php -r '$p = new PDO("sqlite:".$argv[1]); echo $p->query("SELECT COUNT(*) FROM permissions")->fetchColumn();' "$DB_FILE")"
[ "${PERMS:-0}" -gt 0 ] || fail "ProductionSeeder ran but the permissions table is empty — every role would get 403"
pass "Toolkit sequence: key generated, migrated, catalog seeded ($PERMS permissions), super admin created"

# 1 — the document root boots the app.
PORT=$(( 20000 + RANDOM % 20000 ))
(cd "$API/public" && php -S "127.0.0.1:$PORT" index.php >"$WORK/server.log" 2>&1) &
SERVER_PID=$!
PING=""
for _ in $(seq 1 30); do
  PING="$(curl -s --max-time 5 "http://127.0.0.1:$PORT/api/v1/ping" || true)"
  case "$PING" in *'"status":"ok"'*) break ;; esac
  sleep 1
done
case "$PING" in *'"status":"ok"'*) ;; *) cat "$WORK/server.log" >&2; fail "api/public/index.php did not answer /api/v1/ping: ${PING:-no response}" ;; esac
pass "the document root (api/public/index.php) boots the app: /api/v1/ping ok"

# 3 — the next release written over this one, as Plesk Git does.
cp -R "$REL/." "$APP/"
[ -f "$ENV" ] || fail "a redeploy removed api/.env"
[ "$(grep '^APP_KEY=' "$ENV")" = "$KEY1" ] || fail "a redeploy changed APP_KEY"
artisan migrate --force >"$WORK/migrate2" 2>&1 || { cat "$WORK/migrate2"; fail "second migrate failed"; }
artisan db:seed --class=ProductionSeeder --force >"$WORK/seed2" 2>&1 || { cat "$WORK/seed2"; fail "ProductionSeeder is not re-runnable"; }
grep -q 'Nothing to migrate' "$WORK/migrate2" || fail "second migrate was not a no-op"
pass "a second deploy keeps .env and APP_KEY; migrate is a no-op"

echo "rehearsal passed"
