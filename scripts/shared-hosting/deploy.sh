#!/usr/bin/env bash
#
# Deploy ETHR to Ethio Telecom Plesk shared hosting (Linux Gold).
#
# NOT scripts/deploy.sh — that one drives docker-compose.prod.yml on the VPS
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

# The chosen frontend mode is a static export (SHARED_HOSTING_PLAN.md §3): it
# needs no custom nginx directives, which is exactly what G0-A cannot supply.
# But the export DOES NOT BUILD TODAY, measured 2026-09-18 and re-confirmed
# 2026-09-22. Refuse loudly here rather than fail three steps later with a
# Next.js stack trace that does not name the cause.
if [ "$FRONTEND_MODE" = "export" ]; then
  BLOCKERS=()
  grep -q 'force-static' "$REPO_ROOT/src/src/app/manifest.ts" 2>/dev/null \
    || BLOCKERS+=("src/src/app/manifest.ts has no \`export const dynamic = 'force-static'\`")
  while IFS= read -r f; do
    head -1 "$f" | grep -q '"use client"' \
      && BLOCKERS+=("${f#"$REPO_ROOT"/} is \"use client\" — Next forbids combining that with generateStaticParams")
  done < <(find "$REPO_ROOT/src/src/app" -name 'page.tsx' -path '*[[]*' 2>/dev/null | sort)

  if [ ${#BLOCKERS[@]} -gt 0 ]; then
    echo
    echo "  REFUSING: FRONTEND_MODE=export cannot build in this repository yet."
    printf '    - %s\n' "${BLOCKERS[@]}"
    echo
    echo "  Each [id] route's ids are TENANT DATA, so generateStaticParams can only"
    echo "  return [] and every real /employees/123 would 404. These routes need a"
    echo "  server-component split plus client-side routing first — an application"
    echo "  change, tracked in SHARED_HOSTING_AUDIT.md §E, not something this"
    echo "  deploy script may paper over."
    echo
    echo "  Re-run with FRONTEND_MODE=standalone to build the Node-server variant"
    echo "  (needs Plesk Node.js enabled AND G0-A routing, both unresolved)."
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
# NEXT_OUTPUT is read by next.config.ts only if wired; default stays
# "standalone" so docker/frontend/Dockerfile is unaffected either way.
run env NEXT_OUTPUT="$FRONTEND_MODE" npm --prefix "$REPO_ROOT/src" run build

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
cat <<'ACTIONS'
  cd ~/ethr/api && php artisan migrate --force
  cd ~/ethr/api && php artisan config:cache
  cd ~/ethr/api && php artisan route:cache
  cd ~/ethr/api && php artisan view:cache
  cd ~/ethr/api && php artisan storage:link
ACTIONS
info "(printed, not executed: this script has no shell on the host)"

step "6. Smoke check"
info "scripts/shared-hosting/smoke-check.sh https://ethr.et"

step "Done"
