#!/usr/bin/env bash
#
# ETHR quality gates — the full CLAUDE.md gate list in one command.
#
#   ./scripts/gates.sh             run every gate, report all failures
#   ./scripts/gates.sh backend     Pint + PHPStan + Pest only
#   ./scripts/gates.sh frontend    i18n + prettier + eslint + tsc + Vitest only
#   ./scripts/gates.sh performance the tests/Performance benchmarks only
#
# The API-contract gate needs both halves, so it only runs in a full sweep.
#
# Provider-neutral on purpose: any CI runner (or a pre-push hook, or a person)
# invokes this one script, so the gate list lives here and not in pipeline YAML.
#
# Runs every gate even after one fails, so a single run reports all the damage
# rather than making you rediscover it one gate at a time. Exits non-zero if any
# gate failed.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$REPO_ROOT/api"
WEB_DIR="$REPO_ROOT/src"

SCOPE="${1:-all}"

FAILED=()
PASSED=()

run_gate() {
    local name="$1"
    shift
    printf '\n\033[1m━━━ %s ━━━\033[0m\n' "$name"

    if "$@"; then
        PASSED+=("$name")
    else
        FAILED+=("$name")
        printf '\033[31m✗ %s failed\033[0m\n' "$name"
    fi
}

# Node binaries are invoked directly rather than through `npm run`: it keeps the
# script working where npm's shell resolution is broken, and skips a process layer.
tsc_gate() { (cd "$WEB_DIR" && node node_modules/typescript/bin/tsc --noEmit); }
vitest_gate() { (cd "$WEB_DIR" && node node_modules/vitest/vitest.mjs run --reporter=dot); }

# CLAUDE.md lists both of these as mandatory gates, but neither was wired in here
# until 2026-08-24 — which is how F-2 ("prettier --check fails, 36 files") reached
# a release audit at all: the formatting gate existed only as a line in a document,
# so nothing failed when it went red. Cheap to run, and they catch a class of drift
# tsc and Vitest are both blind to.
prettier_gate() { (cd "$WEB_DIR" && node node_modules/prettier/bin/prettier.cjs --check src/); }

# Exits non-zero on errors only; warnings (currently 2, both React-Compiler
# "incompatible library" notes on react-hook-form and TanStack Table) do not fail
# the gate. Do not add --max-warnings=0 without first retiring those two.
eslint_gate() { (cd "$WEB_DIR" && node node_modules/eslint/bin/eslint.js .); }

# Catches untranslated keys, en/am drift, and interpolated fallbacks — all of which
# fail silently at runtime, so nothing else in this list would notice them.
i18n_gate() { (cd "$WEB_DIR" && node "$REPO_ROOT/scripts/i18n-check.js"); }

# Fails when src/api/generated.ts no longer matches the live routes. tsc cannot
# catch this: it type-checks happily against a stale contract.
api_types_gate() { bash "$REPO_ROOT/scripts/api-types-check.sh"; }

# PHP tooling runs natively where a php binary exists (Linux CI, a WSL2-native
# checkout) and inside the api container otherwise. On the documented Windows +
# Docker Desktop setup there is no native php at all — it lives only in the
# container — so the gates that shelled straight out to `php` died with
# "env: 'php': No such file or directory" and took the whole sweep with them.
CONTAINER="${ETHR_API_CONTAINER:-et-api-1}"

have_php()    { command -v php >/dev/null 2>&1; }
container_up() { docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "$CONTAINER"; }

no_php_msg() {
    printf 'No native php, and container %s is not running.\n' "$CONTAINER"
    printf 'Start the stack with:  docker compose up -d\n'
}

# Safe to run over the bind mount either way: unlike Larastan and Pest, Pint's
# file scan is not lossy here — on-mount and off-mount runs were compared and
# reported the identical 880 files and the identical violations.
pint_gate() {
    if have_php; then
        (cd "$API_DIR" && ./vendor/bin/pint --test)
    elif container_up; then
        docker exec "$CONTAINER" sh -c 'cd /var/www/api && ./vendor/bin/pint --test'
    else
        no_php_msg
        return 1
    fi
}

# Off the bind mount, for the same reason as pest_gate below: Larastan reads the
# schema by enumerating migration files with RecursiveDirectoryIterator, which
# returns roughly half of them over a Docker Desktop Windows mount. The missing
# tables become ~990 "Access to an undefined property" errors — the noise that
# made this gate unusable, and which is entirely an artefact of where the files
# live. Measured 2026-08-22: 990 errors on the mount, 0 off it, same config.
phpstan_gate() { bash "$REPO_ROOT/scripts/phpstan-isolated.sh"; }

# memory_limit=-1 is required, not cosmetic: the DomPDF payslip tests exhaust the
# default limit and take the whole suite down with them.
#
# The collection guard in front of it is not optional either. PHP's recursive
# directory scan returns incomplete results over a Docker Desktop Windows bind
# mount: measured 2026-08-21, `pest` collected 21 of 132 test classes, ran them,
# and exited 0 with a green summary — so the gate reported success while proving
# almost nothing, and did so for weeks. `find` (a native binary) reads the same
# directory correctly, which is what makes the comparison possible.
#
# Fail loudly on an undercount rather than quietly passing. If this fires,
# either run the suite off the bind mount (copy `api/` into the container's own
# filesystem first) or move the checkout to a named volume / WSL2.
pest_gate() {
    # Without a native php the on-mount run below cannot start, and on this setup
    # it would fail the collection guard anyway — which is exactly the situation
    # the comment above says to resolve by running off the mount. That is what
    # scripts/pest-isolated.sh does, and it carries the same guard, so delegate
    # rather than reporting a red gate for a solved problem.
    if ! have_php; then
        if container_up; then
            bash "$REPO_ROOT/scripts/pest-isolated.sh"
            return $?
        fi
        no_php_msg
        return 1
    fi

    (
        cd "$API_DIR" || return 1

        # Only the two directories phpunit.xml declares as testsuites.
        # `tests/Performance` is deliberately outside them (it holds a
        # benchmark, not a gate) — counting it would make this guard fire on a
        # healthy environment.
        local on_disk collected
        on_disk=$(find tests/Unit tests/Feature -name '*Test.php' | wc -l | tr -d '[:space:]')
        collected=$(php -d memory_limit=-1 vendor/bin/pest --list-tests 2>/dev/null \
            | grep -oE '^ - [A-Za-z0-9_\\]+::' | sort -u | wc -l | tr -d '[:space:]')

        if [[ -z "$collected" || "$collected" -lt "$on_disk" ]]; then
            printf '\033[31mTest collection is lossy: %s of %s test classes collected.\033[0m\n' \
                "${collected:-0}" "$on_disk"
            printf 'Running anyway would report a green suite that never ran %s files.\n' \
                "$((on_disk - ${collected:-0}))"
            return 1
        fi

        printf 'collection check: %s/%s test classes\n' "$collected" "$on_disk"

        php -d memory_limit=-1 vendor/bin/pest
    )
}

if [[ "$SCOPE" == "all" || "$SCOPE" == "backend" ]]; then
    run_gate "Pint (format)"       pint_gate
    run_gate "PHPStan (level 6)"   phpstan_gate
    run_gate "Pest (backend)"      pest_gate
fi

if [[ "$SCOPE" == "all" || "$SCOPE" == "frontend" ]]; then
    run_gate "i18n (keys + en/am)" i18n_gate
    run_gate "Prettier (format)"   prettier_gate
    run_gate "ESLint (frontend)"   eslint_gate
    run_gate "TypeScript (tsc)"    tsc_gate
    run_gate "Vitest (frontend)"   vitest_gate
fi

# Needs both halves of the stack, so it only runs in a full sweep.
if [[ "$SCOPE" == "all" ]]; then
    run_gate "API types (contract)" api_types_gate
fi

# Opt-in, never part of `all`. These assert the CLAUDE.md "Performance Targets"
# wall-clock budgets, and they run against SQLite rather than MariaDB, so they are
# a regression guard rather than a production benchmark — timing-sensitive enough
# that a loaded machine can redden them without a code change. They stay out of the
# blocking sweep for that reason, but they are reachable by name now: audit finding
# F-6 was "ResponseTimeTest is not wired into any suite", and running it exactly
# once by hand closed the measurement without closing the hole.
if [[ "$SCOPE" == "performance" ]]; then
    run_gate "Pest (performance budgets)" bash "$REPO_ROOT/scripts/pest-isolated.sh" tests/Performance
fi

printf '\n\033[1m━━━ summary ━━━\033[0m\n'
for gate in "${PASSED[@]:-}"; do
    [[ -n "$gate" ]] && printf '\033[32m✓\033[0m %s\n' "$gate"
done
for gate in "${FAILED[@]:-}"; do
    [[ -n "$gate" ]] && printf '\033[31m✗\033[0m %s\n' "$gate"
done

if [[ ${#FAILED[@]} -gt 0 ]]; then
    printf '\n\033[31m%d gate(s) failed.\033[0m\n' "${#FAILED[@]}"
    exit 1
fi

printf '\n\033[32mAll gates passed.\033[0m\n'
