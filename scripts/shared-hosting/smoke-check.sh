#!/usr/bin/env bash
#
# Post-deploy smoke check for ETHR on Plesk shared hosting.
# Read-only: it asserts, it never writes or migrates.
#
#   scripts/shared-hosting/smoke-check.sh https://ethr.et
#
set -uo pipefail

BASE="${1:-${APP_URL:-}}"
[ -n "$BASE" ] || { echo "usage: $0 <base-url>" >&2; exit 2; }
API_DIR="${API_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../api" && pwd)}"

PASS=0; FAIL=0
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
bad()  { printf '  \033[31m✗\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
note() { printf '    %s\n' "$1"; }

printf '\n\033[1m━━━ 1. Health endpoint\033[0m\n'
CODE=$(curl -sS -o /tmp/ethr-health.$$ -w '%{http_code}' --max-time 20 "$BASE/api/v1/health" 2>/dev/null || echo 000)
if [ "$CODE" = "200" ]; then ok "GET /api/v1/health -> 200"; else bad "GET /api/v1/health -> $CODE (expected 200)"; fi
[ -s /tmp/ethr-health.$$ ] && note "body: $(head -c 200 /tmp/ethr-health.$$)"
rm -f /tmp/ethr-health.$$

printf '\n\033[1m━━━ 2. TLS on the served host\033[0m\n'
if [[ "$BASE" == https://* ]]; then
  if curl -sS --max-time 20 -o /dev/null "$BASE/api/v1/health" 2>/dev/null; then
    ok "TLS certificate validates for $BASE"
  else
    bad "TLS validation failed for $BASE"
  fi
else
  bad "base URL is not https — SESSION_SECURE_COOKIE=true withholds auth cookies over http"
fi

printf '\n\033[1m━━━ 3. Database connectivity\033[0m\n'
DB_OUT=$(php "$API_DIR/artisan" db:show --json 2>/dev/null) && DB_RC=0 || DB_RC=$?
if [ "${DB_RC:-1}" -eq 0 ]; then
  DRIVER=$(printf '%s' "$DB_OUT" | sed -n 's/.*"driver" *: *"\([^"]*\)".*/\1/p' | head -1)
  ok "artisan db:show connected (driver: ${DRIVER:-unknown})"
else
  bad "artisan db:show could not connect"
fi

printf '\n\033[1m━━━ 4. Encryption in transit to the database\033[0m\n'
# DECISION DB-1 (SHARED_HOSTING_PLAN.md §0) puts MySQL on the plan at
# DB_HOST=localhost, so there is no network hop to encrypt and no external 5432
# to reach — gap-table row #6 is retired on that basis. This check therefore
# REPORTS the shape rather than asserting TLS unconditionally, which would fail
# a correct localhost deploy.
#
# It is kept rather than deleted precisely because the decision could be
# revisited: point DB_HOST at anything non-loopback and the assertion below
# turns back on and refuses to pass without TLS evidence.
DB_HOST_VAL=$(grep -E '^DB_HOST=' "$API_DIR/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'"'"' ')
case "${DB_HOST_VAL:-}" in
  ""|localhost|127.0.0.1|::1)
    ok "DB_HOST=${DB_HOST_VAL:-<unset>} — loopback, no network hop to encrypt"
    note "if this ever becomes an external host, TLS becomes REQUIRED and is unverified: G0 has no row for it" ;;
  *)
    note "DB_HOST=$DB_HOST_VAL is remote — TLS is required on this hop"
    if php "$API_DIR/artisan" db:show --json 2>/dev/null | grep -qi 'ssl\|tls'; then
      ok "connection reports SSL/TLS"
    else
      bad "remote DB_HOST with no TLS evidence — refuse to ship this"
    fi ;;
esac

printf '\n\033[1m━━━ 5. Tenant isolation (the actual control)\033[0m\n'
# NO RLS CHECK HERE, AND THAT IS SETTLED RATHER THAN OMITTED.
#
# The deploy brief asked this step to assert "RLS active for ethr_app". That
# premise was tested repo-wide and did not hold, and the question is now closed
# by DECISION DB-1 in SHARED_HOSTING_PLAN.md §0: MySQL/MariaDB on the plan, no
# external PostgreSQL. The audit found zero policies, zero ENABLE ROW LEVEL
# SECURITY, no ethr_app role anywhere in the repository, and no test asserting
# isolation below the query builder.
#
# Isolation is an Eloquent global scope — api/app/Traits/BelongsToTenant.php:17-23,
# `where tenant_id = ?`, falling back to `whereRaw('0 = 1')` when no tenant is
# resolved. MySQL has no RLS feature regardless; it is PostgreSQL-only.
#
# A check named "RLS active" would therefore either always fail or pass while
# testing nothing. This asserts the control that actually exists.
ISO=$(php "$API_DIR/artisan" tinker --execute '
    $m = new \App\Models\Employee;
    echo in_array(\App\Traits\BelongsToTenant::class, class_uses_recursive($m)) ? "trait:yes" : "trait:no";
    echo " ";
    echo $m->newQuery()->toSql();
' 2>/dev/null)
case "$ISO" in
  *trait:yes*) ok "Employee uses BelongsToTenant" ;;
  *)           bad "Employee does not use BelongsToTenant — isolation is not in force" ;;
esac
case "$ISO" in
  *"0 = 1"*)   ok "unresolved-tenant query fails closed (contains \`0 = 1\`)" ;;
  *tenant_id*) ok "query is tenant-scoped (contains \`tenant_id\`)" ;;
  *)           bad "global scope applied neither a tenant predicate nor the fail-closed guard"
               note "got: ${ISO:-<no output>}" ;;
esac

printf '\n\033[1m━━━ summary\033[0m\n'
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ]
