#!/usr/bin/env bash
#
# Run PHPStan off the bind mount.
#
# Why this exists: Larastan derives every model's properties by parsing the
# migration files, and it enumerates them with `RecursiveDirectoryIterator` —
# the same PHP API that returns incomplete results over a Docker Desktop Windows
# bind mount (measured 2026-08-22: 25 of 52 migrations visible on the mount,
# 52 of 52 off it). Larastan therefore builds its schema from roughly half the
# tables, and every column defined in a migration it never saw is reported as
# "Access to an undefined property".
#
# That is the whole of the ~990-error wall that made this gate unusable:
#
#   on the bind mount   990 errors  (EmployeeController alone: 6)
#   off the bind mount    0 errors  (same file, same config, same container)
#
# It is the identical defect that makes `pest` collect a fraction of the suite —
# see scripts/pest-isolated.sh. The real fix for both is to stop bind-mounting on
# Windows (named volume or WSL2-native checkout).
#
#   ./scripts/phpstan-isolated.sh                       analyse
#   ./scripts/phpstan-isolated.sh --generate-baseline   regenerate the baseline
#
# With --generate-baseline the regenerated file is copied back into the repo.

set -euo pipefail

CONTAINER="${ETHR_API_CONTAINER:-et-api-1}"
WORKDIR="/tmp/phpstan-run"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    printf 'Container %s is not running. Start the stack with: docker compose up -d\n' "$CONTAINER" >&2
    exit 1
fi

GENERATE=0
if [[ "${1:-}" == "--generate-baseline" ]]; then
    GENERATE=1
    shift
fi

docker exec "$CONTAINER" sh -c "rm -rf $WORKDIR && mkdir -p $WORKDIR && cp -a /var/www/api/. $WORKDIR/"

# Guard: if the copy is itself lossy the analysis is meaningless, and it would
# fail in the direction that looks like success (fewer migrations, more
# "undefined property" errors — or a baseline that bakes them in).
ON_DISK=$(docker exec "$CONTAINER" sh -c "cd $WORKDIR && find database/migrations -name '*.php' | wc -l" | tr -d '[:space:]')
SEEN=$(docker exec "$CONTAINER" sh -c "cd $WORKDIR && php -r '\$c=0; foreach (new DirectoryIterator(\"database/migrations\") as \$f) { if (! \$f->isDot()) \$c++; } echo \$c;'" | tr -d '[:space:]')

printf 'migration visibility: %s/%s\n' "$SEEN" "$ON_DISK"

if [[ -z "$SEEN" || "$SEEN" -lt "$ON_DISK" ]]; then
    printf 'Directory iteration is still lossy inside the copy — Larastan would see a partial schema.\n' >&2
    exit 1
fi

if [[ "$GENERATE" -eq 1 ]]; then
    docker exec "$CONTAINER" sh -c "cd $WORKDIR && php -d memory_limit=-1 vendor/bin/phpstan analyse --no-progress --generate-baseline=phpstan-baseline.neon"
    docker exec "$CONTAINER" sh -c "cp $WORKDIR/phpstan-baseline.neon /var/www/api/phpstan-baseline.neon"
    printf 'Baseline written to %s/api/phpstan-baseline.neon\n' "$REPO_ROOT"
    exit 0
fi

docker exec "$CONTAINER" sh -c "cd $WORKDIR && php -d memory_limit=-1 vendor/bin/phpstan analyse --no-progress $*"
