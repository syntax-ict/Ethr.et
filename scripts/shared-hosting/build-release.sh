#!/usr/bin/env bash
#
# Build the Plesk release tree: exactly what the `production` branch carries and
# what Plesk Git deploys into <APP_ROOT> (`~/ethr/`).
#
#   <out>/
#     api/          Laravel, with vendor/ (--no-dev), without tests/ or any .env
#     httpdocs/     the assembled document root: static export + index.php + .htaccess
#     deploy/       post-deploy.sh — the one Plesk "additional deployment action"
#     RELEASE       which commit this tree was built from
#
# WHY A BUILT BRANCH. The account has no shell, no Node application (C-5) and no
# guaranteed Composer, so nothing can be built ON the host. Plesk Git can only
# pull a branch. So the branch it pulls must already be the release: vendor/
# installed, the export built, index.php repointed and .htaccess rendered. CI
# builds it (.github/workflows/release.yml); the host only copies and migrates.
#
# The tree is built from COMMITTED files (`git archive HEAD`), never from the
# working directory, so a developer's api/.env, local caches or uncommitted
# edits cannot reach a release. The verification at the end checks that rather
# than trusting it.
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

step "3. Document root (DEPLOYMENT.md §4a)"
mkdir -p "$OUT/httpdocs"
cp -R "$REPO_ROOT/src/out/." "$OUT/httpdocs/"
[ -f "$OUT/api/public/favicon.ico" ] && cp "$OUT/api/public/favicon.ico" "$OUT/httpdocs/favicon.ico"

# index.php: Laravel's front controller with every __DIR__.'/../' repointed at the
# sibling app directory. httpdocs/ is one level below home and the app is ~/ethr/,
# so '/../' becomes '/../ethr/api/' — DEPLOYMENT.md §0's depth table.
sed "s#__DIR__\.'/\.\./#__DIR__.'/../ethr/api/#g" "$OUT/api/public/index.php" > "$OUT/httpdocs/index.php"
REWRITES="$(grep -c "__DIR__\.'/\.\./ethr/api/" "$OUT/httpdocs/index.php" || true)"
[ "$REWRITES" = "3" ] || die "index.php: expected 3 repointed paths (maintenance, autoload, bootstrap), found $REWRITES — api/public/index.php changed shape"
info "index.php repointed: $REWRITES paths"

php "$REPO_ROOT/scripts/shared-hosting/render-htaccess.php" \
  --target=static-export --admin-host="$ADMIN_HOST" -o "$OUT/httpdocs/.htaccess"
info "admin host: $ADMIN_HOST"

# PHP limits: the panel shows them read-only on this account, and .user.ini is
# the override it names. See scripts/shared-hosting/user.ini.
cp "$REPO_ROOT/scripts/shared-hosting/user.ini" "$OUT/httpdocs/.user.ini"

step "4. Deployment action"
mkdir -p "$OUT/deploy"
cp "$REPO_ROOT/scripts/shared-hosting/post-deploy.sh" "$OUT/deploy/post-deploy.sh"
chmod +x "$OUT/deploy/post-deploy.sh"

cat > "$OUT/RELEASE" <<EOF
commit=$SHA
built_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
admin_host=$ADMIN_HOST
EOF

step "5. Verify the tree rather than trusting the steps"
# A credential file in a release is published: the production branch is in a
# public repository. Same check deploy.sh makes on its tarball.
LEAK="$(find "$OUT" \( -name '.env' -o -name '.env.local' -o -name '.env.production' -o -name '.env.backup' -o -name 'auth.json' \) -print)"
[ -z "$LEAK" ] || die "the release contains credential files: $LEAK"
# A config cache built here would freeze CI's environment into production.
[ ! -e "$OUT/api/bootstrap/cache/config.php" ] || die "bootstrap/cache/config.php was built into the release"
[ ! -e "$OUT/api/bootstrap/cache/routes-v7.php" ] || die "a route cache was built into the release"
[ -f "$OUT/api/vendor/autoload.php" ] || die "vendor/autoload.php is missing"
[ ! -d "$OUT/api/vendor/pestphp" ] || die "dev packages are installed (pestphp) — composer ran without --no-dev"
[ ! -d "$OUT/api/tests" ] || die "api/tests is in the release"
[ -f "$OUT/httpdocs/.htaccess" ] || die "httpdocs/.htaccess is missing"
[ -f "$OUT/httpdocs/index.html" ] || die "httpdocs/index.html is missing"
grep -q '^memory_limit = 256M' "$OUT/httpdocs/.user.ini" 2>/dev/null || die "httpdocs/.user.ini is missing or lacks the memory_limit floor"
grep -qF '\.user\.ini' "$OUT/httpdocs/.htaccess" || die "httpdocs/.htaccess does not deny .user.ini"
grep -q '^#@' "$OUT/httpdocs/.htaccess" && die "httpdocs/.htaccess still has unrendered #@ lines"

info "files: $(find "$OUT" -type f | wc -l | tr -d ' ')  size: $(du -sh "$OUT" | cut -f1)"
info "release tree ready: $OUT"
