#!/usr/bin/env bash
#
# Fetch every path shape the Bronze rules distinguish, against the local rehearsal.
#
#   scripts/local-production/verify.sh          # PORT=8081 by default
#
# THIS IS NOT HOST VERIFICATION. It runs against MY Apache under `AllowOverride All`
# — the permissive case — so it cannot answer G0-B.6, and it is not evidence for M1,
# M2, G0-F, M6, M3, G0-H, G0-I, G0-J or quotas either. Every one of those stays
# exactly where `docs/deployment/CUTOVER-CHECKLIST.md` has it.
#
# What it IS: the first end-to-end exercise of the assembled document root. §21d
# served the export under these rules but against a stub index.php with no
# application behind it; this has Laravel, MariaDB and the migrated schema behind it.
#
# The matrix is §21d's, plus the six sibling routes §22b found broken — because a
# 20-of-20 pass that never fetched /employees/new is how that defect survived.

set -uo pipefail

PORT="${PORT:-8081}"
BASE="http://localhost:$PORT"
ADMIN_HOST="${ADMIN_HOST:-admin.localhost:8081}"
TENANT_HOST="${TENANT_HOST:-acme.localhost:8081}"

pass=0; fail=0

# $1 label · $2 expected (regex on the status) · $3.. curl args
check() {
    local label="$1" want="$2"; shift 2
    local code
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$@" || echo 000)"

    if [[ "$code" =~ ^$want$ ]]; then
        printf '  \033[32mok\033[0m    %-52s %s\n' "$label" "$code"; pass=$((pass+1))
    else
        printf '  \033[31mFAIL\033[0m  %-52s %s (want %s)\n' "$label" "$code" "$want"; fail=$((fail+1))
    fi
}

# Assert a response body contains something, not merely that it answered.
contains() {
    local label="$1" needle="$2"; shift 2
    local body
    body="$(curl -s --max-time 15 "$@" || true)"

    if printf '%s' "$body" | grep -q -- "$needle"; then
        printf '  \033[32mok\033[0m    %-52s contains %s\n' "$label" "$needle"; pass=$((pass+1))
    else
        printf '  \033[31mFAIL\033[0m  %-52s missing %s\n' "$label" "$needle"; fail=$((fail+1))
        printf '        got: %s\n' "$(printf '%s' "$body" | head -c 160 | tr '\n' ' ')"
    fi
}

printf '\n\033[1mLocal production rehearsal — %s\033[0m\n' "$BASE"

printf '\n\033[1m1 · the exported site\033[0m\n'
check "/ (marketing root)"                    "200|30[0-9]" "$BASE/"
check "/en/pricing"                           "200|30[0-9]" "$BASE/en/pricing"
check "/login"                                "200"         "$BASE/login"
check "/dashboard"                            "200"         "$BASE/dashboard"
check "/dashboard/ (trailing slash)"          "200"         "$BASE/dashboard/"

printf '\n\033[1m2 · entity routes — one shell per route, id read from the URL\033[0m\n'
check "/employees/<ULID>"                     "200" "$BASE/employees/01HQZX3NDJKMNP4RSTVWXY5Z6A"
check "/payroll/<ULID>"                       "200" "$BASE/payroll/01HQZX3NDJKMNP4RSTVWXY5Z6A"
check "/devices/<ULID>"                       "200" "$BASE/devices/01HQZX3NDJKMNP4RSTVWXY5Z6A"

printf '\n\033[1m3 · static siblings under a dynamic prefix — the §22b defect\033[0m\n'
# Each of these was served the entity-detail shell until 2026-09-27, so a 200 proves
# nothing — the BODY has to be the sibling's own page.
#
# Compared BYTE-EXACT against the file on disk, not against the shell by prefix. The
# first draft took `head -c 400` of each and compared: every Next.js export opens with
# the same <head> scaffolding, so it reported all six as regressions when all six were
# correct. A check that cannot distinguish what it claims to test is the emulator
# passing 13/13 one level up.
# The host's document root is ethr/api/public (2026-10-06), so the rehearsal's is
# the release's api/public too.
DOCROOT_DIR="${DOCROOT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/.local-production/app/api/public}"

for r in employees/new employees/import payroll/loans payroll/payslips payroll/cost-sharing devices/dashboard; do
    want="$DOCROOT_DIR/$r.html"
    shell="$DOCROOT_DIR/${r%%/*}/__id__.html"
    got="$(mktemp)"
    curl -s --max-time 15 -o "$got" "$BASE/$r" || true

    if [ ! -s "$got" ]; then
        printf '  \033[31mFAIL\033[0m  %-52s empty response\n' "/$r"; fail=$((fail+1))
    elif cmp -s "$got" "$want"; then
        printf '  \033[32mok\033[0m    %-52s its own page (byte-identical)\n' "/$r"; pass=$((pass+1))
    elif cmp -s "$got" "$shell"; then
        printf '  \033[31mFAIL\033[0m  %-52s served the ENTITY SHELL (§22b regression)\n' "/$r"; fail=$((fail+1))
    else
        printf '  \033[31mFAIL\033[0m  %-52s neither its page nor the shell\n' "/$r"; fail=$((fail+1))
    fi
    rm -f "$got"
done

# And the converse: a real id must get the shell, byte-identical. §21d measured this.
got="$(mktemp)"
curl -s --max-time 15 -o "$got" "$BASE/employees/01HQZX3NDJKMNP4RSTVWXY5Z6A" || true
if cmp -s "$got" "$DOCROOT_DIR/employees/__id__.html"; then
    printf '  \033[32mok\033[0m    %-52s byte-identical to __id__.html\n' "/employees/<ULID>"; pass=$((pass+1))
else
    printf '  \033[31mFAIL\033[0m  %-52s not the sentinel shell\n' "/employees/<ULID>"; fail=$((fail+1))
fi
rm -f "$got"

printf '\n\033[1m4 · Laravel through .htaccess — no proxy directive\033[0m\n'
check "/api/v1/health"                        "200|503"     "$BASE/api/v1/health"
contains "/api/v1/health is JSON from PHP"    "status"      "$BASE/api/v1/health"
check "/api/v1/plans (public endpoint)"       "200"         "$BASE/api/v1/plans"
check "/sanctum/csrf-cookie"                  "204|200"     "$BASE/sanctum/csrf-cookie"
check "/apixyz does NOT over-match"           "404"         "$BASE/apixyz"

printf '\n\033[1m5 · the deny rules — [F,L], the M1 mechanism\033[0m\n'
# On the host this is M1 and it is UNMEASURED. Here it says the rule is correct as
# written, which is all §21e claims for the local harness.
check "/.env"                                 "403" "$BASE/.env"
check "/composer.json"                        "403" "$BASE/composer.json"
check "/artisan"                              "403" "$BASE/artisan"
check "/storage/anything"                     "403" "$BASE/storage/secret.txt"
# .user.ini sits in the document root on purpose (the PHP limits) and is config.
check "/.user.ini"                            "403" "$BASE/.user.ini"

printf '\n\033[1m6 · the /admin host boundary — routing, not authorization\033[0m\n'
check "/admin from a tenant host"             "403" -H "Host: $TENANT_HOST" "$BASE/admin"
check "/admin from the admin host"            "200" -H "Host: $ADMIN_HOST"  "$BASE/admin"
check "/dashboard on the admin host redirects" "302" -H "Host: $ADMIN_HOST" "$BASE/dashboard"

printf '\n\033[1m7 · 404 is a real 404, not a rewritten 200\033[0m\n'
check "/nope"                                 "404" "$BASE/nope"

printf '\n\033[1m8 · security headers\033[0m\n'
hdrs="$(curl -sI --max-time 15 "$BASE/login" || true)"
for h in X-Frame-Options X-Content-Type-Options Strict-Transport-Security \
         Referrer-Policy Permissions-Policy Content-Security-Policy; do
    if printf '%s' "$hdrs" | grep -qi "^$h:"; then
        printf '  \033[32mok\033[0m    %-52s present\n' "$h"; pass=$((pass+1))
    else
        printf '  \033[31mFAIL\033[0m  %-52s absent\n' "$h"; fail=$((fail+1))
    fi
done
if printf '%s' "$hdrs" | grep -qi "script-src 'self' 'unsafe-inline'"; then
    printf '  \033[32mok\033[0m    %-52s present\n' "CSP allows the un-nonced inline scripts"; pass=$((pass+1))
else
    printf '  \033[31mFAIL\033[0m  %-52s the export will not hydrate\n' "CSP script-src"; fail=$((fail+1))
fi

swh="$(curl -sI --max-time 15 "$BASE/sw.js" || true)"
if printf '%s' "$swh" | grep -qi '^Cache-Control:.*no-cache' && ! printf '%s' "$swh" | grep -qi 'immutable'; then
    printf '  \033[32mok\033[0m    %-52s no-cache, not immutable\n' "/sw.js caching"; pass=$((pass+1))
else
    printf '  \033[31mFAIL\033[0m  %-52s %s\n' "/sw.js caching" "$(printf '%s' "$swh" | grep -i '^Cache-Control' | tr -d '\r')"; fail=$((fail+1))
fi

printf '\n\033[1m9 · the application root is not web-reachable\033[0m\n'
# With the document root at api/public, .env is ONE level up: the closest it has
# ever been. Encoded and plain traversal must both fail to reach it.
check "/../.env via traversal"                "40[0-9]" --path-as-is "$BASE/../.env"
check "/%2e%2e/.env via encoded traversal"    "40[0-9]" --path-as-is "$BASE/%2e%2e/.env"
contains "/api/v1/ping answers from api/public" '"status":"ok"' "$BASE/api/v1/ping"

printf '\n\033[1m━━━ %d passed, %d failed\033[0m\n' "$pass" "$fail"

cat <<'EOF'

  This is LOCAL VERIFIED and nothing more. It runs under AllowOverride All — the
  permissive case — so it cannot answer G0-B.6, and it is not evidence for M1, M2,
  G0-F, M6, M3, G0-H, G0-I, G0-J or quotas.

  ./scripts/gates.sh evidence still lists all eleven host gates as outstanding.
EOF

[ "$fail" -eq 0 ]
