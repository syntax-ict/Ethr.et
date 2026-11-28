#!/usr/bin/env bash
#
# ETHR accuracy session: the machine charters of a one-day session, in order,
# and a draft session sheet. The runbook is
# docs/production testing/15-ACCURACY-SESSION.md; the sheet's template is
# section 9 of docs/production testing/10-TEMPLATES-AND-REFERENCE.md.
#
#   ./scripts/accuracy-session.sh                     every charter, in order
#   ./scripts/accuracy-session.sh --only races        chosen charters (comma list)
#   ./scripts/accuracy-session.sh --list              what exists and what can run here
#   ./scripts/accuracy-session.sh --out <dir>         write logs and the sheet here
#
# Charters, in order: preflight, conformance, robustness, races, strength,
# restated, production. `preflight` always runs, because the others decide from
# what it finds; --only chooses among the rest.
#
# Exit code: 0 every charter that ran passed (skips never fail the session),
#            1 a charter that ran failed,
#            2 preflight failed, or the arguments were wrong.
#
# What it never does: edit a test, a case or a document in the repository,
# start a database server, type or read a credential, send a request to
# production other than through the read-only specs named in `production`,
# retry a charter. Every command runs under a deadline.
#
# ## Where the output goes
#
# api/storage/logs/accuracy-session/<UTC yyyy-mm-dd-HHMM>/: one NN-<charter>.log
# per charter and session-sheet.md. NOT api/storage/accuracy-session/, which
# was tried first and is not ignored by git (git check-ignore, 2026-11-28);
# api/storage/logs/.gitignore ignores everything under it. The script checks
# `git check-ignore` again at run time and refuses (exit 2) an --out inside the
# repository that git would show. An --out outside the repository is not
# checked. The sheet holds no markdown links: scripts/docs-link-check.cjs walks the
# filesystem, ignored files included, and a relative link in a sheet that lives
# under api/storage would fail the docs gate. src/test-results/ is avoided on
# purpose: Playwright empties its
# output directory at the start of a run.
#
# ## Deadlines
#
# Each command runs under GNU `timeout` (or `gtimeout`). Windows has its own,
# different timeout.exe; the script accepts only the GNU one and fails
# preflight with exit 2 otherwise. A charter that hits its deadline is recorded
# `failed: timed out after N min`. On Git Bash, timeout may not reach a native
# php.exe started by the command it killed; after a deadline the script looks
# for php.exe and says so in the log, because the next charter must not overlap
# a leftover run.
#
# ## Two-part charters
#
# conformance, robustness and restated run more than one command. One rule:
# failed if any part that ran failed; passed if at least one part ran and every
# part that ran passed; otherwise the skip reasons, joined. A part that did not
# run is named in the Note column.
#
# ## Test hooks (environment variables; none is a credential)
#
#   ACCURACY_SESSION_DB_PORT   port probed for the database server (default
#                              DB_PORT, else 3306). Set to 1 to prove that
#                              `races` is recorded `not run: no database server`.
#   ACCURACY_SESSION_STACK_URL the local production-shaped stack probed in
#                              preflight, information only (default
#                              http://localhost:8081).
#   E2E_ORIGIN                 the address pattern gates.sh e2e-attendance uses,
#                              `{tenant}` standing for the organisation
#                              (default http://{tenant}.localhost:3000). The
#                              `restated` browser journeys run only when that
#                              origin answers for tenant e2e-att.
#   DB_HOST                    host probed for the database (default 127.0.0.1).
#
# `production` runs `npm run test:e2e:production`, which is read-only by
# construction: every spec imports `test` from src/e2e-production/guard.ts,
# which aborts any /api/v1/ call that is not GET, HEAD or OPTIONS before it
# leaves the browser, and src/playwright.production.config.ts sets
# `serviceWorkers: "block"` (the one offline project never signs in). The
# script clears PROD_PROBE_SECRETS for that run, so S-05, which sends refused
# requests the host's firewall counts, never runs from here.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$REPO_ROOT/api"
WEB_DIR="$REPO_ROOT/src"

CHARTERS=(preflight conformance robustness races strength restated production)

declare -A CH_WHAT=(
    [preflight]='commit, tools, collection count, database, stack, gh'
    [conformance]='gates.sh accuracy; payroll golden payslips and tax points until they join the suite'
    [robustness]='generated seeded run and properties; api/tests/Feature/Idempotency'
    [races]='gates.sh concurrency (punches racing from separate processes)'
    [strength]='latest CI run of the accuracy-mutation job, read with gh'
    [restated]='restated-figures tests; gates.sh e2e-attendance'
    [production]='npm run test:e2e:production, read-only, saved sessions only'
)
declare -A CH_DEADLINE=(
    [preflight]=120 [conformance]=1500 [robustness]=2700 [races]=1200
    [strength]=300 [restated]=2400 [production]=1200
)

declare -A CH_STATUS=() CH_DUR=() CH_LOG=() CH_NOTE=()
declare -A ENGINE_COUNTS=()
ROBUST_LINES=""

# ── Arguments ────────────────────────────────────────────────────────────────

OUT_DIR=""
ONLY=""
LIST=0

usage() {
    printf 'Usage: %s [--out <dir>] [--only <charter>[,<charter>...]] [--list]\n' "$0" >&2
    printf 'Charters: %s\n' "${CHARTERS[*]}" >&2
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --out)  [[ $# -ge 2 ]] || { usage; exit 2; }; OUT_DIR="$2"; shift 2 ;;
        --only) [[ $# -ge 2 ]] || { usage; exit 2; }; ONLY="$2"; shift 2 ;;
        --list) LIST=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'Unknown argument: %s\n' "$1" >&2; usage; exit 2 ;;
    esac
done

is_charter() {
    local c
    for c in "${CHARTERS[@]}"; do [[ "$c" == "$1" ]] && return 0; done
    return 1
}

selected() {
    [[ -z "$ONLY" ]] && return 0
    [[ "$1" == "preflight" ]] && return 0
    [[ ",$ONLY," == *",$1,"* ]]
}

# ── Small helpers ────────────────────────────────────────────────────────────

strip_ansi() { sed 's/\x1b\[[0-9;]*[A-Za-z]//g'; }

fmt_dur() { printf '%d:%02d' $(($1 / 60)) $(($1 % 60)); }

have() { command -v "$1" >/dev/null 2>&1; }

TIMEOUT_CMD=""
find_timeout() {
    local c
    for c in timeout gtimeout; do
        have "$c" || continue
        # Windows ships its own timeout.exe, which waits for a keypress and
        # takes no command. Only the GNU one counts.
        if "$c" --version 2>&1 | grep -qi 'coreutils' && "$c" 5 true >/dev/null 2>&1; then
            TIMEOUT_CMD="$c"
            return 0
        fi
    done
    return 1
}

# run_bounded <seconds> <logfile> <command...>
# Appends stdout and stderr to the log. Returns the command's status, 124 on
# the deadline. Never retries.
run_bounded() {
    local secs="$1" log="$2"
    shift 2
    "$TIMEOUT_CMD" -k 15 "$secs" "$@" >>"$log" 2>&1
}

# Short probes use the same wrapper and swallow output.
probe_bounded() {
    local secs="$1"
    shift
    "$TIMEOUT_CMD" -k 5 "$secs" "$@" >/dev/null 2>&1
}

mins() { printf '%d' $(($1 / 60)); }

# After a deadline, say whether a native php.exe is still around.
note_leftovers() {
    local log="$1"
    if have tasklist; then
        if tasklist 2>/dev/null | grep -qi '^php'; then
            printf '\nAfter the deadline a php process is still running on this machine. Check it before anything else runs tests here.\n' >>"$log"
        fi
    elif have pgrep && pgrep -x php >/dev/null 2>&1; then
        printf '\nAfter the deadline a php process is still running on this machine. Check it before anything else runs tests here.\n' >>"$log"
    fi
}

# last_tests_line <logfile>: the last Pest "Tests:" line, ANSI stripped, or empty.
last_tests_line() {
    strip_ansi <"$1" | grep -E '^[[:space:]]*Tests:' | tail -n 1 | sed 's/^[[:space:]]*//'
}

# count_in_line <word> <line>: the number before the word, or 0 when absent.
count_in_line() {
    local n
    n="$(printf '%s' "$2" | grep -oE "[0-9]+ $1" | head -n 1 | grep -oE '^[0-9]+')"
    printf '%s' "${n:-0}"
}

# ── Environment probes (cheap, bounded, shared by --list and the run) ────────

PRE_SHA=""; PRE_BRANCH=""; PRE_CLEAN=""
PRE_PHP=""; PRE_COMPOSER=""; PRE_NODE=""; PRE_GH=""
PRE_VENDOR=0
PRE_UNIT=0; PRE_FEATURE=0; PRE_TOTAL=0
DB_UP=0; DB_HOST_PROBED=""; DB_PORT_PROBED=""
STACK_URL="${ACCURACY_SESSION_STACK_URL:-http://localhost:8081}"; STACK_UP=0
E2E_PROBE_URL=""; E2E_UP=0
GH_OK=0
PRE_PROBLEMS=()

http_answers() {
    # http_answers <url> [curl --resolve value]: 0 when anything answers over HTTP.
    local url="$1" resolve="${2:-}" code
    have curl || return 1
    if [[ -n "$resolve" ]]; then
        code="$("$TIMEOUT_CMD" -k 5 15 curl -s -o /dev/null -w '%{http_code}' --max-time 8 --resolve "$resolve" "$url" 2>/dev/null)"
    else
        code="$("$TIMEOUT_CMD" -k 5 15 curl -s -o /dev/null -w '%{http_code}' --max-time 8 "$url" 2>/dev/null)"
    fi
    [[ -n "$code" && "$code" != "000" ]]
}

probe_environment() {
    PRE_SHA="$(git -C "$REPO_ROOT" rev-parse --short=10 HEAD 2>/dev/null || true)"
    PRE_BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD 2>/dev/null || true)"
    if [[ -z "$PRE_SHA" ]]; then PRE_PROBLEMS+=("not a git checkout, no commit to record"); fi

    if [[ -z "$(git -C "$REPO_ROOT" status --porcelain --untracked-files=no 2>/dev/null)" ]]; then
        PRE_CLEAN="clean (tracked files)"
    else
        PRE_CLEAN="not clean (tracked files changed)"
    fi

    if have php; then PRE_PHP="$(php -r 'echo PHP_VERSION;' 2>/dev/null)"; else PRE_PHP="not found"; PRE_PROBLEMS+=("no php on PATH"); fi
    if have composer; then
        PRE_COMPOSER="$("$TIMEOUT_CMD" -k 5 30 composer --version --no-ansi 2>/dev/null | head -n 1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n 1)"
        PRE_COMPOSER="${PRE_COMPOSER:-found, version unread}"
    else
        PRE_COMPOSER="not found"
    fi
    if have node; then PRE_NODE="$(node -v 2>/dev/null)"; else PRE_NODE="not found"; PRE_PROBLEMS+=("no node on PATH"); fi
    if have gh; then PRE_GH="$(gh --version 2>/dev/null | head -n 1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n 1)"; else PRE_GH="not found"; fi

    if [[ -f "$API_DIR/vendor/autoload.php" ]]; then PRE_VENDOR=1; else PRE_PROBLEMS+=("api/vendor is missing (run composer install in api/)"); fi

    # The collection-count rule of the root CLAUDE.md, as the two numbers it
    # compares on disk. The whole suite is not run and not listed here.
    PRE_UNIT="$(find "$REPO_ROOT/api/tests/Unit" -name '*Test.php' 2>/dev/null | wc -l | tr -d '[:space:]')"
    PRE_FEATURE="$(find "$REPO_ROOT/api/tests/Feature" -name '*Test.php' 2>/dev/null | wc -l | tr -d '[:space:]')"
    PRE_TOTAL="$(find "$REPO_ROOT/api/tests/Unit" "$REPO_ROOT/api/tests/Feature" -name '*Test.php' 2>/dev/null | wc -l | tr -d '[:space:]')"

    DB_HOST_PROBED="${DB_HOST:-127.0.0.1}"
    DB_PORT_PROBED="${ACCURACY_SESSION_DB_PORT:-${DB_PORT:-3306}}"
    if have php && probe_bounded 15 php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $s, 3) ? 0 : 1);' "$DB_HOST_PROBED" "$DB_PORT_PROBED"; then
        DB_UP=1
    fi

    if http_answers "$STACK_URL"; then STACK_UP=1; fi

    # The origin gates.sh e2e-attendance will actually use, for tenant e2e-att.
    local pattern host port scheme
    pattern="${E2E_ORIGIN:-http://\{tenant\}.localhost:3000}"
    E2E_PROBE_URL="${pattern//\{tenant\}/e2e-att}/login"
    scheme="${E2E_PROBE_URL%%://*}"
    host="${E2E_PROBE_URL#*://}"; host="${host%%/*}"
    port="${host##*:}"; [[ "$port" == "$host" ]] && { [[ "$scheme" == "https" ]] && port=443 || port=80; }
    host="${host%%:*}"
    if [[ "$host" == "localhost" || "$host" == *.localhost ]]; then
        http_answers "$E2E_PROBE_URL" "$host:$port:127.0.0.1" && E2E_UP=1
    else
        http_answers "$E2E_PROBE_URL" && E2E_UP=1
    fi

    if have gh && probe_bounded 30 gh auth status; then GH_OK=1; fi
}

# ── Charter preconditions: one answer for --list and for the run ─────────────
# Prints `runs`, `not run: <reason>` or `not built yet` (possibly with a note
# after a semicolon for two-part charters).

accuracy_has_dir() { [[ -d "$API_DIR/tests/Accuracy/$1" ]]; }

generated_paths() {
    # Paths, relative to api/, of generated-run tests under tests/Accuracy.
    local p
    while IFS= read -r p; do
        [[ -n "$p" ]] && printf '%s\n' "${p#"$API_DIR"/}"
    done < <(find "$API_DIR/tests/Accuracy" \( -type d -name 'Generator' -o -type f -iname '*Generated*' \) 2>/dev/null | sort)
}

mutation_workflow() {
    grep -lE '^[[:space:]]*accuracy-mutation:' "$REPO_ROOT"/.github/workflows/*.yml 2>/dev/null | head -n 1
}

production_roles() {
    # The roles whose saved session the pack's own check (sessionProblem in
    # src/e2e-production/env.ts) accepts. Prints role names only. Exit 3 when
    # the check itself cannot be run.
    [[ -d "$WEB_DIR/node_modules" ]] || return 4
    (
        cd "$WEB_DIR" || exit 3
        "$TIMEOUT_CMD" -k 5 30 node --experimental-strip-types --no-warnings -e '
            import("./e2e-production/env.ts").then((m) => {
                console.log(m.ROLES.filter((r) => m.sessionProblem(r) === null).join(","));
            }).catch(() => process.exit(3));
        ' 2>/dev/null
    )
}

charter_state() {
    case "$1" in
        preflight) echo runs ;;
        conformance)
            if [[ $PRE_VENDOR -eq 1 ]] && have php; then echo runs; else echo "not run: no api/vendor or no php"; fi ;;
        robustness)
            local gen=0 idem=0
            [[ -n "$(generated_paths)" ]] && gen=1
            [[ -d "$API_DIR/tests/Feature/Idempotency" ]] && idem=1
            if [[ $gen -eq 0 && $idem -eq 0 ]]; then
                echo "not built yet"
            elif [[ $PRE_VENDOR -eq 1 ]] && have php; then
                local n=""
                [[ $gen -eq 0 ]] && n="generated run and properties: not built yet"
                [[ $idem -eq 0 ]] && n="api/tests/Feature/Idempotency: not built yet"
                echo "runs${n:+; $n}"
            else
                echo "not run: no api/vendor or no php"
            fi ;;
        races)
            if [[ $DB_UP -eq 1 ]]; then echo runs; else echo "not run: no database server"; fi ;;
        strength)
            if [[ -z "$(mutation_workflow)" ]]; then echo "not built yet"
            elif [[ $GH_OK -eq 0 ]]; then echo "not run: gh not signed in"
            else echo runs; fi ;;
        restated)
            local r=0 j=0
            accuracy_has_dir Restated && r=1
            [[ $E2E_UP -eq 1 ]] && j=1
            if [[ $r -eq 0 && $j -eq 0 ]]; then
                echo "not run: local stack not running; restated-figures tests not built yet"
            else
                local n=""
                [[ $r -eq 0 ]] && n="restated-figures tests: not built yet"
                [[ $j -eq 0 ]] && n="browser journeys: not run, local stack not running at $E2E_PROBE_URL"
                echo "runs${n:+; $n}"
            fi ;;
        production)
            local roles rc
            roles="$(production_roles)"; rc=$?
            if [[ $rc -eq 4 ]]; then echo "not run: no src/node_modules"
            elif [[ $rc -ne 0 ]]; then echo "not run: the session check could not be read (needs Node 22.6 or newer)"
            elif [[ -z "$roles" ]]; then echo "not run: no saved session"
            else echo "runs; fresh sessions: $roles"; fi ;;
    esac
}

# ── --list ───────────────────────────────────────────────────────────────────

do_list() {
    printf 'Accuracy session charters (nothing is run; cheap probes only: tool versions, a TCP probe, three bounded HTTP probes and gh auth status).\n\n'
    if ! find_timeout; then
        printf 'WARNING: no GNU timeout on PATH. A session cannot start: every command needs a deadline.\n\n'
        TIMEOUT_CMD="false"
    fi
    probe_environment
    printf '%-12s %-9s %-8s %s\n' charter deadline here? what
    local c st mark
    for c in "${CHARTERS[@]}"; do
        st="$(charter_state "$c")"
        printf '%-12s %-9s %s\n' "$c" "$(fmt_dur "${CH_DEADLINE[$c]}")" "$st"
        printf '%-12s %-9s   %s\n' "" "" "${CH_WHAT[$c]}"
    done
    printf '\nDatabase probe: %s:%s answers: %s. Local stack %s answers: %s.\n' \
        "$DB_HOST_PROBED" "$DB_PORT_PROBED" "$([[ $DB_UP -eq 1 ]] && echo yes || echo no)" \
        "$STACK_URL" "$([[ $STACK_UP -eq 1 ]] && echo yes || echo no)"
}

# ── The session sheet: ONE function. Section 9 of file 10 shows the same headings.

write_sheet() {
    local f="$OUT_DIR/session-sheet.md" c st dur log note
    {
        printf '# Accuracy session sheet\n\n'
        printf '## Header\n\n'
        printf '| | |\n|---|---|\n'
        printf '| Date (UTC) | %s |\n' "$(date -u '+%Y-%m-%d %H:%M')"
        printf '| Commit | %s |\n' "${PRE_SHA:-unknown}"
        printf '| Branch | %s |\n' "${PRE_BRANCH:-unknown}"
        printf '| Worktree | %s |\n' "$PRE_CLEAN"
        printf '| Machine | %s |\n' "$(uname -srm 2>/dev/null)"
        printf '| PHP, Composer, Node, gh | %s, %s, %s, %s |\n' "$PRE_PHP" "$PRE_COMPOSER" "$PRE_NODE" "$PRE_GH"
        printf '| Test files on disk | Unit %s, Feature %s, together %s |\n' "$PRE_UNIT" "$PRE_FEATURE" "$PRE_TOTAL"
        printf '| Database at %s:%s | %s |\n' "$DB_HOST_PROBED" "$DB_PORT_PROBED" "$([[ $DB_UP -eq 1 ]] && echo answers || echo 'does not answer')"
        printf '| Local stack at %s | %s |\n' "$STACK_URL" "$([[ $STACK_UP -eq 1 ]] && echo answers || echo 'does not answer')"
        printf '| Run by | script, then a person or an agent fills the triage table |\n\n'

        printf '## Charters\n\n'
        printf '| Charter | Status | Duration | Log | Note |\n|---|---|---|---|---|\n'
        for c in "${CHARTERS[@]}"; do
            st="${CH_STATUS[$c]:-not selected}"
            dur="${CH_DUR[$c]:--}"
            log="${CH_LOG[$c]:--}"
            note="${CH_NOTE[$c]:-}"
            printf '| %s | %s | %s | %s | %s |\n' "$c" "$st" "$dur" "$log" "$note"
        done
        printf '\n'

        printf '## Conformance by engine\n\n'
        printf 'Counts are copied from the last Pest "Tests:" line of each run. Incomplete means a recorded finding. Skipped means the rule is undecided.\n\n'
        printf '| Engine | Source | Passed | Failed | Incomplete | Skipped |\n|---|---|---|---|---|---|\n'
        local e
        for e in attendance payroll leave calendar; do
            printf '%s\n' "${ENGINE_COUNTS[$e]:-| $e | not run | | | | |}"
        done
        printf '\n'

        printf '## Robustness output\n\n'
        printf 'Lines copied unchanged from the test output: seed, number of cases, disagreements, input distribution. No interval is computed.\n\n'
        if [[ -n "$ROBUST_LINES" ]]; then
            printf '```text\n%s\n```\n\n' "$ROBUST_LINES"
        else
            printf 'None: the robustness charter did not run, or printed none of those lines.\n\n'
        fi

        printf '## Triage\n\n'
        printf 'One row per failure, incomplete case or surprise. Class is one of the four in file 12, section 6 (Classify a finding): engine defect, wrong hand value, fixture error, rule undecided.\n\n'
        printf '| # | Charter | Case or check | What happened | Class | Filed as | Owner |\n|---|---|---|---|---|---|---|\n'
        printf '| | | | | | | |\n\n'

        printf '## Debrief\n\n'
        printf -- '- What the machine could not do today, and why:\n'
        printf -- '- What surprised us:\n'
        printf -- '- What the next session should change:\n'
    } >"$f"
}

# ── Charters ─────────────────────────────────────────────────────────────────

CUR_LOG=""

begin_charter() { # <name> <index>
    CUR_LOG="$OUT_DIR/$(printf '%02d' "$2")-$1.log"
    : >"$CUR_LOG"
    CH_LOG[$1]="$(basename "$CUR_LOG")"
    printf '\n=== %s (deadline %s min) ===\n' "$1" "$(mins "${CH_DEADLINE[$1]}")"
}

# part_result <name> <rc> <label>: records a part's outcome in the charter's tallies.
PARTS_RAN=0; PARTS_FAILED=0; PARTS_FAIL_WHY=""
part_reset() { PARTS_RAN=0; PARTS_FAILED=0; PARTS_FAIL_WHY=""; }
part_result() {
    local rc="$1" label="$2" secs="$3"
    PARTS_RAN=$((PARTS_RAN + 1))
    if [[ $rc -eq 0 ]]; then
        printf '  %s: passed\n' "$label"
    else
        PARTS_FAILED=$((PARTS_FAILED + 1))
        if [[ $rc -eq 124 || $rc -eq 137 ]]; then
            PARTS_FAIL_WHY="${PARTS_FAIL_WHY:+$PARTS_FAIL_WHY; }$label timed out after $(mins "$secs") min"
            printf '  %s: timed out\n' "$label"
            note_leftovers "$CUR_LOG"
        else
            PARTS_FAIL_WHY="${PARTS_FAIL_WHY:+$PARTS_FAIL_WHY; }$label exit $rc"
            printf '  %s: failed (exit %s)\n' "$label" "$rc"
        fi
    fi
}

finish_charter() { # <name> <start-seconds> <skip-note-or-empty>
    local c="$1" start="$2" skipnote="$3"
    CH_DUR[$c]="$(fmt_dur $((SECONDS - start)))"
    if [[ $PARTS_FAILED -gt 0 ]]; then
        CH_STATUS[$c]="failed: $PARTS_FAIL_WHY"
    else
        CH_STATUS[$c]="passed"
    fi
    CH_NOTE[$c]="$skipnote"
}

engine_row() { # <engine> <source> <summary-line>
    local line="$3"
    if [[ -z "$line" ]]; then
        ENGINE_COUNTS[$1]="| $1 | $2 | no summary line | no summary line | no summary line | no summary line |"
    else
        ENGINE_COUNTS[$1]="| $1 | $2 | $(count_in_line passed "$line") | $(count_in_line failed "$line") | $(count_in_line incomplete "$line") | $(count_in_line skipped "$line") |"
    fi
}

charter_preflight() {
    local start=$SECONDS
    begin_charter preflight 1
    {
        printf 'commit: %s\nbranch: %s\nworktree: %s\n' "$PRE_SHA" "$PRE_BRANCH" "$PRE_CLEAN"
        printf 'php: %s\ncomposer: %s\nnode: %s\ngh: %s\n' "$PRE_PHP" "$PRE_COMPOSER" "$PRE_NODE" "$PRE_GH"
        printf 'api/vendor present: %s\n' "$([[ $PRE_VENDOR -eq 1 ]] && echo yes || echo no)"
        printf 'test files on disk (find api/tests/Unit api/tests/Feature -name *Test.php): Unit %s, Feature %s, together %s\n' "$PRE_UNIT" "$PRE_FEATURE" "$PRE_TOTAL"
        printf 'database server at %s:%s answers: %s\n' "$DB_HOST_PROBED" "$DB_PORT_PROBED" "$([[ $DB_UP -eq 1 ]] && echo yes || echo no)"
        printf 'local stack at %s answers: %s\n' "$STACK_URL" "$([[ $STACK_UP -eq 1 ]] && echo yes || echo no)"
        printf 'e2e origin %s answers: %s\n' "$E2E_PROBE_URL" "$([[ $E2E_UP -eq 1 ]] && echo yes || echo no)"
        printf 'gh signed in: %s\n' "$([[ $GH_OK -eq 1 ]] && echo yes || echo no)"
        printf 'timeout command: %s\n' "$TIMEOUT_CMD"
        printf 'output directory: %s\n' "$OUT_DIR"
    } >>"$CUR_LOG"
    CH_DUR[preflight]="$(fmt_dur $((SECONDS - start)))"
    if [[ ${#PRE_PROBLEMS[@]} -gt 0 ]]; then
        CH_STATUS[preflight]="failed: ${PRE_PROBLEMS[*]}"
        printf 'problems: %s\n' "${PRE_PROBLEMS[*]}" >>"$CUR_LOG"
        printf '  preflight: failed (%s)\n' "${PRE_PROBLEMS[*]}"
        return 1
    fi
    CH_STATUS[preflight]="passed"
    CH_NOTE[preflight]="worktree: $PRE_CLEAN"
    printf '  preflight: passed\n'
}

charter_conformance() {
    local start=$SECONDS part line payroll_note=""
    begin_charter conformance 2
    part_reset

    printf '\n--- gates.sh accuracy ---\n' >>"$CUR_LOG"
    part="$(mktemp)"
    run_bounded 900 "$part" bash "$REPO_ROOT/scripts/gates.sh" accuracy
    local rc=$?
    cat "$part" >>"$CUR_LOG"
    line="$(last_tests_line "$part")"
    rm -f "$part"
    part_result $rc "gates.sh accuracy" 900
    local label="tests/Accuracy (attendance golden cases and oracle)"
    if accuracy_has_dir Payroll || accuracy_has_dir Leave || accuracy_has_dir Calendar; then
        label="tests/Accuracy (attendance, and the engines whose folders exist)"
    fi
    engine_row attendance "$label" "$line"

    if accuracy_has_dir Payroll; then
        ENGINE_COUNTS[payroll]="| payroll | in the tests/Accuracy row above | | | | |"
        payroll_note="payroll cases are in the accuracy suite"
    elif [[ -f "$API_DIR/tests/Feature/Payroll/PayrollGoldenPayslipsTest.php" && -f "$API_DIR/tests/Feature/Payroll/TaxCalculatorTest.php" ]]; then
        printf '\n--- payroll golden payslips and tax points ---\n' >>"$CUR_LOG"
        part="$(mktemp)"
        (cd "$API_DIR" && run_bounded 600 "$part" php -d memory_limit=-1 vendor/bin/pest \
            tests/Feature/Payroll/PayrollGoldenPayslipsTest.php tests/Feature/Payroll/TaxCalculatorTest.php)
        rc=$?
        cat "$part" >>"$CUR_LOG"
        line="$(last_tests_line "$part")"
        rm -f "$part"
        part_result $rc "payroll golden payslips and tax points" 600
        engine_row payroll "PayrollGoldenPayslipsTest and TaxCalculatorTest" "$line"
    else
        ENGINE_COUNTS[payroll]="| payroll | not run: test files not found | | | | |"
    fi

    if accuracy_has_dir Leave; then ENGINE_COUNTS[leave]="| leave | in the tests/Accuracy row above | | | | |"
    else ENGINE_COUNTS[leave]="| leave | not measured, no cases built yet | | | | |"; fi
    if accuracy_has_dir Calendar; then ENGINE_COUNTS[calendar]="| calendar | in the tests/Accuracy row above | | | | |"
    else ENGINE_COUNTS[calendar]="| calendar | not measured, no cross-check built yet | | | | |"; fi

    finish_charter conformance "$start" "$payroll_note"
}

charter_robustness() {
    local start=$SECONDS part rc skip="" paths p
    begin_charter robustness 3
    part_reset
    local state; state="$(charter_state robustness)"
    [[ "$state" == *";"* ]] && skip="${state#*; }"

    paths="$(generated_paths)"
    if [[ -n "$paths" ]]; then
        printf '\n--- generated run and properties ---\n' >>"$CUR_LOG"
        part="$(mktemp)"
        # shellcheck disable=SC2086
        (cd "$API_DIR" && run_bounded 1500 "$part" php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml $(printf '%s ' $paths))
        rc=$?
        cat "$part" >>"$CUR_LOG"
        # Verbatim: whatever the test prints about seed, cases, disagreements and
        # the input distribution. Nothing is computed from it here.
        ROBUST_LINES="$(strip_ansi <"$part" | grep -iE 'seed|cases|disagree|distribution' | head -n 60)"
        rm -f "$part"
        part_result $rc "generated run and properties" 1500
    fi

    if [[ -d "$API_DIR/tests/Feature/Idempotency" ]]; then
        printf '\n--- api/tests/Feature/Idempotency ---\n' >>"$CUR_LOG"
        part="$(mktemp)"
        (cd "$API_DIR" && run_bounded 900 "$part" php -d memory_limit=-1 vendor/bin/pest tests/Feature/Idempotency)
        rc=$?
        cat "$part" >>"$CUR_LOG"
        rm -f "$part"
        part_result $rc "idempotency tests" 900
    fi

    finish_charter robustness "$start" "$skip"
}

charter_races() {
    local start=$SECONDS
    begin_charter races 4
    part_reset
    run_bounded 1200 "$CUR_LOG" bash "$REPO_ROOT/scripts/gates.sh" concurrency
    part_result $? "gates.sh concurrency" 1200
    finish_charter races "$start" ""
}

charter_strength() {
    local start=$SECONDS wf wfname ids id job sha created found=0 localsha
    begin_charter strength 5
    part_reset
    wf="$(mutation_workflow)"; wfname="$(basename "$wf")"
    printf 'workflow file: %s\n' "$wfname" >>"$CUR_LOG"
    ids="$(cd "$REPO_ROOT" && "$TIMEOUT_CMD" -k 5 60 gh run list --workflow "$wfname" --limit 10 --json databaseId --jq '.[].databaseId' 2>>"$CUR_LOG")"
    local rc=$?
    if [[ $rc -ne 0 ]]; then
        part_result $rc "gh run list" 60
        finish_charter strength "$start" ""
        return
    fi
    if [[ -z "$ids" ]]; then
        printf 'No run of %s exists yet.\n' "$wfname" >>"$CUR_LOG"
        CH_DUR[strength]="$(fmt_dur $((SECONDS - start)))"
        CH_STATUS[strength]="not run: no run of accuracy-mutation yet"
        CH_NOTE[strength]="the job is manual; nothing here starts it"
        return
    fi
    localsha="$(git -C "$REPO_ROOT" rev-parse HEAD 2>/dev/null)"
    for id in $ids; do
        job="$("$TIMEOUT_CMD" -k 5 60 gh run view "$id" --json jobs \
            --jq '[.jobs[] | select(.name | test("accuracy-mutation")) | .conclusion] | first // ""' 2>>"$CUR_LOG")"
        printf 'run %s: job conclusion "%s"\n' "$id" "$job" >>"$CUR_LOG"
        # A run that skipped the manual job says nothing about test strength.
        if [[ -n "$job" && "$job" != "skipped" ]]; then
            found=1
            sha="$("$TIMEOUT_CMD" -k 5 60 gh run view "$id" --json headSha --jq '.headSha' 2>>"$CUR_LOG")"
            created="$("$TIMEOUT_CMD" -k 5 60 gh run view "$id" --json createdAt --jq '.createdAt' 2>>"$CUR_LOG")"
            part_reset
            PARTS_RAN=1
            if [[ "$job" == "success" ]]; then
                :
            else
                PARTS_FAILED=1; PARTS_FAIL_WHY="latest accuracy-mutation run $id concluded $job"
            fi
            local note="run $id, $created, commit ${sha:0:10}"
            [[ "$sha" != "$localsha" ]] && note="$note, not this checkout's commit"
            finish_charter strength "$start" "$note"
            return
        fi
    done
    CH_DUR[strength]="$(fmt_dur $((SECONDS - start)))"
    CH_STATUS[strength]="not run: no completed run of accuracy-mutation in the last 10 runs"
}

charter_restated() {
    local start=$SECONDS part rc skip="" state
    begin_charter restated 6
    part_reset
    state="$(charter_state restated)"
    [[ "$state" == *";"* ]] && skip="${state#*; }"

    if accuracy_has_dir Restated; then
        printf '\n--- restated-figures tests ---\n' >>"$CUR_LOG"
        part="$(mktemp)"
        (cd "$API_DIR" && run_bounded 900 "$part" php -d memory_limit=-1 vendor/bin/pest -c phpunit.accuracy.xml tests/Accuracy/Restated)
        rc=$?
        cat "$part" >>"$CUR_LOG"; rm -f "$part"
        part_result $rc "restated-figures tests" 900
    fi
    if [[ $E2E_UP -eq 1 ]]; then
        printf '\n--- gates.sh e2e-attendance (seeds the local database; the payroll journey needs queue:work) ---\n' >>"$CUR_LOG"
        part="$(mktemp)"
        run_bounded 1800 "$part" bash "$REPO_ROOT/scripts/gates.sh" e2e-attendance
        rc=$?
        cat "$part" >>"$CUR_LOG"; rm -f "$part"
        part_result $rc "gates.sh e2e-attendance" 1800
    fi
    finish_charter restated "$start" "$skip"
}

charter_production() {
    local start=$SECONDS state roles
    begin_charter production 7
    part_reset
    state="$(charter_state production)"
    roles="${state#*fresh sessions: }"
    printf 'fresh saved sessions (roles only): %s\n' "$roles" >>"$CUR_LOG"
    (cd "$WEB_DIR" && env -u PROD_PROBE_SECRETS "$TIMEOUT_CMD" -k 15 1200 npm run test:e2e:production >>"$CUR_LOG" 2>&1)
    part_result $? "npm run test:e2e:production" 1200
    finish_charter production "$start" "sessions used: $roles"
}

# ── Main ─────────────────────────────────────────────────────────────────────

main() {
    if [[ -n "$ONLY" ]]; then
        local n
        IFS=',' read -r -a _names <<<"$ONLY"
        for n in "${_names[@]}"; do
            is_charter "$n" || { printf 'Unknown charter: %s\n' "$n" >&2; usage; exit 2; }
        done
    fi

    if [[ $LIST -eq 1 ]]; then do_list; exit 0; fi

    if ! find_timeout; then
        printf 'Preflight failed: no GNU timeout (or gtimeout) on PATH.\n' >&2
        printf 'Every command of a session needs a deadline, and Windows'"'"' own timeout.exe is not one.\n' >&2
        printf 'Use Git Bash, where /usr/bin/timeout is the GNU one.\n' >&2
        exit 2
    fi

    local stamp
    stamp="$(date -u '+%Y-%m-%d-%H%M')"
    if [[ -z "$OUT_DIR" ]]; then
        OUT_DIR="$API_DIR/storage/logs/accuracy-session/$stamp"
    else
        case "$OUT_DIR" in /*|[A-Za-z]:*) ;; *) OUT_DIR="$PWD/$OUT_DIR" ;; esac
    fi

    probe_environment

    case "$OUT_DIR" in
        "$REPO_ROOT"/*)
            if ! git -C "$REPO_ROOT" check-ignore -q "$OUT_DIR/session-sheet.md"; then
                printf 'Refusing to write into %s: git does not ignore it, so logs would show up as changes.\n' "$OUT_DIR" >&2
                printf 'Use the default (api/storage/logs/accuracy-session/) or a directory outside the repository.\n' >&2
                exit 2
            fi ;;
    esac
    mkdir -p "$OUT_DIR" || { printf 'Cannot create %s\n' "$OUT_DIR" >&2; exit 2; }

    printf 'Accuracy session, %s, commit %s\nOutput: %s\n' "$stamp" "${PRE_SHA:-unknown}" "$OUT_DIR"

    if ! charter_preflight; then
        local c
        for c in "${CHARTERS[@]}"; do
            [[ "$c" == preflight ]] && continue
            CH_STATUS[$c]="not run: preflight failed"
        done
        write_sheet
        printf '\nPreflight failed. Sheet: %s\n' "$OUT_DIR/session-sheet.md"
        exit 2
    fi

    local c st failed=0
    for c in "${CHARTERS[@]}"; do
        [[ "$c" == preflight ]] && continue
        if ! selected "$c"; then CH_STATUS[$c]="not selected"; continue; fi
        st="$(charter_state "$c")"
        case "$st" in
            runs*) "charter_$c" ;;
            *)
                CH_STATUS[$c]="${st%%;*}"
                printf '\n=== %s ===\n  %s\n' "$c" "${CH_STATUS[$c]}"
                ;;
        esac
    done

    for c in "${CHARTERS[@]}"; do
        case "${CH_STATUS[$c]:-}" in failed*) failed=1 ;; esac
    done

    write_sheet

    printf '\n--- summary ---\n'
    for c in "${CHARTERS[@]}"; do
        printf '%-12s %s\n' "$c" "${CH_STATUS[$c]:-not selected}"
    done
    printf '\nSession sheet: %s\n' "$OUT_DIR/session-sheet.md"
    if [[ $failed -eq 1 ]]; then
        printf 'At least one charter that ran failed.\n'
        exit 1
    fi
    printf 'Every charter that ran passed.\n'
    exit 0
}

# Sourcing the file (to test a function) must not start a session.
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    main
fi
