#!/usr/bin/env bash
#
# Build the Plesk release tree: exactly what the `production` branch carries and
# what Plesk Git deploys into <APP_ROOT> (`~/ethr/`).
#
#   <out>/
#     api/          Laravel, with vendor/ (--no-dev), without tests/ or any .env
#       public/     THE DOCUMENT ROOT: Laravel's own index.php, the static export,
#                   the rendered .htaccess and .user.ini
#     RELEASE       which commit this tree was built from
#
# THE LAYOUT (owner decision 2026-10-06). The site's document root is
# <APP_ROOT>/api/public, the standard Laravel shape: .env, vendor/ and storage/
# sit one level above it and are never served. The layout follows from the
# install route. The Plesk Git deployment action runs in a chrooted shell with
# no PHP (measured on the first host deploy, 2026-10-06), so artisan runs from
# Plesk's Laravel Toolkit. The Toolkit only discovers an application whose
# `public/` is the document root with `artisan` above it.
#
# WHY A BUILT BRANCH. The account cannot build anything: no Node application
# (C-5) and no PHP in the deployment shell. Plesk Git can only pull a branch,
# so the branch it pulls must already be the release. CI builds it
# (.github/workflows/release.yml), and the host only receives it.
#
# The tree is built from COMMITTED files (`git archive HEAD`), never from the
# working directory, so a developer's api/.env, local caches or uncommitted
# edits cannot reach a release. The verification at the end checks that
# instead of assuming it.
#
# Usage:
#   ETHR_ADMIN_HOST=admin.<APP_DOMAIN> scripts/shared-hosting/build-release.sh <out-dir>
#
# Needs: git, php 8.2+, composer, node + `npm ci` already run in src/.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${1:?usage: build-release.sh <out-dir>}"
ADMIN_HOST="${ETHR_ADMIN_HOST:?ETHR_ADMIN_HOST is required — the platform console host, e.g. admin.<APP_DOMAIN>. It has no default on purpose: render-htaccess.php denies /admin on every other host.}"

step() { printf '\n\033[1m━━━ %s\033[0m\n' "$1"; }
info() { printf '  %s\n' "$1"; }
die()  { printf '  REFUSING: %s\n' "$1" >&2; exit 1; }

case "$OUT" in
  ""|"/"|"$REPO_ROOT"|"$REPO_ROOT/") die "refusing to build into '$OUT'" ;;
esac

rm -rf "$OUT"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
PUBLIC="$OUT/api/public"

SHA="$(git -C "$REPO_ROOT" rev-parse HEAD)"

step "1. Backend — committed api/ at ${SHA:0:12}, vendor without dev packages"
git -C "$REPO_ROOT" archive --format=tar HEAD api | tar -xf - -C "$OUT"
rm -rf "$OUT/api/tests" "$OUT/api/phpunit.xml" "$OUT/api/phpstan.neon" "$OUT/api/phpstan-baseline.neon"
# --no-scripts is NOT used: package:discover writes bootstrap/cache/packages.php,
# which is portable and saves the host a step. It needs no .env since
# config/broadcasting.php defaults to `null` (2026-09-25).
composer install --working-dir="$OUT/api" --no-dev --optimize-autoloader \
  --no-interaction --no-progress --quiet
mkdir -p "$OUT/api/storage/framework/"{cache/data,sessions,views} \
         "$OUT/api/storage/logs" "$OUT/api/storage/app/private" "$OUT/api/bootstrap/cache"

step "2. Frontend — static export (C-5)"
npm --prefix "$REPO_ROOT/src" run build:shared-hosting
[ -f "$REPO_ROOT/src/out/index.html" ] || die "src/out/index.html is missing — the export did not build"

step "3. Document root — api/public"
# Laravel's own robots.txt is replaced, not merged: it allows everything, while
# the export's names the sitemap and is the one written for this site
# (DEPLOYMENT.md §4a, "What is deliberately not copied"). The export ships its
# own favicon too. index.php is Laravel's, UNMODIFIED: in this layout its
# '/../' paths already point at api/.
rm -f "$PUBLIC/robots.txt"
cp -R "$REPO_ROOT/src/out/." "$PUBLIC/"

# Laravel's stock public/.htaccess is replaced by the rendered deployment rules.
php "$REPO_ROOT/scripts/shared-hosting/render-htaccess.php" \
  --target=static-export --admin-host="$ADMIN_HOST" -o "$PUBLIC/.htaccess"
info "admin host: $ADMIN_HOST"

# PHP limits: the panel shows them read-only on this account, and .user.ini is
# the override it names. See scripts/shared-hosting/user.ini.
cp "$REPO_ROOT/scripts/shared-hosting/user.ini" "$PUBLIC/.user.ini"

cat > "$OUT/RELEASE" <<EOF
commit=$SHA
built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
admin_host=$ADMIN_HOST
EOF

step "4. Verify the tree rather than trusting the steps"
# A credential file in a release is published: the production branch is in a
# public repository. Same check deploy.sh makes on its tarball.
LEAK="$(find "$OUT" \( -name '.env' -o -name '.env.local' -o -name '.env.production' -o -name '.env.backup' -o -name 'auth.json' \) -print)"
[ -z "$LEAK" ] || die "the release contains credential files: $LEAK"
# A config cache built here would freeze CI's environment into production.
[ ! -e "$OUT/api/bootstrap/cache/config.php" ] || die "bootstrap/cache/config.php was built into the release"
[ ! -e "$OUT/api/bootstrap/cache/routes-v7.php" ] || die "a route cache was built into the release"
[ -f "$OUT/api/vendor/autoload.php" ] || die "vendor/autoload.php is missing"
[ -f "$OUT/api/artisan" ] || die "api/artisan is missing — the Laravel Toolkit discovers the app by it"
[ ! -d "$OUT/api/vendor/pestphp" ] || die "dev packages are installed (pestphp) — composer ran without --no-dev"
[ ! -d "$OUT/api/tests" ] || die "api/tests is in the release"
cmp -s "$REPO_ROOT/api/public/index.php" "$PUBLIC/index.php" || die "api/public/index.php differs from the repository's; nothing should rewrite it"
[ -f "$PUBLIC/index.html" ] || die "api/public/index.html is missing"
[ -f "$PUBLIC/.htaccess" ] || die "api/public/.htaccess is missing"
grep -q '^#@' "$PUBLIC/.htaccess" && die "api/public/.htaccess still has unrendered #@ lines"
grep -qF '\.user\.ini' "$PUBLIC/.htaccess" || die "api/public/.htaccess does not deny .user.ini"
grep -q '^memory_limit = 256M' "$PUBLIC/.user.ini" 2>/dev/null || die "api/public/.user.ini is missing or lacks the memory_limit floor"
# Nothing above the document root may be reachable from it.
[ ! -e "$PUBLIC/.env.shared-hosting.example" ] || die "an env template landed in the document root"

info "files: $(find "$OUT" -type f | wc -l | tr -d ' ')  size: $(du -sh "$OUT" | cut -f1)"
info "release tree ready: $OUT"
