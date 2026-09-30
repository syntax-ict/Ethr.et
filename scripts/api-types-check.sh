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

# The export runs on native PHP. A Docker-container fallback lived here until
# 2026-09-30, when the Docker development stack was removed; CI and the
# XAMPP-based local setup (scripts/local-production/) both have native php.
#
# Scramble enumerates routes through the router (routes/api.php, a single
# file), not by scanning a directory, so no filesystem quirk affects it.
if ! command -v php >/dev/null 2>&1; then
    echo "✗ no php on PATH — install PHP 8.2+ natively (on Windows, XAMPP)."
    exit 1
fi
RUN_EXPORT() { (cd "$API_DIR" && php -d memory_limit=512M artisan scramble:export --path="$1" 2>&1); }

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
    echo "    start MariaDB (XAMPP), and check DB_* in api/.env"
    echo "  Confirm the schema is actually populated — an empty or partially"
    echo "  migrated database does NOT fail the export, it silently produces a"
    echo "  degraded contract. See docs/ETHR_AUDIT_2026-08-14.md."
    exit 1
fi


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
