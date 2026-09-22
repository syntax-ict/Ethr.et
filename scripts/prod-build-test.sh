#!/usr/bin/env bash
set -uo pipefail

# Production build test — builds the real production images and boots the full
# stack, then asserts it actually works.
#
# Why this exists: `scripts/gates.sh` runs static/unit gates and never builds a
# production image; `scripts/run-e2e.sh` drives Playwright against the *dev*
# images (docker-compose.yml + .test.yml). Between them, nothing ever executed
# api/Dockerfile.prod or docker/frontend/Dockerfile, and nothing ever started
# docker-compose.prod.yml. Both were broken for a long time without failing
# anything: the api image could not build at all, and the stack could not boot.
#
# Safe to run alongside a live dev or e2e stack. Everything is namespaced under
# its own project (images tag as ethr-prod-test-*, not et-*), publishes no host
# ports, and is torn down with its volumes on exit.
#
# Usage:  ./scripts/prod-build-test.sh
# Exit:   0 = every check passed. Non-zero = count of failed checks.
#
# Not covered: nginx, and therefore TLS, the security headers, rate limiting and
# the three vhost splits. infrastructure/nginx-common.conf hardcodes
# /etc/letsencrypt/live/ethr.et/*.pem, so exercising it needs a real
# certificate. Everything nginx fronts is verified directly instead.

REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO" || exit 1

PROJECT="${ETHR_PROD_TEST_PROJECT:-ethr-prod-test}"
TMP=".prod-test-tmp"                 # inside the repo: Compose resolves relative
                                     # paths against the project directory, which
                                     # keeps this working on Windows and Linux
                                     # alike (an absolute /c/... path does not).
OVERRIDE="$TMP/override.yml"
ROOT_ENV="$TMP/root.env"
API_ENV="$TMP/api.env"

FAILED=0
PASSED=0

dc() { docker compose --project-directory "$REPO" --env-file "$ROOT_ENV" \
         -p "$PROJECT" -f docker-compose.prod.yml -f "$OVERRIDE" "$@"; }

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASSED=$((PASSED+1)); printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
bad()  { FAILED=$((FAILED+1)); printf '  \033[31mFAIL\033[0m  %s\n' "$1"; }
check(){ if [ "$1" -eq 0 ]; then ok "$2"; else bad "$2"; fi; }

cleanup() {
  # ETHR_KEEP_UP=1 leaves the stack running so a failure can be inspected —
  # container logs and `docker compose exec` are usually the only way to tell a
  # broken probe apart from a broken service. Tear down by hand afterwards.
  if [ -n "${ETHR_KEEP_UP:-}" ]; then
    say "Teardown skipped (ETHR_KEEP_UP set)"
    echo "  docker compose -p $PROJECT -f docker-compose.prod.yml -f $OVERRIDE down -v"
    return
  fi
  say "Teardown"
  dc down --volumes --remove-orphans >/dev/null 2>&1
  rm -rf "$TMP"
  echo "  stack and volumes removed"
}
trap cleanup EXIT

# ── Throwaway credentials ────────────────────────────────────────────────────
# Generated per run and destroyed with the stack. Deliberately not the operator's
# real .env: this test must be runnable on a machine that has never been
# configured for deployment.
say "Setup"
rm -rf "$TMP"; mkdir -p "$TMP"

rnd() { openssl rand -hex 24; }
DB_ROOT_PASSWORD="$(rnd)"; DB_PASSWORD="$(rnd)"; DB_READ_PASSWORD="$(rnd)"
REDIS_PASSWORD="$(rnd)";   MINIO_ACCESS_KEY="$(openssl rand -hex 12)"
MINIO_SECRET_KEY="$(rnd)"; REVERB_APP_KEY="$(openssl rand -hex 16)"
REVERB_APP_SECRET="$(openssl rand -hex 16)"
APP_KEY="base64:$(openssl rand -base64 32)"

cat > "$ROOT_ENV" <<EOF
DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD
DB_DATABASE=ethr
DB_USERNAME=ethr
DB_PASSWORD=$DB_PASSWORD
DB_READ_USERNAME=ethr_read
DB_READ_PASSWORD=$DB_READ_PASSWORD
REDIS_PASSWORD=$REDIS_PASSWORD
# The browser half of the Reverb credential pair. api/.env.production carries
# REVERB_APP_KEY (what the server checks); this is what gets compiled into the
# JS bundle. Both are set from the same variable here precisely so the assertion
# below can prove they agree.
NEXT_PUBLIC_REVERB_APP_KEY=$REVERB_APP_KEY
MINIO_ACCESS_KEY=$MINIO_ACCESS_KEY
MINIO_SECRET_KEY=$MINIO_SECRET_KEY
# API_REPLICAS / WORKER_REPLICAS / REVERB_REPLICAS are not written here: nothing
# reads them any more. docker-compose.prod.yml sets its replica counts literally,
# and the two workers are separate services rather than copies of one.
EOF

# api/.env.production.example is the committed template, with every secret blank.
# Copy it and fill in the required values. Deliberately NOT api/.env.production:
# that file is gitignored, so on a fresh clone it does not exist (this script
# read it for months on the strength of a comment claiming it was committed),
# and on a real host it holds live secrets this script has no business reading.
sed -e "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" \
    -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" \
    -e "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD|" \
    -e "s|^DB_READ_PASSWORD=.*|DB_READ_PASSWORD=$DB_READ_PASSWORD|" \
    -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD=$REDIS_PASSWORD|" \
    -e "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=$REVERB_APP_KEY|" \
    -e "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=$REVERB_APP_SECRET|" \
    -e "s|^MINIO_ACCESS_KEY=.*|MINIO_ACCESS_KEY=$MINIO_ACCESS_KEY|" \
    -e "s|^MINIO_SECRET_KEY=.*|MINIO_SECRET_KEY=$MINIO_SECRET_KEY|" \
    api/.env.production.example > "$API_ENV"

# Self-signed stand-in for the Let's Encrypt wildcard, so the nginx layer can be
# exercised without a real certificate. Same SAN set the production cert needs
# (apex + wildcard), same paths nginx-common.conf hardcodes. Clients below pass
# -k: this proves routing, vhost selection, headers and TLS termination, not
# trust-chain validity.
mkdir -p "$TMP/letsencrypt/live/ethr.et"
# Subject and SAN come from a config file rather than -subj/-addext. Under Git
# Bash, MSYS rewrites the leading slash of `-subj /CN=ethr.et` into a Windows
# path ("C:/Program Files/Git/CN=ethr.et") and openssl rejects it; suppressing
# the conversion with MSYS2_ARG_CONV_EXCL then breaks the output paths instead.
# A config file has no slash-prefixed argument to mangle, so this is portable.
cat > "$TMP/openssl.cnf" <<'EOF'
[req]
distinguished_name = dn
prompt = no
x509_extensions = v3
[dn]
CN = ethr.et
[v3]
subjectAltName = DNS:ethr.et,DNS:*.ethr.et,DNS:admin.ethr.et
basicConstraints = CA:FALSE
EOF
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
  -keyout "$TMP/letsencrypt/live/ethr.et/privkey.pem" \
  -out    "$TMP/letsencrypt/live/ethr.et/fullchain.pem" \
  -config "$TMP/openssl.cnf" -extensions v3 \
  >/dev/null 2>&1
check $? "generated self-signed wildcard certificate"
# Fatal: without it nginx cannot load its TLS config and crash-loops, which
# turns one setup fault into a whole cascade of misleading downstream failures.
[ -s "$TMP/letsencrypt/live/ethr.et/fullchain.pem" ] || {
  echo "  cannot continue without a certificate"; exit 1; }

cat > "$OVERRIDE" <<'EOF'
services:
  api:       {env_file: [./.prod-test-tmp/api.env], deploy: {replicas: 1}}
  scheduler: {env_file: [./.prod-test-tmp/api.env]}
  reverb:    {env_file: [./.prod-test-tmp/api.env], deploy: {replicas: 1}}
  # Pinned to one replica each so the assertions below address a known container.
  worker-realtime: {env_file: [./.prod-test-tmp/api.env], deploy: {replicas: 1}}
  worker-exports:  {env_file: [./.prod-test-tmp/api.env], deploy: {replicas: 1}}
  nginx:
    # Compose keys volumes by target path, so this replaces the host
    # /etc/letsencrypt bind mount rather than colliding with it.
    volumes:
      - ./.prod-test-tmp/letsencrypt:/etc/letsencrypt:ro
    # Publish nothing: the real file claims 80/443, which would collide with a
    # dev stack or anything else on the host. Assertions reach nginx over the
    # project's own network instead.
    ports: !reset []
EOF
echo "  generated throwaway credentials in $TMP/"

# ── 1. Build ─────────────────────────────────────────────────────────────────
say "1. Build production images"
if dc build > "$TMP/build.log" 2>&1; then
  ok "docker compose build"
else
  bad "docker compose build"
  echo "--- last 30 lines ---"; tail -30 "$TMP/build.log"
  exit 1                                    # nothing downstream can run
fi

# ── 2. Image contents ────────────────────────────────────────────────────────
say "2. Image contents"
docker run --rm --entrypoint sh "${PROJECT}-api" -c \
  'php -m | grep -qx redis && php -m | grep -qx pdo_mysql && php -m | grep -qx intl' >/dev/null 2>&1
check $? "api image has redis / pdo_mysql / intl"

docker run --rm --entrypoint sh "${PROJECT}-api" -c \
  '[ ! -d vendor/laravel/pail ] && [ ! -d vendor/laravel/sail ]' >/dev/null 2>&1
check $? "api image carries no dev dependencies"

# A host-built manifest here lists dev providers that --no-dev did not install,
# and the first command to boot the framework dies on the missing class.
docker run --rm --entrypoint sh "${PROJECT}-api" -c \
  '[ ! -f bootstrap/cache/packages.php ]' >/dev/null 2>&1
check $? "api image ships no stale package manifest"

docker run --rm --entrypoint sh "${PROJECT}-frontend" -c \
  '[ -f server.js ] && [ -d .next/static ]' >/dev/null 2>&1
check $? "frontend image has the Next standalone build"

# NEXT_PUBLIC_* are inlined at build time; if the build arg is dropped the
# hostname-aware login and the /admin guard silently go inert.
docker run --rm --entrypoint sh "${PROJECT}-frontend" -c \
  'grep -rq "ethr\.et" .next/static/chunks' >/dev/null 2>&1
check $? "frontend build args were inlined into the client bundle"

# The Reverb key specifically, because the generic check above cannot see it and
# its failure mode is silent. src/lib/echo.ts reads
# `process.env.NEXT_PUBLIC_REVERB_APP_KEY ?? "ethr-key"`, so when the build arg
# is missing the bundle compiles in the literal fallback and every WebSocket
# handshake is rejected by a server checking the real REVERB_APP_KEY. The REST
# API is unaffected, so notifications, live device status and attendance updates
# just stop with nothing in any log. That is exactly how it shipped: the arg was
# absent from the Dockerfile and from docker-compose.prod.yml, and no gate
# looked at it.
docker run --rm --entrypoint sh "${PROJECT}-frontend" -c \
  "grep -rq '$REVERB_APP_KEY' .next/static/chunks" >/dev/null 2>&1
check $? "browser bundle carries the real Reverb app key"

# The other direction: the fallback must be absent. Asserting only the presence
# of the real key would still pass if both were somehow present.
docker run --rm --entrypoint sh "${PROJECT}-frontend" -c \
  '! grep -rq "ethr-key" .next/static/chunks' >/dev/null 2>&1
check $? "browser bundle does not fall back to the placeholder Reverb key"

# ── 3. Boot ──────────────────────────────────────────────────────────────────
say "3. Boot the stack"
# nginx included: it gates on api and frontend being healthy, so starting it here
# also proves those two dependency conditions can actually be satisfied.
dc up -d --wait mariadb mariadb-replica redis redis-cache minio api scheduler reverb \
  worker-realtime worker-exports frontend nginx \
  > "$TMP/up.log" 2>&1
check $? "all services reached healthy (up --wait)"
[ $FAILED -eq 0 ] || { echo "--- last 20 lines ---"; tail -20 "$TMP/up.log"; dc ps; }

# ── 4. Replication and storage ───────────────────────────────────────────────
# Both are real deploy steps, exercised through the same scripts an operator runs.
say "4. Replication and storage provisioning"
COMPOSE_PROJECT_NAME="$PROJECT" COMPOSE_FILE=docker-compose.prod.yml \
  DB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" DB_DATABASE=ethr \
  ./scripts/setup-replication.sh > "$TMP/repl.log" 2>&1
check $? "scripts/setup-replication.sh"

COMPOSE_PROJECT_NAME="$PROJECT" COMPOSE_FILE=docker-compose.prod.yml \
  MINIO_ACCESS_KEY="$MINIO_ACCESS_KEY" MINIO_SECRET_KEY="$MINIO_SECRET_KEY" \
  ./scripts/init-storage.sh > "$TMP/storage.log" 2>&1
check $? "scripts/init-storage.sh"

# Replicating the primary's mysql schema over the replica breaks its own
# healthcheck account, and api gates on that service being healthy.
[ "$(docker inspect --format '{{.State.Health.Status}}' \
     "$(dc ps -q mariadb-replica)" 2>/dev/null)" = "healthy" ]
check $? "replica still healthy after replication was configured"

# ── 5. Schema ────────────────────────────────────────────────────────────────
say "5. Migrations and seeders"
dc exec -T api php artisan migrate --force > "$TMP/migrate.log" 2>&1
check $? "migrate --force on an empty database"

PENDING=$(dc exec -T api php artisan migrate:status 2>/dev/null | grep -c "Pending")
[ "$PENDING" -eq 0 ]
check $? "no pending migrations (found $PENDING)"
[ "$PENDING" -eq 0 ] || { echo "--- migrate.log ---"; tail -12 "$TMP/migrate.log"; }

for s in PermissionSeeder PlanSeeder TaxBracketSeeder; do
  dc exec -T api php artisan db:seed --class="$s" --force >/dev/null 2>&1
  check $? "seeder $s"
done

# ── 6. Runtime behaviour ─────────────────────────────────────────────────────
say "6. Runtime"
dc exec -T api php artisan health:check >/dev/null 2>&1
check $? "php artisan health:check (the container healthcheck command)"

# nginx is absent, so drive the route through the HTTP kernel directly.
HEALTH=$(dc exec -T api php -r '
require "/var/www/ethr/api/vendor/autoload.php";
$a = require "/var/www/ethr/api/bootstrap/app.php";
$k = $a->make(Illuminate\Contracts\Http\Kernel::class);
$r = $k->handle(Illuminate\Http\Request::create("/api/v1/health","GET"));
echo $r->getStatusCode(), " ", $r->getContent();' 2>/dev/null)

case "$HEALTH" in 200*) ok "GET /api/v1/health returns 200";;
                  *)    bad "GET /api/v1/health returns 200 (got: ${HEALTH:0:120})";; esac
case "$HEALTH" in *'"storage":"healthy"'*) ok "storage reports healthy";;
                  *) bad "storage reports healthy (bucket missing?)";; esac
case "$HEALTH" in *'"database_read":"healthy"'*) ok "read replica reports healthy";;
                  *) bad "read replica reports healthy";; esac

# Prove the split is real rather than both handles landing on the primary.
HOSTS=$(dc exec -T api php -r '
require "/var/www/ethr/api/vendor/autoload.php";
$a = require "/var/www/ethr/api/bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = Illuminate\Support\Facades\DB::connection();
echo $c->getReadPdo()->query("SELECT @@hostname")->fetchColumn(), " ",
     $c->getPdo()->query("SELECT @@hostname")->fetchColumn();' 2>/dev/null)
[ -n "$HOSTS" ] && [ "$(echo "$HOSTS" | awk '{print $1}')" != "$(echo "$HOSTS" | awk '{print $2}')" ]
check $? "reads and writes hit different hosts ($HOSTS)"

dc exec -T api sh -c 'wget -q -O /dev/null http://frontend:3000' >/dev/null 2>&1
check $? "frontend serves over the internal network"

# FIXED 2026-09-22, with docker-compose.prod.yml. These two checks asserted
# `horizon:status` and `horizon:supervisors`, neither of which is a defined
# command since cdf85d1 removed Horizon. They were left failing on purpose --
# deleting them would have made this test go green over a stack with no queue
# worker, which is the worse failure -- until the replacement topology was
# chosen. It now is: two services, attendance/notifications/default and exports.
#
# What replaces them has to answer the same question the old pair did: not "is a
# worker running somewhere", but "is THIS container draining the queues it was
# configured for". `horizon:status` failed that because it inspected every
# master registered in Redis, so one healthy container made them all pass.
#
# Docker's own healthcheck is now that per-container probe, so assert on it.
for svc in worker-realtime worker-exports; do
  STATE=$(dc ps --format '{{.Service}} {{.Health}}' 2>/dev/null | awk -v s="$svc" '$1==s {print $2; exit}')
  [ "$STATE" = "healthy" ]
  check $? "$svc reports healthy (queue:work process is up; got: '${STATE:-none}')"
done

# And assert the queue lists themselves, because a healthy container proves a
# worker is running, not that it drains the right queues. A `--queue` list that
# drifts from QueueHealth::QUEUES is the failure that looks healthy forever:
# the queue simply fills and only the health endpoint would ever say so.
for pair in "worker-realtime attendance,notifications,default" "worker-exports exports"; do
  svc=${pair%% *}; want=${pair#* }
  # /proc/1/cmdline is the container's own worker process, NUL-separated.
  GOT=$(dc exec -T "$svc" sh -c "tr '\0' ' ' < /proc/1/cmdline" 2>/dev/null \
        | grep -o -- '--queue=[a-z,]*' | head -1)
  [ "$GOT" = "--queue=$want" ]
  check $? "$svc drains --queue=$want (got: '${GOT:-none}')"
done

# Read the process environment rather than parsing `artisan about`, whose output
# is formatted for humans and whose resolvers touch every configured driver. This
# asserts the narrower thing that actually matters: that `env_file:` reached the
# container. If a config cache were ever baked into the image, Laravel would stop
# consulting env() and these would silently drift from what the file says.
APP_ENV_ACTUAL=$(dc exec -T api printenv APP_ENV 2>/dev/null | tr -d '\r\n')
[ "$APP_ENV_ACTUAL" = "production" ]
check $? "APP_ENV is production (got: '${APP_ENV_ACTUAL}')"

APP_DEBUG_ACTUAL=$(dc exec -T api printenv APP_DEBUG 2>/dev/null | tr -d '\r\n')
[ "$APP_DEBUG_ACTUAL" = "false" ]
check $? "APP_DEBUG is false (got: '${APP_DEBUG_ACTUAL}')"

# ── 7. nginx: TLS, vhost routing, headers ────────────────────────────────────
# Everything here was previously unverifiable because the layer needs a
# certificate. curl runs inside the project network with --resolve, so the three
# hostnames are exercised by real SNI without touching host DNS or host ports.
say "7. nginx (TLS, vhost routing, security headers)"

NET="${PROJECT}_ethr"
# $1 = shell snippet run with $NGINX bound to nginx's address on that network
via_curl() { docker run --rm --network "$NET" --entrypoint sh curlimages/curl:latest -c "
  NGINX=\$(getent hosts nginx | awk '{print \$1}')
  R=\"--resolve ethr.et:443:\$NGINX --resolve admin.ethr.et:443:\$NGINX --resolve demo.ethr.et:443:\$NGINX --resolve ethr.et:80:\$NGINX\"
  $1" 2>/dev/null; }

[ "$(via_curl 'curl -s -o /dev/null -w "%{http_code}" $R http://ethr.et/healthz')" = "200" ]
check $? "nginx liveness endpoint /healthz answers on :80"

# Must not redirect: the compose healthcheck hits it over plain HTTP and a 301
# to https://localhost would fail hostname verification against this cert.
[ "$(via_curl 'curl -s $R http://ethr.et/healthz')" = "ok" ]
check $? "/healthz returns plain 'ok' without redirecting"

[ "$(via_curl 'curl -s -o /dev/null -w "%{http_code}" $R http://ethr.et/')" = "301" ]
check $? "http :80 redirects to https"

for host in ethr.et admin.ethr.et demo.ethr.et; do
  [ "$(via_curl "curl -sk -o /dev/null -w '%{http_code}' \$R https://$host/")" = "200" ]
  check $? "https://$host/ serves 200"
done

# The console belongs to admin.ethr.et; the apex must refuse it. This cannot
# shadow the admin *API*, which lives under /api/v1/admin.
[ "$(via_curl 'curl -sk -o /dev/null -w "%{http_code}" $R https://ethr.et/admin')" = "404" ]
check $? "apex refuses /admin (console is admin-host only)"

# Apex, not a tenant host: {sub}.ethr.et is resolved to a tenant by the
# ResolveTenant middleware, so hitting demo.ethr.et here would 404 for want of a
# seeded tenant rather than for anything wrong with nginx or php-fpm.
API_CODE=$(via_curl 'curl -sk -o /dev/null -w "%{http_code}" $R https://ethr.et/api/v1/health')
[ "$API_CODE" = "200" ]
check $? "API reachable through nginx -> php-fpm (/api/v1/health) [got $API_CODE]"

# The other half of that: an unknown tenant host must reach Laravel and be
# rejected *as a tenant*, which proves nginx preserved the Host header and
# ResolveTenant parsed the subdomain out of it. A plain nginx 404, or a 502,
# would look like success to a bare status-code check.
TENANT_BODY=$(via_curl 'curl -sk $R https://demo.ethr.et/api/v1/health')
case "$TENANT_BODY" in
  *tenant-not-found*) ok  "unknown tenant host resolved and rejected by ResolveTenant";;
  *) bad "unknown tenant host resolved and rejected by ResolveTenant [got ${TENANT_BODY:0:80}]";;
esac

# TLS is genuinely terminating with the mounted certificate: verification must
# FAIL without -k (the cert is self-signed) and succeed with it. Asserting only
# the -k path would pass just as happily against plaintext.
# Compare curl's exit status, not its -w output: -w still prints "000" on a
# failed request, so a string comparison silently matched the success path.
[ "$(via_curl 'curl -s -o /dev/null $R https://ethr.et/ >/dev/null 2>&1; echo $?')" != "0" ]
check $? "unverified TLS is rejected without -k (a real cert is presented)"

HDRS=$(via_curl 'curl -sk -D - -o /dev/null $R https://demo.ethr.et/')
for h in "X-Frame-Options" "X-Content-Type-Options" "Strict-Transport-Security" \
         "Referrer-Policy" "Permissions-Policy" "Content-Security-Policy"; do
  echo "$HDRS" | grep -qi "^$h:"
  check $? "security header $h present"
done

# add_header replaces rather than merges inherited headers, so a static-asset
# block that sets Cache-Control silently drops the server-level security headers
# unless it re-declares them. Regression guard for exactly that.
ASSET=$(via_curl 'curl -sk -D - -o /dev/null $R https://demo.ethr.et/favicon.ico')
echo "$ASSET" | grep -qi "^X-Content-Type-Options:"
check $? "static assets keep security headers (add_header replaces, not merges)"

# ── Result ───────────────────────────────────────────────────────────────────
say "Result"
printf '  %d passed, %d failed\n' "$PASSED" "$FAILED"
exit "$FAILED"
