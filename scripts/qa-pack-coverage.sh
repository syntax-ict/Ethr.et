#!/usr/bin/env bash
#
# Which merged changes does the production testing pack not cover yet?
#
#   scripts/qa-pack-coverage.sh              # baseline..origin/main
#   scripts/qa-pack-coverage.sh <ref>        # baseline..<ref>, e.g. a release tag
#   scripts/qa-pack-coverage.sh --all        # include docs, chore, ci, test and build changes
#
# The pack (docs/production testing/) is written by hand from the code, so it
# goes stale one merged PR at a time and nothing says so: by 2026-11-07 it was
# about a hundred PRs behind main. This lists every PR merged on the first-parent
# line since the pack's baseline commit whose number is cited nowhere in the pack.
#
# The baseline is the commit named in the README marker
#   <!-- qa-pack-baseline: <sha> -->
# Move it forward when the pack is brought up to date.
#
# A PR in this list is not necessarily a gap: a refactor or a dependency bump has
# nothing a tester can see. Read the title and decide. A user-visible change gets
# a case, and the case names the PR in 01-TEST-PLAN.md section 2.3.
#
# READ-ONLY. Exit status: 0 when nothing is uncovered, 1 when something is,
# 2 when the repository or the marker is unusable.

set -uo pipefail

ALL=0
REF=""
for arg in "$@"; do
  case "$arg" in
    --all) ALL=1 ;;
    -h|--help) sed -n '2,23p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) REF="$arg" ;;
  esac
done

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "not inside the ETHR repository" >&2; exit 2; }
cd "$REPO_ROOT" || exit 2

PACK="docs/production testing"
README="$PACK/README.md"
[ -f "$README" ] || { echo "$README not found" >&2; exit 2; }

BASE="$(sed -n 's/.*<!-- qa-pack-baseline: \([0-9a-f]\{7,40\}\) -->.*/\1/p' "$README" | head -1)"
[ -n "$BASE" ] || { echo "no <!-- qa-pack-baseline: <sha> --> marker in $README" >&2; exit 2; }
git rev-parse --verify -q "$BASE^{commit}" >/dev/null || { echo "baseline $BASE is not a commit here (fetch?)" >&2; exit 2; }

if [ -z "$REF" ]; then
  REF="origin/main"
fi
git rev-parse --verify -q "$REF^{commit}" >/dev/null || { echo "$REF is not a commit here (fetch?)" >&2; exit 2; }

uncovered=0
skipped=0
# Every PR number the pack cites, read once. The loop below uses bash built-ins
# only: a process per commit made this take over a minute on Windows.
declare -A cited=()
while read -r n; do
  cited["$n"]=1
done < <(grep -rohE '#[0-9]+' "$PACK" | sort -u)

pr_re='#([0-9]+)'
skip_re='^(docs|chore|ci|test|build)(\(|:)'
# One record per commit: sha, subject, body, separated by 0x1f and ended by 0x1e.
while IFS=$'\x1f' read -r -d $'\x1e' sha subject body; do
  sha="${sha#$'\n'}"
  # A merge commit's subject names the branch, not the change; its body's first line does.
  title="$subject"
  if [[ "$subject" == "Merge pull request"* ]]; then
    body="${body#"${body%%[![:space:]]*}"}"
    title="${body%%$'\n'*}"
  fi
  if [ "$ALL" -eq 0 ] && [[ "$title" =~ $skip_re ]]; then
    skipped=$((skipped + 1))
    continue
  fi
  rest="$subject"
  while [[ "$rest" =~ $pr_re ]]; do
    n="#${BASH_REMATCH[1]}"
    rest="${rest#*"$n"}"
    if [ -z "${cited[$n]:-}" ]; then
      printf '%s  %s  %s\n' "$n" "${sha:0:10}" "$title"
      uncovered=$((uncovered + 1))
    fi
  done
done < <(git log --first-parent --format='%H%x1f%s%x1f%b%x1e' "$BASE..$REF")

echo
left_out=""
if [ "$skipped" -gt 0 ]; then
  left_out="; $skipped docs/chore/ci/test/build change(s) left out (--all shows them)"
fi
echo "baseline ${BASE:0:10}..$REF: $uncovered PR(s) not cited in the pack$left_out"
[ "$uncovered" -eq 0 ]
