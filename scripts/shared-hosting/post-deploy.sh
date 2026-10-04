#!/usr/bin/env bash
#
# The Plesk Git "additional deployment action" for ETHR. One line goes in that
# field, and it is the same line for the first deploy and every one after:
#
#     bash deploy/post-deploy.sh
#
# Plesk runs it after writing the `production` branch into <APP_ROOT> (~/ethr/).
# EVERY STEP IS SAFE TO REPEAT, because Plesk repeats it on every deploy:
#
#   - APP_KEY is generated ONLY when .env has none. The old printed action list
#     ran `key:generate --force` unconditionally — on the second deploy that
#     replaces the key, and every `encrypted` column (Employee.tin, national_id,
#     bank details, TOTP secrets) becomes unreadable, permanently.
#   - The super admin is created ONLY from a one-shot file the owner places by
#     hand, which this script deletes once the account exists. No password ever
#     sits in the Plesk field, in .env or in the repository.
#   - migrate is idempotent; the caches are rebuilt from the final .env.
#   - The document root is updated LAST, after migrate, so a new frontend never
#     talks to an old schema. Nothing in it is deleted: `.well-known/` (ACME)
#     and anything the host put there survive.
#
# It refuses to continue — non-zero exit, which Plesk shows as a failed deploy —
# when .env is missing, PHP is too old, or the document root looks wrong.
#
# Overrides (all optional): ETHR_PHP, ETHR_DOCROOT.
#
set -euo pipefail

ETHR_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API="$ETHR_ROOT/api"
DOCROOT="${ETHR_DOCROOT:-$ETHR_ROOT/../httpdocs}"
BOOTSTRAP="$ETHR_ROOT/admin-bootstrap.env"

step() { printf '\n== %s\n' "$1"; }
info() { printf '   %s\n' "$1"; }
die()  { printf '   STOPPED: %s\n' "$1" >&2; exit 1; }

step "ETHR post-deploy — $(sed -n 's/^commit=//p' "$ETHR_ROOT/RELEASE" 2>/dev/null | cut -c1-12)"

# --- PHP ---------------------------------------------------------------------
# The deployment-action shell's `php` is not necessarily the PHP the site runs
# (8.3 in the panel). Plesk installs each version under /opt/plesk/php/<v>/bin.
if [ -n "${ETHR_PHP:-}" ]; then
  PHP="$ETHR_PHP"
elif [ -x /opt/plesk/php/8.3/bin/php ]; then
  PHP=/opt/plesk/php/8.3/bin/php
else
  PHP="$(command -v php || true)"
fi
[ -n "$PHP" ] || die "no php binary found; set ETHR_PHP"
"$PHP" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' \
  || die "$PHP is PHP $("$PHP" -r 'echo PHP_VERSION;'); ETHR needs 8.2+. Set ETHR_PHP=/opt/plesk/php/8.3/bin/php"
info "php: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
artisan() { (cd "$API" && "$PHP" artisan "$@" --no-interaction); }

# --- Document root sanity -----------------------------------------------------
# Publishing into the wrong directory is the one mistake here that exposes
# secrets: a document root at or above ~/ethr/ serves api/.env.
[ -d "$DOCROOT" ] || die "document root $DOCROOT does not exist; set ETHR_DOCROOT"
DOCROOT="$(cd "$DOCROOT" && pwd)"
case "$ETHR_ROOT/" in
  "$DOCROOT"/*) die "the app directory $ETHR_ROOT is INSIDE the document root $DOCROOT — that would serve api/.env. Fix the Plesk Git deployment path." ;;
esac
[ "$DOCROOT" != "$ETHR_ROOT" ] || die "the document root is the app directory"
info "app root: $ETHR_ROOT"
info "docroot : $DOCROOT"

# --- .env ---------------------------------------------------------------------
[ -f "$API/.env" ] || die "$API/.env does not exist. Create it in File Manager from api/.env.shared-hosting.example (fill DB_*, MAIL_*, CRON_TOKEN; leave APP_KEY empty), then deploy again."
mkdir -p "$API/storage/framework/cache/data" "$API/storage/framework/sessions" \
         "$API/storage/framework/views" "$API/storage/logs" "$API/storage/app/private" \
         "$API/bootstrap/cache"

# The value, not the line: the template ships `APP_KEY=   # REQUIRED: ...`, and
# dotenv reads that as empty. Matching `^APP_KEY=.+` would take the comment for
# a key and skip generation on exactly the first deploy that needs it.
app_key() {
  sed -n 's/^APP_KEY=//p' "$API/.env" | head -1 \
    | sed -e 's/[[:space:]]#.*$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/'
}
if [ -n "$(app_key)" ]; then
  info "APP_KEY present — left untouched"
else
  artisan key:generate --force >/dev/null
  [ -n "$(app_key)" ] || die "key:generate did not write APP_KEY — is .env writable?"
  info "APP_KEY generated (first deploy). Back up api/.env now: without this key the encrypted columns cannot be read."
fi

# Drop the previous release's compiled config/routes/views before reading the
# new ones. NOT `optimize:clear`: that also runs cache:clear, which on this host
# is the `database` store — `delete from cache` against a table that does not
# exist until migrate runs, so the very first deploy died here (found by
# rehearse-release.sh, 2026-10-04).
artisan config:clear >/dev/null
artisan route:clear >/dev/null
artisan view:clear >/dev/null

# --- Schema -------------------------------------------------------------------
step "migrate"
# Aborts by design if the database user lacks TRIGGER (G0-F): the audit-log
# triggers are the control that survives a mass update. Do not soften it.
artisan migrate --force

# --- One-shot super admin -----------------------------------------------------
if [ -f "$BOOTSTRAP" ]; then
  step "super admin (one-shot)"
  ADMIN_EMAIL="$(sed -n 's/^ADMIN_EMAIL=//p' "$BOOTSTRAP" | head -1)"
  ADMIN_PASSWORD="$(sed -n 's/^ADMIN_PASSWORD=//p' "$BOOTSTRAP" | head -1)"
  [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ] \
    || die "$BOOTSTRAP needs ADMIN_EMAIL= and ADMIN_PASSWORD= lines"
  artisan ethr:create-admin --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" --force >/dev/null
  rm -f "$BOOTSTRAP"
  unset ADMIN_PASSWORD
  info "super admin $ADMIN_EMAIL is ready; $BOOTSTRAP deleted"
fi

# --- Caches -------------------------------------------------------------------
step "cache"
artisan config:cache >/dev/null
artisan route:cache >/dev/null
artisan view:cache >/dev/null
info "config, routes and views cached"

# --- Publish the document root -------------------------------------------------
step "publish httpdocs"
cp -R "$ETHR_ROOT/httpdocs/." "$DOCROOT/"
info "$(find "$ETHR_ROOT/httpdocs" -type f | wc -l | tr -d ' ') files copied into $DOCROOT"

step "done"
info "check: https://<APP_DOMAIN>/api/v1/health"
