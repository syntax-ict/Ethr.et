#!/usr/bin/env bash
#
# ETHR quality gates — the full CLAUDE.md gate list in one command.
#
#   ./scripts/gates.sh             run every gate, report all failures
#   ./scripts/gates.sh backend     Pint + PHPStan + Pest + composer validate
#   ./scripts/gates.sh frontend    i18n + prettier + eslint + tsc + Vitest
#   ./scripts/gates.sh quick       everything except the test suites (~seconds)
#   ./scripts/gates.sh docs        markdown link integrity only
#   ./scripts/gates.sh security    composer audit + npm audit (production deps)
#   ./scripts/gates.sh performance the tests/Performance benchmarks only
#   ./scripts/gates.sh mysql       the backend suite against MariaDB, not SQLite
#   ./scripts/gates.sh lighthouse  Lighthouse CI over the public pages
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

# Failed gate name -> file holding its captured output. Populated only under
# GitHub Actions, where the job log is unreadable without an account and the
# failure has to be republished somewhere visible. `set -o pipefail` above is
# what makes the capture honest: without it the `| tee` in run_gate would report
# tee's exit status and every gate would pass.
declare -A GATE_OUTPUT=()

run_gate() {
    local name="$1"
    shift
    printf '\n\033[1m━━━ %s ━━━\033[0m\n' "$name"

    # Under GitHub Actions the output is also captured, so a failure can be
    # published where it can actually be read.
    #
    # Job *logs* need a signed-in account even on a public repository — the REST
    # API answers 403, the web log endpoint 404s, and the viewer will not expand
    # steps for a signed-out client. Annotations and the job summary are visible
    # to anyone. Naming the failed gate (below) turned "the Frontend job failed"
    # into "Vitest failed", which was already the difference between guessing and
    # knowing; this turns it into the actual assertion.
    #
    # `tee` keeps the live log identical, so nothing is hidden from someone who
    # *can* read it. Outside Actions this branch is skipped entirely and the gate
    # streams exactly as before.
    if [[ -n "${GITHUB_ACTIONS:-}" ]]; then
        local capture
        capture="$(mktemp)"

        if "$@" 2>&1 | tee "$capture"; then
            PASSED+=("$name")
        else
            FAILED+=("$name")
            printf '\033[31m✗ %s failed\033[0m\n' "$name"
            GATE_OUTPUT["$name"]="$capture"
            return 0
        fi

        rm -f "$capture"
        return 0
    fi

    if "$@"; then
        PASSED+=("$name")
    else
        FAILED+=("$name")
        printf '\033[31m✗ %s failed\033[0m\n' "$name"
    fi
}

# Node binaries are invoked directly rather than through `npm run`: it keeps the
# script working where npm's shell resolution is broken, and skips a process layer.
# `next typegen` first, because tsc cannot pass on a fresh checkout without it.
#
# Next writes `.next/types/*` and `.next/dev/types/*` during a build or a dev
# run, `next-env.d.ts` imports them by path, and tsconfig.json includes
# `next-env.d.ts` — so on a machine that has ever run `next dev` the imports
# resolve and tsc is green, while on a clean clone `.next/` does not exist and
# tsc fails on a file nobody wrote and the repository does not track.
#
# That is exactly how this gate passed locally for months and failed the first
# time CI ever managed to run it (2026-09-16). `typegen` generates those
# definitions without a full build, which is seconds rather than minutes.
tsc_gate() {
    (
        cd "$WEB_DIR" || return 1
        node node_modules/next/dist/bin/next typegen || return 1
        node node_modules/typescript/bin/tsc --noEmit
    )
}
vitest_gate() { (cd "$WEB_DIR" && node node_modules/vitest/vitest.mjs run --reporter=dot); }

# CLAUDE.md lists both of these as mandatory gates, but neither was wired in here
# until 2026-08-24 — which is how F-2 ("prettier --check fails, 36 files") reached
# a release audit at all: the formatting gate existed only as a line in a document,
# so nothing failed when it went red. Cheap to run, and they catch a class of drift
# tsc and Vitest are both blind to.
prettier_gate() { (cd "$WEB_DIR" && node node_modules/prettier/bin/prettier.cjs --check src/); }

# Exits non-zero on errors only. Warnings — 8 as of 2026-09-16 — do not fail the
# gate. Do not add --max-warnings=0 without first reading both groups:
#
#   2x  React-Compiler "Compilation Skipped: Use of incompatible library"
#       on react-hook-form and TanStack Table. Nothing to do here; they go when
#       those libraries ship compiler-compatible releases.
#
#   6x  @next/next/no-location-assign-relative-destination — `window.location
#       .href = "/..."` instead of a router push. The rule is right in general
#       and wrong at four of these five call sites, so do not "fix" them
#       wholesale:
#
#         api/client.ts:70                     -> /login  on a 401
#         features/auth/api.ts:63              -> /login  after logout
#         features/admin/api.ts:165            -> /dashboard on impersonate
#         components/shared/impersonation-banner.tsx:46
#                                              -> /admin or /login on exit
#
#       Every one of those follows a change of *identity*. A full reload is the
#       point: a soft navigation keeps the React tree, and with it cached
#       queries and component state belonging to the previous user. Turning
#       these into router.push() would convert a lint warning into a
#       cross-session data leak.
#
#         app/(dashboard)/devices/[id]/page.tsx:200  -> /devices after delete
#
#       That last one is an ordinary redirect and is a fair candidate for
#       router.push(), if anyone wants the warning count at 7.
#
# The count in this comment was stale once already — it said "currently 2" after
# eslint-config-next moved from 16.2.12 to 16.3.5 and added the rule above. If
# it disagrees with a run, trust the run.
eslint_gate() { (cd "$WEB_DIR" && node node_modules/eslint/bin/eslint.js .); }

# Catches untranslated keys, en/am drift, and interpolated fallbacks — all of which
# fail silently at runtime, so nothing else in this list would notice them.
i18n_gate() { (cd "$WEB_DIR" && node "$REPO_ROOT/scripts/i18n-check.js"); }

# Fails when src/api/generated.ts no longer matches the live routes. tsc cannot
# catch this: it type-checks happily against a stale contract.
api_types_gate() { bash "$REPO_ROOT/scripts/api-types-check.sh"; }

# Every relative markdown link must resolve. Added after Phase 1 found 33 broken
# links that no amount of reading had caught: ten phase documents pointed at
# ENTERPRISE_ROADMAP.md as a sibling when it lives one directory up, and twenty
# cited audit files that are not in the tree and never were. All of it sat in a
# header whose only job was to route readers to current status.
docs_gate() { (cd "$REPO_ROOT" && node scripts/docs-link-check.js); }

# composer.json and composer.lock agree, and the manifest is well-formed.
# --no-check-publish because this is a private application, not a package:
# without it, `name`/`description`/`license` requirements fail the gate for
# no reason.
composer_validate_gate() {
    if have_php; then
        (cd "$API_DIR" && composer validate --no-check-publish --no-interaction)
    elif container_up; then
        docker exec "$CONTAINER" sh -c 'cd /var/www/api && composer validate --no-check-publish --no-interaction'
    else
        no_php_msg
        return 1
    fi
}

# ── Security gates ───────────────────────────────────────────────────────────
#
# Deliberately NOT part of `all`, for the same reason tests/Performance is not:
# a gate that is permanently red stops being read. These depend on the advisory
# databases, so they go red when a new CVE lands rather than when someone breaks
# something, and `all` needs to mean "I broke nothing".
#
# They are blocking in CI (.github/workflows/security.yml), where a red result
# is a notification rather than an obstacle to the next commit.
#
# npm audit runs with --omit=dev on purpose. Dev-only advisories are real but
# they do not ship: as of 2026-09-15 this repository had 27 total and 5 in
# production dependencies, and lumping them together buries the five that
# reach users. Dev findings are printed separately and do not fail the gate.

# `composer audit` prints "No packages - skipping audit." and EXITS 0 when it
# cannot resolve an installed set — which is every machine without `api/vendor`,
# including a fresh clone. Before 2026-09-18 this gate passed that straight
# through, so `./scripts/gates.sh security` printed "All gates passed" having
# audited nothing at all. Measured here on 2026-09-18.
#
# CI is unaffected: security.yml runs `composer install` before this. The hole
# was local, which is worse in one specific way — CLAUDE.md tells a human to run
# this scope BY HAND, and by hand is exactly where vendor/ may be missing.
#
# Same defect class as the Pest undercount this script already guards: a run that
# examines a fraction of what it claims and exits green. A gate that cannot see
# its input must say so, not pass.
# Deliberately NOT a pipeline. `composer audit` exits non-zero when it finds a
# real advisory, and piping it into a checker would return the CHECKER's status
# and swallow that — turning a genuine vulnerability into a pass. Both the exit
# code and the output have to survive, so the output is captured and the status
# read straight from the command.
run_composer_audit() {
    local output status
    output="$("$@" 2>&1)"
    status=$?
    printf '%s\n' "$output"

    # composer prints this and exits 0 when it cannot resolve an installed set.
    if printf '%s' "$output" | grep -qiE 'No packages *- *skipping audit'; then
        {
            printf '\nGATE FAILED: composer audited NOTHING.\n'
            printf '  It reported "No packages - skipping audit", which means it could not\n'
            printf '  resolve an installed dependency set — usually a missing api/vendor.\n'
            printf '  That is not a pass. Run `cd api && composer install` first, then\n'
            printf '  re-run `./scripts/gates.sh security`.\n'
        } >&2
        return 1
    fi

    return "$status"
}

composer_audit_gate() {
    if have_php; then
        (cd "$API_DIR" && run_composer_audit composer audit --no-interaction)
    elif container_up; then
        run_composer_audit docker exec "$CONTAINER" sh -c \
            'cd /var/www/api && composer audit --no-interaction'
    else
        no_php_msg
        return 1
    fi
}

npm_audit_gate() {
    (
        cd "$WEB_DIR" || return 1
        printf 'Production dependencies (these ship):
'
        npm audit --omit=dev --audit-level=high
        local rc=$?

        printf '
Dev-only dependencies (informational, does not fail this gate):
'
        npm audit --include=dev --audit-level=high 2>&1 | tail -5 || true

        return $rc
    )
}

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

# Native PHP first, container only as the fallback — the same shape as
# pest_gate below, and for the same reason.
#
# The isolation exists for one specific defect: Larastan reads the schema by
# enumerating migration files with RecursiveDirectoryIterator, which returns
# roughly half of them over a Docker Desktop Windows bind mount. The missing
# tables become ~990 "Access to an undefined property" errors — noise that is
# entirely an artefact of where the files live. Measured 2026-08-22: 990 errors
# on the mount, 0 off it, same config.
#
# That is a property of the bind mount, not of PHP: a native run against a local
# disk sees all 55 migrations. So native is not a compromise, it is the better
# path wherever it exists.
#
# This used to delegate to phpstan-isolated.sh unconditionally, which requires a
# running `et-api-1` container — so the gate was unpassable in two places at
# once:
#
#   - a CI runner has native PHP and no container, so it exited 1 with
#     "Container et-api-1 is not running" every time. PHPStan could never have
#     passed in CI, whatever the code said.
#   - a developer with native PHP and Docker stopped got the same, on a machine
#     where PHPStan runs perfectly.
phpstan_gate() {
    if have_php; then
        (cd "$API_DIR" && php -d memory_limit=-1 vendor/bin/phpstan analyse --no-progress)
        return $?
    fi

    bash "$REPO_ROOT/scripts/phpstan-isolated.sh"
}

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

# The same suite against MySQL/MariaDB instead of SQLite :memory:.
#
# It exists because the two drivers are not interchangeable and the differences
# are not theoretical: the first time this suite was pointed at MariaDB it found
# a search defect that returned nothing in production and passed every test, a
# seeder whose TRUNCATE implicitly committed RefreshDatabase's transaction, and
# five tests that were green only because SQLite reuses low row ids. See
# docs/decisions/DECISIONS.md D-011 and D-012.
#
# **It fails rather than skips when no server is reachable.** That is the whole
# design: a gate that skips reports success, and this repository has already
# been bitten twice by checks that were green because they had not run. If you
# want to run the other gates without a database, ask for a different scope.
pest_mysql_gate() {
    if ! have_php; then
        no_php_msg
        return 1
    fi

    (
        cd "$API_DIR" || return 1

        local host port
        host="${DB_HOST:-127.0.0.1}"
        port="${DB_PORT:-3306}"

        if ! php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $s, 3) ? 0 : 1);' "$host" "$port"; then
            printf '\033[31mNo MySQL/MariaDB reachable at %s:%s.\033[0m\n' "$host" "$port"
            printf 'This gate runs the suite against the driver production uses, so it\n'
            printf 'refuses to pass without one. Start a server and create the database:\n\n'
            printf '  CREATE DATABASE ethr_suite_mysql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n\n'
            printf 'Override DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD if yours differ.\n'
            return 1
        fi

        php -d memory_limit=-1 vendor/bin/pest -c phpunit.mysql.xml
    )
}

lighthouse_gate() {
    (
        cd "$WEB_DIR" || return 1

        local base
        base="${LHCI_BASE_URL:-http://demo.localhost:3000}"

        # Same posture as the MySQL gate: refuse rather than skip. A Lighthouse
        # run that silently measures nothing is worse than no run, because the
        # report still renders and still looks like evidence.
        if ! node -e '
            const { get } = require("http");
            const req = get(process.argv[1], (res) => process.exit(res.statusCode < 500 ? 0 : 1));
            req.on("error", () => process.exit(1));
            req.setTimeout(3000, () => process.exit(1));
        ' "$base"; then
            printf '\033[31mNothing serving at %s.\033[0m\n' "$base"
            printf 'Lighthouse measures a running build, so this gate refuses to pass\n'
            printf 'without one:\n\n'
            printf '  cd src && npm run build && npm run start\n\n'
            printf 'Override the origin with LHCI_BASE_URL.\n'
            return 1
        fi

        node node_modules/@lhci/cli/src/cli.js autorun --config=.lighthouserc.cjs
    )
}

# `quick` is the pre-push scope: every gate that does not run a test suite.
# Seconds rather than ten minutes, which is the difference between a hook people
# keep and a hook people learn to pass --no-verify to. The suites run in CI, and
# `all` is still there for anyone who wants the lot locally.
if [[ "$SCOPE" == "quick" ]]; then
    run_gate "Composer (manifest)"   composer_validate_gate
    run_gate "Pint (format)"         pint_gate
    run_gate "i18n (keys + en/am)"   i18n_gate
    run_gate "Prettier (format)"     prettier_gate
    run_gate "ESLint (frontend)"     eslint_gate
    run_gate "TypeScript (tsc)"      tsc_gate
    run_gate "Docs (link integrity)" docs_gate
fi

if [[ "$SCOPE" == "all" || "$SCOPE" == "backend" ]]; then
    run_gate "Composer (manifest)" composer_validate_gate
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

if [[ "$SCOPE" == "all" || "$SCOPE" == "docs" ]]; then
    run_gate "Docs (link integrity)" docs_gate
fi

# Needs both halves of the stack, so it only runs in a full sweep.
if [[ "$SCOPE" == "all" ]]; then
    run_gate "API types (contract)" api_types_gate
fi

# Opt-in, never part of `all`: it needs a database server, and `all` has to stay
# runnable on a fresh clone. CI does have one, so the workflow calls this scope
# by name — which is why it fails rather than skips when the server is missing.
if [[ "$SCOPE" == "mysql" ]]; then
    run_gate "Pest (backend, MySQL)" pest_mysql_gate
fi

# Opt-in, never part of `all` — see the comment above composer_audit_gate.
if [[ "$SCOPE" == "security" ]]; then
    run_gate "Composer audit (backend deps)" composer_audit_gate
    run_gate "npm audit (production deps)"   npm_audit_gate
fi

# Opt-in, never part of `all`. These assert the CLAUDE.md "Performance Targets"
# wall-clock budgets, and they run against SQLite rather than MariaDB, so they are
# a regression guard rather than a production benchmark — timing-sensitive enough
# that a loaded machine can redden them without a code change. They stay out of the
# blocking sweep for that reason, but they are reachable by name now: audit finding
# F-6 was "ResponseTimeTest is not wired into any suite", and running it exactly
# once by hand closed the measurement without closing the hole.
#
# Native-first, for the same reason `pest_gate` and `phpstan_gate` are. This
# delegated to pest-isolated.sh unconditionally, which requires a running
# `et-api-1`, so `gates.sh performance` was unrunnable on any machine without
# Docker — including every CI runner, and including a developer machine with
# native PHP and Docker stopped. That is the identical defect that left PHPStan
# unable to pass in CI for fifty runs (see phpstan_gate above), missed here
# because this scope sits outside the blocking sweep and so nobody ran it.
#
# It is why BASELINE §13e read "no performance baseline exists": the gate that
# was supposed to produce one could not start.
performance_gate() {
    if have_php; then
        (cd "$API_DIR" && php -d memory_limit=-1 vendor/bin/pest tests/Performance)
        return $?
    fi

    if container_up; then
        bash "$REPO_ROOT/scripts/pest-isolated.sh" tests/Performance
        return $?
    fi

    no_php_msg
    return 1
}

if [[ "$SCOPE" == "performance" ]]; then
    run_gate "Pest (performance budgets)" performance_gate
fi

# Opt-in, never part of `all`, for the same reason as `mysql`: it needs a built
# app on a running server, and `all` has to stay runnable on a fresh clone. The
# public pages are the ones this measures — they are the only ones Lighthouse can
# reach without a session, which the config's own header explains at length.
if [[ "$SCOPE" == "lighthouse" ]]; then
    run_gate "Lighthouse CI (public pages)" lighthouse_gate
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

    # Name the failed gates as GitHub Actions annotations, not just in stdout.
    #
    # This is not decoration. A workflow job's log is readable only by someone
    # signed in with access, and on 2026-09-16 that made a Frontend failure
    # undiagnosable from outside: the job said "Process completed with exit
    # code 1" and nothing else, while the step that failed — one of five run by
    # this script — stayed inside a log nobody could open. Four hypotheses were
    # tested locally and all four were wrong; see docs/audit/BASELINE.md §12d.
    #
    # Annotations are visible on the run page without signing in. Emitting one
    # per failed gate turns "the Frontend job failed" into "ESLint failed",
    # which is the difference between guessing and knowing.
    #
    # Deliberately only the gate *names*, which this script already knows. The
    # workflow still calls `gates.sh` rather than restating the gate list, so
    # the two cannot drift — the same reason .github/workflows/gates.yml gives
    # in its own header.
    if [[ -n "${GITHUB_ACTIONS:-}" ]]; then
        # The failure text goes in the annotation itself, not only the job
        # summary. Run #62 wrote a summary and nothing rendered on the run page,
        # while the annotations from the same run rendered fine — so annotations
        # are the channel that is actually known to work for a signed-out reader.
        # The summary block below is kept as well; it costs nothing and is
        # pleasanter to read when it does show up.
        #
        # Actions requires newlines in an annotation to be escaped as %0A, and a
        # literal % as %25. Escape % first or it eats the escapes that follow.
        for gate in "${FAILED[@]:-}"; do
            [[ -z "$gate" ]] && continue

            local_capture="${GATE_OUTPUT[$gate]:-}"

            if [[ -n "$local_capture" && -f "$local_capture" ]]; then
                # The failing test NAMES first, then the tail.
                #
                # A blind tail is the wrong shape for these reporters. Run #63
                # reported "3 failed" and the 25-line tail showed one of them,
                # because Vitest prints the rendered DOM on a query failure and a
                # single dump swallowed the window. Grepping the failure lines
                # gives every failure in a few lines; the tail then adds the
                # detail for whichever one came last.
                printf '::error title=Gate failed: %s::%s%%0A%%0A%s%%0A%%0A--- tail ---%%0A%s\n' \
                    "$gate" \
                    "$gate" \
                    "$(sed 's/\x1b\[[0-9;]*m//g' "$local_capture" \
                        | grep -aE '(FAIL|✕|×|⨯|⨉)|Tests:|Test Files' \
                        | head -n 40 \
                        | sed 's/%/%25/g' \
                        | sed ':a;N;$!ba;s/\n/%0A/g')" \
                    "$(tail -n 20 "$local_capture" \
                        | sed 's/\x1b\[[0-9;]*m//g' \
                        | sed 's/%/%25/g' \
                        | sed ':a;N;$!ba;s/\n/%0A/g')"
            else
                printf '::error title=Gate failed::%s\n' "$gate"
            fi
        done

        # And the actual output, in the job summary — which renders on the run
        # page for anyone, signed in or not. The tail rather than the whole
        # thing: a Pest or Vitest run is tens of thousands of lines and the
        # failures are at the end, where both reporters print their summary.
        if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
            {
                printf '## Failed gates\n\n'
                for gate in "${FAILED[@]:-}"; do
                    [[ -z "$gate" ]] && continue
                    printf '### %s\n\n' "$gate"
                    local_capture="${GATE_OUTPUT[$gate]:-}"
                    if [[ -n "$local_capture" && -f "$local_capture" ]]; then
                        printf '```\n'
                        # Strip ANSI so the summary renders as text rather than
                        # escape soup.
                        tail -n 60 "$local_capture" | sed 's/\x1b\[[0-9;]*m//g'
                        printf '```\n\n'
                    else
                        printf '_No output captured._\n\n'
                    fi
                done
            } >> "$GITHUB_STEP_SUMMARY"
        fi
    fi

    exit 1
fi

printf '\n\033[32mAll gates passed.\033[0m\n'
