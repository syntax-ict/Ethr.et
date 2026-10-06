#!/usr/bin/env bash
#
# Rehearse a Plesk deploy of a built release tree, twice, against a throwaway
# host layout — and assert the things that would be expensive to learn live:
#
#   1. a missing .env stops the deploy and publishes nothing;
#   2. the first deploy generates APP_KEY, migrates, creates the super admin
#      from the one-shot file and deletes that file, and publishes httpdocs;
#   3. the SECOND deploy leaves APP_KEY byte-identical — the defect the old
#      action list (`key:generate --force` on every deploy) would have shipped;
#   4. httpdocs/.well-known (ACME) survives a publish.
#
# SQLite stands in for the host's MySQL, so this proves the script's control
# flow and idempotence, not the host's database grants (G0-F) — that is M2.
#
# Usage: scripts/shared-hosting/rehearse-release.sh <release-dir>
#
set -euo pipefail

REL="$(cd "${1:?usage: rehearse-release.sh <release-dir>}" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok   %s\n' "$1"; }

HOST="$WORK/home"
mkdir -p "$HOST/httpdocs/.well-known/acme-challenge"
echo token > "$HOST/httpdocs/.well-known/acme-challenge/keep"
cp -R "$REL" "$HOST/ethr"

# 1 — no .env: must stop, must not publish.
if bash "$HOST/ethr/deploy/post-deploy.sh" >"$WORK/out0" 2>&1; then
  fail "post-deploy succeeded without api/.env"
fi
grep -q 'api/.env does not exist' "$WORK/out0" || fail "missing-.env message not shown"
[ ! -f "$HOST/httpdocs/index.php" ] || fail "httpdocs was published without a .env"
pass "a deploy without .env stops and publishes nothing"

# The owner's File Manager step: .env from the template, APP_KEY empty.
ENV="$HOST/ethr/api/.env"
cp "$HOST/ethr/api/.env.shared-hosting.example" "$ENV"
touch "$WORK/db.sqlite"
# APP_KEY is deliberately left as the template ships it — `APP_KEY=   # REQUIRED`
# — because that trailing comment is what a naive "is there a key?" check
# mistakes for one.
grep -qE '^APP_KEY=[[:space:]]+#' "$ENV" || fail "the template's APP_KEY line changed shape; update this rehearsal"
# Git Bash's /tmp is invisible to a native Windows PHP; give it a mixed path.
DB_FILE="$WORK/db.sqlite"
command -v cygpath >/dev/null 2>&1 && DB_FILE="$(cygpath -m "$DB_FILE")"
sed -i -e 's#^DB_CONNECTION=.*#DB_CONNECTION=sqlite#' \
       -e "s#^DB_DATABASE=.*#DB_DATABASE=$DB_FILE#" "$ENV"
grep -q '^DB_CONNECTION=sqlite' "$ENV" || fail "could not set DB_CONNECTION in the rehearsal .env"
grep -q "^DB_DATABASE=$DB_FILE" "$ENV" || fail "could not set DB_DATABASE in the rehearsal .env"
printf 'ADMIN_EMAIL=owner@example.et\nADMIN_PASSWORD=Rehearsal-Passw0rd-123\n' > "$HOST/ethr/admin-bootstrap.env"

# 2 — first deploy.
bash "$HOST/ethr/deploy/post-deploy.sh" >"$WORK/out1" 2>&1 || { cat "$WORK/out1"; fail "first deploy failed"; }
KEY1="$(grep '^APP_KEY=' "$ENV")"
grep -qE '^APP_KEY=base64:' "$ENV" || fail "APP_KEY was not generated: $KEY1"
[ ! -f "$HOST/ethr/admin-bootstrap.env" ] || fail "admin-bootstrap.env was not deleted"
grep -q 'super admin owner@example.et is ready' "$WORK/out1" || fail "super admin not created"
[ -f "$HOST/httpdocs/index.php" ] && [ -f "$HOST/httpdocs/.htaccess" ] && [ -f "$HOST/httpdocs/index.html" ] \
  || fail "httpdocs was not published"
[ -f "$HOST/ethr/api/bootstrap/cache/config.php" ] || fail "config was not cached"
[ -f "$HOST/httpdocs/.user.ini" ] || fail "httpdocs/.user.ini (the PHP limits) was not published"
pass "first deploy: key generated, migrated, admin created and file deleted, docroot published"

# 3 — second deploy: the key must not move.
bash "$HOST/ethr/deploy/post-deploy.sh" >"$WORK/out2" 2>&1 || { cat "$WORK/out2"; fail "second deploy failed"; }
KEY2="$(grep '^APP_KEY=' "$ENV")"
[ "$KEY1" = "$KEY2" ] || fail "APP_KEY CHANGED on the second deploy — encrypted columns would be lost"
grep -q 'APP_KEY present' "$WORK/out2" || fail "second deploy did not report the key as kept"
pass "second deploy: APP_KEY unchanged"

# 4 — ACME survives.
[ "$(cat "$HOST/httpdocs/.well-known/acme-challenge/keep")" = token ] || fail ".well-known was disturbed"
pass ".well-known survived both publishes"

echo "rehearsal passed"
