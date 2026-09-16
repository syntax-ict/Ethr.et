#!/usr/bin/env bash
#
# API contract drift gate.
#
# `src/src/api/generated.ts` is the compiled contract between the Laravel API and
# the Next.js frontend: every response type the frontend trusts comes from it. It
# is generated from the live routes, so it only stays true if someone remembers to
# re-run `npm run generate:api` after changing a response shape. Nobody remembers.
#
# The failure is silent — tsc happily checks the frontend against a stale contract
# and passes. Earlier this repo had `id` renamed to `public_id` on three resources
# with no type change; the frontend kept compiling against a field the API had
# stopped sending.
#
# This regenerates into a temp directory and diffs against the committed file.
# Both steps are verified deterministic (identical bytes across consecutive runs),
# so a difference means a real drift, not generator noise.
#
# Fix a failure with:  cd src && npm run generate:api

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$REPO_ROOT/api"
WEB_DIR="$REPO_ROOT/src"
COMMITTED="$WEB_DIR/src/api/generated.ts"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# The export runs wherever PHP actually is. On the documented Windows/Docker
# Desktop setup there is no native `php` — it exists only in the api container —
# and this gate hard-failed on `php: command not found` while printing the
# "database is not reachable" advice below. That is the same misdiagnosis this
# script's own header warns about, and it cost the contract real drift: the gate
# was unrunnable, so 14 endpoint groups were added without regenerating.
#
# Unlike pest and phpstan, this needs no off-mount copy. Scramble enumerates
# routes through the router (routes/api.php, a single file), not by scanning a
# directory, so it is untouched by the lossy bind mount — verified by exporting
# on and off the mount and getting byte-identical specs.
CONTAINER="${ETHR_API_CONTAINER:-et-api-1}"

if command -v php >/dev/null 2>&1; then
    RUN_EXPORT() { (cd "$API_DIR" && php -d memory_limit=512M artisan scramble:export --path="$1" 2>&1); }
    COPY_SPEC() { :; }
elif docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$CONTAINER"; then
    echo "No native php; exporting inside $CONTAINER."
    RUN_EXPORT() { docker exec "$CONTAINER" sh -c "cd /var/www/api && php -d memory_limit=512M artisan scramble:export --path=/tmp/api-types-check.json" 2>&1; }
    COPY_SPEC() { docker cp "$CONTAINER:/tmp/api-types-check.json" "$1" >/dev/null; }
else
    echo "✗ no way to run the export: no native php, and container $CONTAINER is not running."
    echo "  Start the stack with:  docker compose up -d"
    exit 1
fi

# memory_limit: the export needs more than PHP's default 128M (256M is the floor).
#
# Do NOT silence this. scramble:export introspects the live database schema to
# type API responses, so its usual failure is a QueryException from an
# unreachable DB — and swallowing that is what produced, and then sustained for
# weeks, a phantom "the export OOMs at >4 GB" diagnosis in the roadmap. It does
# not OOM; it needs a database.
if ! RUN_EXPORT "$TMP/openapi.json"; then
    echo ""
    echo "✗ could not export the OpenAPI spec (scramble:export failed above)"
    echo "  If the error above is a QueryException, the database is not reachable:"
    echo "    docker compose up -d mariadb"
    echo "  Confirm the schema is actually populated — an empty or partially"
    echo "  migrated database does NOT fail the export, it silently produces a"
    echo "  degraded contract. See docs/ETHR_AUDIT_2026-08-14.md."
    exit 1
fi

COPY_SPEC "$TMP/openapi.json"

if ! (cd "$WEB_DIR" && node node_modules/openapi-typescript/bin/cli.js "$TMP/openapi.json" -o "$TMP/generated.ts" >/dev/null 2>&1); then
    echo "✗ could not generate TypeScript types from the spec"
    exit 1
fi

if diff -q "$TMP/generated.ts" "$COMMITTED" >/dev/null 2>&1; then
    echo "API types in sync with the live routes."
    exit 0
fi

echo "✗ src/api/generated.ts is stale — the API has changed since it was generated."
echo ""
diff "$COMMITTED" "$TMP/generated.ts" | head -40
echo ""
echo "Regenerate with:  cd src && npm run generate:api"
exit 1
