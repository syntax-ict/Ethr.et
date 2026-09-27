#!/usr/bin/env bash
#
# Deploy ETHR to Ethio Telecom Plesk shared hosting (Bronze — the tier confirmed on the subscription).
#
# The only deploy script now. scripts/deploy.sh drove the VPS compose stack and
# went with it on 2026-09-26
# and is untouched by this file. This one assumes the shared-hosting contract:
# no Docker, no systemd, no Supervisor, no root, no long-running daemon.
#
# Build happens HERE (or in CI). The host only receives artifacts and runs
# artisan. That split is deliberate: SSH is Forbidden on this account, so the
# only command channel is Plesk Git's "additional deployment actions", which
# fire on deploy and can run one-off work but nothing recurring.
#
# Usage:
#   scripts/shared-hosting/deploy.sh --dry-run     # print the plan, touch nothing
#   scripts/shared-hosting/deploy.sh --build-only  # build artifacts, no upload
#   scripts/shared-hosting/deploy.sh               # full run
#
set -euo pipefail

DRY_RUN=false
BUILD_ONLY=false
for arg in "$@"; do
  case "$arg" in
    --dry-run)    DRY_RUN=true ;;
    --build-only) BUILD_ONLY=true ;;
    -h|--help)    sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ARTIFACT_DIR="${ARTIFACT_DIR:-$REPO_ROOT/.deploy-artifacts}"
FRONTEND_MODE="${FRONTEND_MODE:-export}"

# Deliberately unset by default. The deploy refuses to run for real without
# them rather than inventing a target.
DEPLOY_HOST="${DEPLOY_HOST:-}"
DEPLOY_USER="${DEPLOY_USER:-}"
DEPLOY_PATH="${DEPLOY_PATH:-}"

step() { printf '\n\033[1m━━━ %s\033[0m\n' "$1"; }
info() { printf '  %s\n' "$1"; }
run()  {
  if [ "$DRY_RUN" = true ]; then printf '  [dry-run] %s\n' "$*"; else printf '  + %s\n' "$*"; "$@"; fi
}

step "0. Preconditions"

# REWRITTEN 2026-09-27. This block used to refuse FRONTEND_MODE=export outright,
# on the grounds that "the export DOES NOT BUILD TODAY, measured 2026-09-18 and
# re-confirmed 2026-09-22". That was true when written and stopped being true on
# 2026-09-26: unknown 3b is CLOSED (audit/BASELINE.md §19j). The four [id] routes
# are server-component wrappers returning one sentinel id, and useRouteId reads
# the real id from usePathname().
#
# Its checks had also gone vacuous rather than wrong — the `"use client"` test now
# passes because those page.tsx files are server components — so it would have
# waved the build through while its message told the operator the opposite.
#
# What replaces it is a check on the switch this script depends on, not a
# re-litigation of whether the export works. Whether it works is answered by
# building it, which step 2 now does through `npm run build:shared-hosting`, and
# by CI's `static-export` job on every pull request.
if [ "$FRONTEND_MODE" = "export" ]; then
  BLOCKERS=()
  grep -q 'SHARED_HOSTING_TARGET' "$REPO_ROOT/src/src/lib/build-target.ts" 2>/dev/null \
    || BLOCKERS+=("src/src/lib/build-target.ts does not declare SHARED_HOSTING_TARGET")
  grep -q 'IS_STATIC_EXPORT' "$REPO_ROOT/src/next.config.ts" 2>/dev/null \
    || BLOCKERS+=("src/next.config.ts does not switch \`output\` on IS_STATIC_EXPORT")
  grep -q 'build:shared-hosting' "$REPO_ROOT/src/package.json" 2>/dev/null \
    || BLOCKERS+=("src/package.json has no build:shared-hosting script")

  if [ ${#BLOCKERS[@]} -gt 0 ]; then
    echo
    echo "  REFUSING: the shared-hosting build switch is not intact."
    printf '    - %s\n' "${BLOCKERS[@]}"
    echo
    echo "  Without it, ETHR_TARGET is ignored and this script silently produces a"
    echo "  standalone build — which emits .next/, not out/, and cannot be served by"
    echo "  Apache. That exact defect shipped in this script until 2026-09-27."
    echo
    echo "  Re-run with FRONTEND_MODE=standalone for the Node-server variant. Note that"
    echo "  branch is NOT owner-selected: see docs/decisions/OWNER-DECISION-C5-FRONTEND-TARGET.md."
    [ "$DRY_RUN" = true ] && info "(dry-run: continuing so the rest of the plan is visible)" || exit 1
  fi
fi

if [ "$BUILD_ONLY" = false ] && [ "$DRY_RUN" = false ]; then
  for v in DEPLOY_HOST DEPLOY_USER DEPLOY_PATH; do
    [ -n "${!v}" ] || { echo "  REFUSING: $v is unset. This script will not guess a deploy target." >&2; exit 1; }
  done
fi
info "frontend mode : $FRONTEND_MODE"
info "artifact dir  : $ARTIFACT_DIR"
info "target        : ${DEPLOY_USER:-<unset>}@${DEPLOY_HOST:-<unset>}:${DEPLOY_PATH:-<unset>}"

step "1. Build backend artifacts (locally / in CI — never on the host)"
run mkdir -p "$ARTIFACT_DIR"
run composer install --working-dir="$REPO_ROOT/api" --no-dev --optimize-autoloader --no-interaction

step "2. Build the frontend ($FRONTEND_MODE)"
# FIXED 2026-09-27. This read:
#
#   run env NEXT_OUTPUT="$FRONTEND_MODE" npm --prefix "$REPO_ROOT/src" run build
#
# with the comment "NEXT_OUTPUT is read by next.config.ts only if wired". It was
# never wired, and nothing in src/ reads NEXT_OUTPUT. The variable next.config.ts
# switches on is ETHR_TARGET, via src/lib/build-target.ts — so this step produced a
# STANDALONE build under FRONTEND_MODE=export, and step 3 then tarred src/out,
# which a standalone build does not emit. The script would have failed at the tar
# with a missing-directory error that names nothing about the cause.
#
# `npm run build:shared-hosting` sets ETHR_TARGET itself and runs
# verify-static-export.mjs afterwards, so a wrong-target build cannot get past
# this step quietly. The standalone path keeps the plain build.
if [ "$FRONTEND_MODE" = "export" ]; then
  run npm --prefix "$REPO_ROOT/src" run build:shared-hosting
else
  run npm --prefix "$REPO_ROOT/src" run build
fi

step "3. Package"
run tar -czf "$ARTIFACT_DIR/api.tar.gz" -C "$REPO_ROOT" api
if [ "$FRONTEND_MODE" = "export" ]; then
  run tar -czf "$ARTIFACT_DIR/frontend.tar.gz" -C "$REPO_ROOT/src" out
else
  run tar -czf "$ARTIFACT_DIR/frontend.tar.gz" -C "$REPO_ROOT/src" .next
fi

[ "$BUILD_ONLY" = true ] && { step "Done (--build-only)"; exit 0; }

step "4. Upload"
# Plesk Git deployment is the PRIMARY channel per SHARED-HOSTING-CONTRACT.md.
# This rsync path is the OPTIONAL fallback and only works if SSH is ever
# granted — it is Forbidden today, which is why step 5 exists separately.
run rsync -az --delete "$ARTIFACT_DIR/" "${DEPLOY_USER}@${DEPLOY_HOST}:${DEPLOY_PATH}/artifacts/"

step "5. Post-deploy commands (Plesk Git 'additional deployment actions')"
# These run as the subscription user on deploy. They cover one-off install
# work. They CANNOT drive anything recurring — see cron.txt and G0-D.
# CORRECTED 2026-09-27. Two defects, both of which would have been pasted into a
# live deployment:
#
#   `storage:link` was here and must not be. ETHR never symlinks the public disk —
#   every file URL is a signed temporaryUrl() from FileStorageService — so the
#   symlink points at a storage/app/public that nothing writes to.
#   shared-hosting/DEPLOYMENT.md:308 and SHARED_HOSTING_PLAN.md §5.5 step 7 both
#   removed it on 2026-09-24 (the latter from seven places) and this script was
#   missed. Its own §"Correction 2026-09-24" says a full database.sql restore
#   assumed that symlink and "it will not exist".
#
#   `key:generate` and `ethr:create-admin` were MISSING, and they are the two that
#   make the difference between a deployed application and a booting one.
#   SHARED-HOSTING-CONTRACT.md names the one-off install set as
#   key:generate, migrate, db:seed, ethr:create-admin — and APP_KEY is not
#   optional: Employee.tin and national_id use the `encrypted` cast, and Laravel's
#   encrypter refuses to boot without a key. CI hit exactly this as cause 4 of the
#   five structural failures, 156 tests dying with MissingAppKeyException.
#
# Order is load-bearing: key:generate before migrate, because the migration writes
# encrypted columns; caches last, because they capture config that must be final.
cat <<'ACTIONS'
  cd ~/ethr/api && php artisan key:generate --force
  cd ~/ethr/api && php artisan migrate --force
  cd ~/ethr/api && php artisan ethr:create-admin
  cd ~/ethr/api && php artisan config:cache
  cd ~/ethr/api && php artisan route:cache
  cd ~/ethr/api && php artisan view:cache
ACTIONS
echo
echo "  NOT in that list, deliberately:"
echo "    storage:link  — ETHR uses signed temporaryUrl(); the symlink would be broken."
echo "    db:seed       — optional, and DemoTenantSeeder needs faker (a require-dev package)."
echo
echo "  migrate WILL ABORT if CREATE TRIGGER is denied. That is by design — the audit-log"
echo "  trigger is the only control that survives a mass update. Measure the grant first"
echo "  (probe DB4, manual queue M2); if denied, request it. Do not soften the migration."
info "(printed, not executed: this script has no shell on the host)"

step "6. Smoke check"
info "scripts/shared-hosting/smoke-check.sh https://ethr.et"

step "Done"
