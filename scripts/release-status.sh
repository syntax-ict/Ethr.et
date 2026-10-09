#!/usr/bin/env bash
#
# Where is each copy of ETHR? One answer for the four places a change passes through:
#
#   local checkout  ->  GitHub main  ->  production branch (+ tag)  ->  live site
#
#   scripts/release-status.sh                         # local, main, production
#   scripts/release-status.sh https://<APP_DOMAIN>    # ...and the live site
#   ETHR_BASE_URL=http://localhost:8081 scripts/release-status.sh
#                                                     # ...and the local production
#                                                     #    rehearsal (scripts/local-production)
#
# The live check reads <url>/release.json. build-release.sh writes it into the
# document root of every release, so the site reports the commit it was built
# from. A site deployed before release.json existed reports nothing, and that is
# shown as unknown rather than guessed.
#
# READ-ONLY. It fetches, and changes nothing else: no checkout, no merge, no
# deploy. The URL is an argument, never a literal in this file (HARD RULE 2,
# docs/deployment/SHARED-HOSTING-CONTRACT.md).
#
# Exit status: 0 when every place checked agrees (a place that could not be
# checked is reported as unknown, not failed), 1 when something is behind or
# pending (the output says what to do), 2 when the repository itself is unusable.

set -uo pipefail

URL="${1:-${ETHR_BASE_URL:-}}"
URL="${URL%/}"
REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "not inside the ETHR repository" >&2; exit 2; }
cd "$REPO_ROOT" || exit 2

ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
todo() { printf '  \033[33m!\033[0m %s\n' "$1"; PENDING=1; }
unk()  { printf '  \033[90m?\033[0m %s\n' "$1"; UNKNOWN=1; }
PENDING=0
UNKNOWN=0

if ! git fetch --quiet --prune --tags origin 2>/dev/null; then
  unk "could not reach GitHub; everything below uses refs cached at the last fetch"
fi

MAIN="$(git rev-parse --verify -q origin/main)" || { echo "origin/main not found" >&2; exit 2; }
echo "GitHub main    ${MAIN:0:12}  $(git log -1 --format=%s "$MAIN" | cut -c1-70)"

# 1 — local checkout
echo
echo "Local ($(git branch --show-current 2>/dev/null || echo detached))"
if git rev-parse --verify -q refs/heads/main >/dev/null; then
  read -r AHEAD BEHIND < <(git rev-list --left-right --count main...origin/main)
  if [ "$AHEAD" = 0 ] && [ "$BEHIND" = 0 ]; then
    ok "main matches GitHub"
  elif [ "$AHEAD" = 0 ]; then
    todo "main is $BEHIND behind GitHub: git switch main && git pull --ff-only"
  else
    todo "main has $AHEAD commit(s) GitHub lacks: main is PR-only, so move them to a branch and open a PR"
  fi
else
  unk "no local main branch"
fi
DIRTY="$(git status --porcelain | wc -l | tr -d ' ')"
[ "$DIRTY" = 0 ] && ok "working tree clean" || echo "    $DIRTY uncommitted change(s) (not in any release until committed and merged)"

# 2 — the production branch, which release.yml publishes after the gates pass on main
echo
echo "Production branch"
PROD="$(git rev-parse --verify -q origin/production)" || { unk "no production branch on GitHub"; exit 2; }
SRC="$(git log -1 --format=%s "$PROD" | sed -n 's/^release: \([0-9a-f]\{7,40\}\)$/\1/p')"
TAG="$(git tag -l --points-at "$PROD" 'v*' | sort -V | tail -1)"
echo "    ${PROD:0:12}  release of main@${SRC:-?}  ${TAG:-(untagged)}"
if [ -z "$SRC" ]; then
  unk "the production head is not a release commit; release.yml is the only thing that should write it"
elif [ "${MAIN:0:${#SRC}}" = "$SRC" ]; then
  ok "built from the current main"
else
  BEHIND_MAIN="$(git rev-list --count "$SRC".."$MAIN" 2>/dev/null || echo '?')"
  todo "built from main@$SRC, $BEHIND_MAIN commit(s) behind. Quality gates and the Plesk release are running, or one failed: check the Actions tab"
fi

# 3 — the running site
echo
if [ -z "$URL" ]; then
  echo "Live site: not checked (pass https://<APP_DOMAIN>, or set ETHR_BASE_URL)"
else
  echo "Live site  $URL"
  BODY="$(curl -fsS --max-time 20 -H 'Cache-Control: no-cache' "$URL/release.json?t=$(date +%s)" 2>/dev/null)"
  LIVE="$(printf '%s' "$BODY" | sed -n 's/.*"commit":"\([0-9a-f]\{40\}\)".*/\1/p')"
  if [ -z "$LIVE" ]; then
    unk "no release.json at $URL. Either the site predates it, or the site is not reachable from here"
  else
    LIVE_REL="$(git log origin/production --format='%H %s' | awk -v s="release: ${LIVE:0:12}" '$0 ~ s"$" {print $1; exit}')"
    LIVE_TAG="$( [ -n "$LIVE_REL" ] && git tag -l --points-at "$LIVE_REL" 'v*' | sort -V | tail -1 )"
    echo "    running main@${LIVE:0:12}  ${LIVE_TAG:-(untagged)}  built $(printf '%s' "$BODY" | sed -n 's/.*"built_at":"\([^"]*\)".*/\1/p')"
    if [ -n "$SRC" ] && [ "${LIVE:0:${#SRC}}" = "$SRC" ]; then
      ok "running the latest release"
    else
      todo "running an older release than production: press Deploy in Plesk Git, then do the release steps (docs/deployment/PLESK-GO-LIVE.md)"
    fi
  fi
fi

echo
if [ "$PENDING" = 1 ]; then echo "Not in step: see the ! lines above."; exit 1; fi
if [ "$UNKNOWN" = 1 ]; then echo "In step where it could be checked; see the ? lines."; exit 0; fi
echo "Local, GitHub, production and the live site are in step."
