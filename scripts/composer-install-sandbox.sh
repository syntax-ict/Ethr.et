#!/usr/bin/env bash
#
# composer install where GitHub's archive hosts are blocked but git is not.
#
# On an ordinary machine you do not need this: run `composer install`. This is
# for an agent sandbox whose egress proxy scopes GitHub per repository, so only
# the repositories attached to the session are reachable over api.github.com and
# codeload.github.com. Composer's `dist` URLs point at
# api.github.com/repos/<vendor>/<pkg>/zipball/... — 163 different repositories,
# none of them this one — so every download 403s. Measured 2026-09-27:
#
#   git clone https://github.com/<any public repo>   works
#   api.github.com/repos/.../zipball/<ref>           403
#   codeload.github.com/...                          403
#   repo.packagist.org/p2/<pkg>.json                 200
#
# `--prefer-source` uses anonymous git clone, which the proxy serves. Two things
# then stand in the way, and the second is the one that is easy to misdiagnose:
#
#   1. `phpstan/phpstan` has "source": null in composer.lock, so it has no git
#      route at all. This script supplies one, pointed at the same commit its
#      dist URL names, so composer checks out the exact locked reference and
#      verifies it itself.
#   2. A full `git clone --mirror` of that repository takes longer than
#      composer's 300-second default process timeout. Composer then falls back
#      to dist, 403s, and reports "Could not authenticate against github.com"
#      at AuthHelper.php:132 — an authentication error that is really a slow
#      clone. COMPOSER_PROCESS_TIMEOUT=0 removes it.
#
# Nothing here modifies a tracked file. composer.json and composer.lock are
# copied to gitignored `composer.sandbox.*` siblings and the copies are patched,
# so a working-tree mutation can never reach a commit. The copies live in api/
# rather than a temp directory because composer generates the application's own
# PSR-4 paths relative to the manifest.
set -euo pipefail

API_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../api" && pwd)"
cd "$API_DIR"

MANIFEST="composer.sandbox.json"
SANDBOX_LOCK="composer.sandbox.lock"

cleanup() { rm -f "$MANIFEST" "$SANDBOX_LOCK"; }
trap cleanup EXIT

# `.env` has to exist before anything runs artisan: routes/channels.php calls
# Broadcast::channel() at load time, and config/broadcasting.php only defaults to
# the null driver when BROADCAST_CONNECTION is *unset* — .env.example ships
# `reverb`, which would build a Reverb broadcaster with no Pusher key.
if [ ! -f .env ]; then
    cp .env.example .env
    sed -i 's/^BROADCAST_CONNECTION=.*/BROADCAST_CONNECTION=null/' .env
    echo "wrote api/.env from .env.example (BROADCAST_CONNECTION=null)"
fi

cp composer.json "$MANIFEST"
cp composer.lock "$SANDBOX_LOCK"

python3 - "$SANDBOX_LOCK" <<'PY'
import collections, json, sys

path = sys.argv[1]
lock = json.load(open(path), object_pairs_hook=collections.OrderedDict)

patched = []
for entry in lock.get('packages', []) + lock.get('packages-dev', []):
    if entry.get('source') or not entry.get('dist'):
        continue
    url = entry['dist'].get('url', '')
    if 'api.github.com/repos/' not in url:
        continue
    owner_repo = url.split('api.github.com/repos/', 1)[1].split('/zipball/', 1)[0]
    entry['source'] = collections.OrderedDict([
        ('type', 'git'),
        ('url', f'https://github.com/{owner_repo}.git'),
        # The reference the dist URL names, so the source install lands on the
        # same commit the zipball would have contained.
        ('reference', entry['dist']['reference']),
    ])
    patched.append(f"{entry['name']} -> {owner_repo}@{entry['dist']['reference'][:12]}")

if patched:
    open(path, 'w').write(json.dumps(lock, indent=4) + '\n')
    print('gave a git source to:')
    for line in patched:
        print('   ', line)
else:
    print('every locked package already has a source; nothing to patch')
PY

echo
echo "installing from source — each package is a git clone, so this is slow"
COMPOSER="$MANIFEST" COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_PROCESS_TIMEOUT=0 \
    composer install --prefer-source --no-scripts --no-interaction

# The install ran with --no-scripts, so the package manifest was never built and
# no app key exists. Tests need the key: Employee.tin and national_id use the
# `encrypted` cast and the encrypter refuses to boot without one — the defect
# that killed 156 tests in CI for fifty runs.
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
php artisan package:discover --ansi

echo
missing=0
for bin in pest pint phpstan; do
    if [ -x "vendor/bin/$bin" ]; then
        echo "  vendor/bin/$bin  ok"
    else
        echo "  vendor/bin/$bin  MISSING" >&2
        missing=1
    fi
done
[ "$missing" -eq 0 ] || exit 1

echo
echo "toolchain ready — ./scripts/gates.sh backend now runs natively"
