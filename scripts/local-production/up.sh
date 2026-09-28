#!/usr/bin/env bash
#
# Stand up a LOCAL PRODUCTION REHEARSAL of the Bronze/Plesk deployment.
#
#   scripts/local-production/up.sh              # build, assemble, migrate, start
#   scripts/local-production/up.sh --no-build   # skip the frontend build
#   scripts/local-production/down.sh            # stop Apache
#
# ┌──────────────────────────────────────────────────────────────────────────┐
# │ THIS PROVES NOTHING ABOUT THE ETHIO TELECOM HOST.                        │
# └──────────────────────────────────────────────────────────────────────────┘
#
# It is MY Apache, MY MariaDB and MY grants. Every host gate — M1, M2, G0-F,
# G0-B.6, M6, M3, G0-H, G0-I, G0-J, quotas — stays exactly where it was.
# `docs/deployment/CUTOVER-CHECKLIST.md` is unchanged by anything here, and
# `AllowOverride All` below is the permissive case, which is the specific reason
# BASELINE.md §21e says the local harness cannot answer G0-B.6.
#
# WHAT IT IS FOR. The Bronze deployment procedure has never been executed end to
# end, anywhere. §21 served the static export under the rendered rules, but nobody
# has assembled a real document root with a repointed `index.php`, run `migrate`
# against MariaDB through it, and had the application answer `/api/v1/*` through
# Apache. This does all of that, on the same Apache 2.4.58 and MariaDB 10.4.32
# builds the earlier measurements used. What it finds is a defect in OUR procedure
# or OUR code — which is worth finding, and is a different claim from "the host
# works".
#
# WHAT IT DOES NOT TOUCH:
#   - `api/.env`            your dev environment, and what the gates run against
#   - `bootstrap/cache/`    a production `config:cache` there would break the gates
#   - XAMPP's httpd.conf    this runs a dedicated Apache on its own config/port
#   - any existing database anything but the `ethr` schema it creates
#
# Undo: `scripts/local-production/down.sh` then `rm -rf .local-production`.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ROOT="$REPO_ROOT/.local-production"
APP_ROOT="$ROOT/app"          # mirrors ~/ethr on the host — NOT web accessible
DOCROOT="$ROOT/docroot"       # mirrors httpdocs/
APACHE_DIR="$ROOT/apache"

XAMPP="${XAMPP:-/c/xampp}"
HTTPD="$XAMPP/apache/bin/httpd.exe"
MYSQL="$XAMPP/mysql/bin/mysql.exe"

# Defaults, and they are the documented ones — api/.env.shared-hosting.example.
PORT="${PORT:-8081}"
# The platform console's hostname. Required by the renderer, no default — see its
# --admin-host note. Deliberately NOT "localhost:$PORT": the single local host would
# then be the ADMIN host, and every tenant route would 302 to /admin/, making the
# tenant app unbrowsable. This way localhost:$PORT is a TENANT host — which is what
# you want to look at — and /admin is correctly denied there, mirroring production.
# Reach the console with:  curl -H "Host: $ADMIN_HOST" http://localhost:$PORT/admin
ADMIN_HOST="${ADMIN_HOST:-admin.localhost:8081}"
DB_NAME="${DB_NAME:-ethr}"
DB_USER="${DB_USER:-ethr}"
DB_PASS="${DB_PASS:-ethr_local_rehearsal}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

BUILD=true
[ "${1:-}" = "--no-build" ] && BUILD=false

step() { printf '\n\033[1m━━━ %s\033[0m\n' "$1"; }
info() { printf '  %s\n' "$1"; }
die()  { printf '\n  REFUSING: %s\n' "$1" >&2; exit 1; }

step "0. Preconditions"

[ -x "$HTTPD" ] || die "no Apache at $HTTPD (set XAMPP=/path/to/xampp)"
[ -x "$MYSQL" ] || die "no mysql client at $MYSQL"
"$HTTPD" -v | head -1 | sed 's/^/  /'
"$MYSQL" --version | sed 's/^/  /'

# Refuse to collide with something already listening. Silently sharing a port
# produces "it works" against the wrong server, which is the worst outcome here.
if netstat -ano 2>/dev/null | grep -q "LISTENING" && netstat -ano 2>/dev/null | grep LISTENING | grep -qE ":${PORT}[[:space:]]"; then
    die "port $PORT is already in use. Re-run with PORT=8082."
fi
info "port $PORT is free"

step "1. Isolated application root (mirrors ~/ethr)"

# A COPY, not the working tree. `config:cache` writes bootstrap/cache/config.php,
# and a production config cached into the repo would break `gates.sh` until someone
# noticed. Copying is slower and cannot do that.
mkdir -p "$APP_ROOT"
if [ -d "$APP_ROOT/api" ]; then
    info "reusing $APP_ROOT/api (delete .local-production to start clean)"
else
    info "copying api/ -> $APP_ROOT/api (vendor included; this takes a minute)"
    # No .env, no .git, no dev caches — the same exclusions the release tarball uses.
    tar -cf - --force-local \
        --exclude='api/.env' \
        --exclude='api/.git' \
        --exclude='api/storage/logs/*' \
        --exclude='api/storage/framework/cache/*' \
        --exclude='api/storage/framework/sessions/*' \
        --exclude='api/storage/framework/views/*' \
        --exclude='api/.phpunit.result.cache' \
        -C "$REPO_ROOT" api | tar -xf - --force-local -C "$APP_ROOT"
fi

mkdir -p "$APP_ROOT/api/storage/framework/"{cache,sessions,views} \
         "$APP_ROOT/api/storage/logs" "$APP_ROOT/api/bootstrap/cache"

step "2. Database ($DB_NAME) — default names from .env.shared-hosting.example"

"$MYSQL" -u root -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# BOTH host forms, and this is not belt-and-braces. MySQL/MariaDB match grants on
# how the SERVER sees the connection, and `localhost` (socket / named pipe) is a
# DIFFERENT grant host from `127.0.0.1` (TCP). Granting only `@localhost` and then
# connecting over TCP gives
#
#   SQLSTATE[HY000] [1045] Access denied for user 'ethr'@'localhost' (using password: YES)
#
# — measured here on the first run, and it reads as a wrong password, which is the
# wrong thing to go and check. `api/.env.shared-hosting.example` uses
# `DB_HOST=localhost` because on the host the database is local to the account; the
# default here is 127.0.0.1 because it is unambiguous on Windows, and both are
# granted so either value works.
for h in localhost 127.0.0.1; do
    "$MYSQL" -u root -e "CREATE USER IF NOT EXISTS '$DB_USER'@'$h' IDENTIFIED BY '$DB_PASS';"
    "$MYSQL" -u root -e "ALTER USER '$DB_USER'@'$h' IDENTIFIED BY '$DB_PASS';"
    "$MYSQL" -u root -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'$h';"
done
"$MYSQL" -u root -e "FLUSH PRIVILEGES;"

info "server: $("$MYSQL" -u root -N -e 'SELECT VERSION();')"
info "grants: $("$MYSQL" -u root -N -e "SHOW GRANTS FOR '$DB_USER'@'$DB_HOST';" | tail -1)"

# Prove the app's own credentials work before migrate does, so a grant problem is
# not reported as a migration failure.
"$MYSQL" -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -P "$DB_PORT" -N -e "SELECT 'connected as '||CURRENT_USER();" "$DB_NAME" 2>/dev/null \
    | sed 's/^/  /' || die "the app user cannot connect as $DB_USER@$DB_HOST — check the grants above"

step "3. Environment — from api/.env.shared-hosting.example, the Bronze template"

ENV_FILE="$APP_ROOT/api/.env"

if [ -f "$ENV_FILE" ]; then
    info "reusing the existing .env (APP_KEY preserved)"
else
    cp "$REPO_ROOT/api/.env.shared-hosting.example" "$ENV_FILE"

    # Fill only what the template marks REQUIRED, with local values.
    #
    # APP_URL carries the port: Laravel builds signed URLs from it, and
    # FileStorageService serves every document through a signed temporaryUrl(), so
    # a wrong APP_URL breaks file access in a way that looks like a storage fault.
    python - "$ENV_FILE" "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS" "$PORT" <<'PY'
import io, re, sys, secrets
path, dbhost, dbport, dbname, dbuser, dbpass, port = sys.argv[1:8]
s = io.open(path, encoding='utf-8').read()

def setkey(text, key, value):
    pat = re.compile(r'^(%s=)([^\n#]*)' % re.escape(key), re.M)
    if pat.search(text):
        return pat.sub(lambda m: m.group(1) + value, text, count=1)
    return text.rstrip('\n') + '\n%s=%s\n' % (key, value)

for k, v in [
    ('APP_ENV', 'production'),
    ('APP_DEBUG', 'false'),
    ('APP_URL', 'http://localhost:%s' % port),
    ('APP_DOMAIN', 'localhost'),
    ('DB_HOST', dbhost),
    ('DB_PORT', dbport),
    ('DB_DATABASE', dbname),
    ('DB_USERNAME', dbuser),
    ('DB_PASSWORD', dbpass),
    # A fresh 32-byte token. Below 32 chars VerifyCronToken 404s the routes.
    ('CRON_TOKEN', secrets.token_hex(32)),
]:
    s = setkey(s, k, v)

io.open(path, 'w', encoding='utf-8', newline='').write(s)
PY
    info "wrote $ENV_FILE"

    # A FRESH key. Never the one in git history (START_BACKEND.ps1, removed in
    # 090ca50) — CUTOVER-CHECKLIST.md's rotation note requires exactly this.
    (cd "$APP_ROOT/api" && php artisan key:generate --force --no-interaction >/dev/null)
    info "APP_KEY generated fresh (not the key in git history)"
fi

step "4. Migrate — this exercises the audit-log CREATE TRIGGER on MariaDB"

# The migration ABORTS if CREATE TRIGGER is denied, by design. Here the user holds
# ALL PRIVILEGES on its own schema, which is the shape Plesk grants by default —
# so a pass here says the migration works against that shape. It says NOTHING about
# whether Ethio Telecom's user holds it. That is G0-F and it stays open.
(cd "$APP_ROOT/api" && php artisan migrate --force --no-interaction 2>&1 | tail -6 | sed 's/^/  /')

info "triggers now on audit_log:"
"$MYSQL" -u root -N -e "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_TABLE='audit_log' AND TRIGGER_SCHEMA='$DB_NAME';" | sed 's/^/    /'

step "5. Frontend — the Bronze static export"

if [ "$BUILD" = true ]; then
    (cd "$REPO_ROOT/src" && npm run build:shared-hosting 2>&1 | tail -4 | sed 's/^/  /')
else
    info "skipped (--no-build)"
fi
[ -d "$REPO_ROOT/src/out" ] || die "src/out is missing — run without --no-build"

step "6. Assemble the document root (DEPLOYMENT.md §4a)"

rm -rf "$DOCROOT"
mkdir -p "$DOCROOT"

# The exported site.
cp -r "$REPO_ROOT/src/out/." "$DOCROOT/"

# The three files §4a names. index.php is Laravel's front controller with its two
# require paths repointed at <APP_ROOT>/api — the "three lines, not two" §4a warns
# about is that `$app->handleRequest` needs no change but BOTH requires do, plus
# the maintenance-mode include above them.
cp "$REPO_ROOT/api/public/favicon.ico" "$DOCROOT/" 2>/dev/null || true
cp "$REPO_ROOT/api/public/robots.txt" "$DOCROOT/robots-laravel.txt" 2>/dev/null || true

python - "$REPO_ROOT/api/public/index.php" "$DOCROOT/index.php" <<'PY'
import io, re, sys
src, dst = sys.argv[1], sys.argv[2]
s = io.open(src, encoding='utf-8').read()
# ../vendor/autoload.php  ->  ../app/api/vendor/autoload.php  (and bootstrap/app.php,
# and storage/framework/maintenance.php). Rewriting __DIR__.'/../' is the whole edit.
s2, n = re.subn(r"__DIR__\s*\.\s*'/\.\./", "__DIR__.'/../app/api/", s)
io.open(dst, 'w', encoding='utf-8', newline='').write(s2)
print("  index.php repointed: %d require path(s) rewritten" % n)
if n < 2:
    raise SystemExit("  REFUSING: expected at least 2 rewrites; api/public/index.php changed shape")
PY

# The rendered .htaccess — generated, never hand-edited.
php "$REPO_ROOT/scripts/shared-hosting/render-htaccess.php" \
    --target=static-export --admin-host="$ADMIN_HOST" \
    -o "$DOCROOT/.htaccess" 2>&1 | sed 's/^/  /'
info "admin host: $ADMIN_HOST   (localhost:$PORT is a TENANT host)"

info "docroot: $(find "$DOCROOT" -type f | wc -l) files"

step "7. Apache — dedicated instance, XAMPP's own config untouched"

mkdir -p "$APACHE_DIR/logs"
# Native Windows paths. Git Bash translates paths in ARGUMENTS to a Windows binary,
# but an httpd.conf is a FILE — no translation happens, and Apache reports only
# "ServerRoot must be a valid directory" for a POSIX-style path. Measured 2026-09-27.
w() { cygpath -m "$1"; }

SRV="$(w "$XAMPP/apache")"
W_DOCROOT="$(w "$DOCROOT")"
W_APP_ROOT="$(w "$APP_ROOT")"
W_APACHE_DIR="$(w "$APACHE_DIR")"
W_PHPDIR="$(w "$XAMPP/php")"

# Only the modules the deployment needs, so a missing one fails here rather than
# presenting as a routing bug: rewrite, headers, dir (DirectorySlash), expires,
# authz_core (Require), mime, log_config, unixd-equivalents.
# Unquoted heredoc: it must expand $W_DOCROOT and friends. That also makes BACKTICKS
# command substitution, even inside # comments — measured 2026-09-27, when a comment
# reading "reports `php sapi : fpm-fcgi`" put "Could not open input file: sapi" into
# the generated config. Use single quotes in here, never backticks.
cat > "$APACHE_DIR/httpd.conf" <<CONF
ServerRoot "$SRV"
Listen $PORT
ServerName localhost:$PORT
PidFile "$W_APACHE_DIR/httpd.pid"
ErrorLog "$W_APACHE_DIR/logs/error.log"
CustomLog "$W_APACHE_DIR/logs/access.log" common
LogLevel warn

LoadModule authz_core_module modules/mod_authz_core.so
LoadModule mime_module modules/mod_mime.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule dir_module modules/mod_dir.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule expires_module modules/mod_expires.so
LoadModule alias_module modules/mod_alias.so
LoadModule cgi_module modules/mod_cgi.so
LoadModule actions_module modules/mod_actions.so

# PHP via mod_cgi + Action, NOT mod_php and NOT mod_proxy_fcgi. Three measurements
# on 2026-09-27 produced that choice, and the trail is worth keeping because each
# failure looked like something else:
#
#   mod_php                 "Fatal Error VirtualProtect() failed" — will not load on
#                           this XAMPP build. Already on record in BASELINE.md §21g,
#                           which is why that pass verified .htaccess ROUTING but not
#                           PHP EXECUTION: index.php was served as text.
#   mod_proxy_fcgi          Apache appends the mapped path to the handler URL, and a
#                           Windows path has no leading slash:
#                             AH00898: URI cannot be parsed: fcgi://127.0.0.1:9123F:/...
#                           -> 400 Proxy Error. A trailing slash on the handler fixes
#                           the parse and then php-cgi gets SCRIPT_FILENAME as
#                           "/F:/..." and answers "No input file specified" (404).
#                           ProxyFCGISetEnvIf did not correct it here.
#   mod_cgi + Action        works.
#
# THE FIDELITY COST, stated rather than buried: this is CGI, one process per request.
# The Ethio Telecom host reports 'php sapi : fpm-fcgi' (canary, 2026-09-25) — a
# persistent FastCGI pool. So request-level PHP behaviour is exercised here and
# process-lifecycle behaviour is not, and nothing here measures the host's SAPI.
# What it does buy is the thing §21g could not do at all: index.php is EXECUTED, so
# the rewrite and the application are verified together.
ScriptAlias /__php-cgi-bin/ "$W_PHPDIR/"
<Directory "$W_PHPDIR">
    Require all granted
    Options +ExecCGI
</Directory>
AddHandler application/x-httpd-php-cgi .php
Action application/x-httpd-php-cgi "/__php-cgi-bin/php-cgi.exe"

TypesConfig conf/mime.types
DirectoryIndex index.html index.php

DocumentRoot "$W_DOCROOT"
<Directory "$W_DOCROOT">
    # AllowOverride All is the PERMISSIVE case. It is exactly why this harness
    # cannot answer G0-B.6 — whether Ethio Telecom grants the Options class these
    # rules need is unmeasured, and BASELINE.md §21e says so.
    AllowOverride All
    Require all granted
</Directory>

# <APP_ROOT> must never be reachable over HTTP. On the host this holds by
# construction because ~/ethr is not under httpdocs; here the two are siblings, so
# the boundary is asserted rather than assumed.
<Directory "$W_APP_ROOT">
    Require all denied
</Directory>
CONF

"$HTTPD" -f "$APACHE_DIR/httpd.conf" -t 2>&1 | sed 's/^/  /' || die "Apache rejected the config"
info "note: httpd -t does NOT read .htaccess — see BASELINE.md §21c"

step "8. Start"

# NOT `-k start`. On Windows that drives the Apache Windows SERVICE and fails with
#   AH00436: No installed service named "Apache2.4"
# — measured 2026-09-27. XAMPP installs no service by default, and installing one for
# a rehearsal would leave machine state behind. Run it in the foreground, backgrounded.
#
# No PID is recorded: in Git Bash `$!` is an MSYS job id, not the Windows pid that
# taskkill needs, and writing it produced a down.sh that reported success while both
# servers kept serving. down.sh resolves the pid from the LISTENING socket instead.
info "apache starting on $PORT"
"$HTTPD" -f "$APACHE_DIR/httpd.conf" -DFOREGROUND \
    > "$APACHE_DIR/logs/stdout.log" 2>&1 &

# Wait for the port rather than sleeping a fixed amount: a fixed sleep either
# wastes time or reports a failure that was only slowness.
for _ in $(seq 1 30); do
    netstat -ano 2>/dev/null | grep LISTENING | grep -qE ":${PORT}[[:space:]]" && break
    sleep 1
done

if ! netstat -ano 2>/dev/null | grep LISTENING | grep -qE ":${PORT}[[:space:]]"; then
    tail -20 "$APACHE_DIR/logs/error.log" 2>/dev/null | sed 's/^/  /'
    die "Apache did not start — see $APACHE_DIR/logs/error.log"
fi

# LISTENING IS NOT SERVING, and the difference is not theoretical: after this machine
# slept overnight, the previous httpd kept the socket open while every request timed
# out (curl exit 28, no status). A port check alone reported that as healthy.
#
# So require a real response before claiming success. `|| true` on curl because a
# failure here must produce this script's diagnosis, not a bare pipefail exit.
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "http://localhost:$PORT/" || true)"

if [ "$code" = "000" ] || [ -z "$code" ]; then
    tail -20 "$APACHE_DIR/logs/error.log" 2>/dev/null | sed 's/^/  /'
    die "port $PORT is listening but nothing answers (curl got '$code'). A wedged httpd
                 holding the socket looks identical to a healthy one from netstat.
                 Run scripts/local-production/down.sh and try again."
fi

info "listening on http://localhost:$PORT (GET / -> $code)"

step "Done — now verify, do not assume"

cat <<EOF
  scripts/local-production/verify.sh          the path matrix
  scripts/local-production/down.sh            stop

  http://localhost:$PORT/                     the exported site
  http://localhost:$PORT/api/v1/health        Laravel through .htaccess

  THIS CLOSES NO HOST GATE. ./scripts/gates.sh evidence still lists all eleven.
EOF
