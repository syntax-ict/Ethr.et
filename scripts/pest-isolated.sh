#!/usr/bin/env bash
#
# Run the Pest suite off the bind mount.
#
# Why this exists: PHP's recursive directory scan returns incomplete results
# over a Docker Desktop Windows bind mount, so `pest` inside `et-api-1` collects
# a fraction of the suite (measured 2026-08-21: 22 of 132 test classes) and
# still exits 0. `scripts/gates.sh` now refuses to run in that state; this is how
# you actually run the suite until the checkout lives on a named volume or a
# WSL2-native path, where PHP's readdir behaves.
#
# It copies `api/` into the container's own filesystem with `cp` (a native
# binary, which reads the directory correctly) and runs there.
#
#   ./scripts/pest-isolated.sh                 whole suite
#   ./scripts/pest-isolated.sh tests/Feature   a subset (any pest argument)
#
# DB_CONNECTION is forced per-invocation: phpunit.xml's <env> does not override
# the container's real DB_CONNECTION OS variable (no force="true"), so without
# this the tests run against the shared dev database instead of SQLite.

set -euo pipefail

CONTAINER="${ETHR_API_CONTAINER:-et-api-1}"
WORKDIR="/tmp/pest-run"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    printf 'Container %s is not running. Start the stack with: docker compose up -d\n' "$CONTAINER" >&2
    exit 1
fi

# Guard against the trap documented in ENTERPRISE_ROADMAP.md: a same-named
# compose project started from a different checkout binds these containers to
# unrelated source, and the suite then "passes" against code you never wrote.
MOUNT=$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{.Source}} {{end}}')
printf 'api mount: %s\n' "$MOUNT"

docker exec "$CONTAINER" sh -c "rm -rf $WORKDIR && mkdir -p $WORKDIR && cp -a /var/www/api/. $WORKDIR/"

# Only the directories phpunit.xml declares as testsuites — `tests/Performance`
# is a benchmark that no suite includes.
ON_DISK=$(docker exec "$CONTAINER" sh -c "cd $WORKDIR && find tests/Unit tests/Feature -name '*Test.php' | wc -l" | tr -d '[:space:]')
COLLECTED=$(docker exec "$CONTAINER" sh -c "cd $WORKDIR && php -d memory_limit=-1 vendor/bin/pest --list-tests 2>/dev/null | grep -oE '^ - [A-Za-z0-9_\\\\]+::' | sort -u | wc -l" | tr -d '[:space:]')

printf 'collection check: %s/%s test classes\n' "$COLLECTED" "$ON_DISK"

if [[ -z "$COLLECTED" || "$COLLECTED" -lt "$ON_DISK" ]]; then
    printf 'Collection is still lossy inside the container copy — do not trust a green run.\n' >&2
    exit 1
fi

docker exec \
    -e DB_CONNECTION=sqlite \
    -e DB_DATABASE=:memory: \
    "$CONTAINER" sh -c "cd $WORKDIR && php -d memory_limit=-1 vendor/bin/pest $*"
