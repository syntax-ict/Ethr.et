#!/usr/bin/env bash
#
# Authenticated Lighthouse against the local-production rehearsal.
#
#   scripts/local-production/up.sh          # once: builds, serves on :8081
#   (cd .local-production/app/api && php artisan db:seed --class=DemoTenantSeeder --force)
#                                           # once: up.sh seeds production data only
#   scripts/lighthouse-authenticated.sh     # desktop and mobile, six pages
#
# Set PW_CHANNEL=chrome on a machine without Playwright's downloaded browser.
#
# Why this exists. `.lighthouserc.cjs` measures public pages only and says so:
# Lighthouse has no session, and until 2026-10-20 nothing had ever measured
# /dashboard, /employees or /payroll as a signed-in person sees them. The
# redesign plan (docs/FRONTEND_REDESIGN_PLAN.md, Phase 1) makes that baseline
# a precondition of any foundation work, so the numbers can be compared later.
#
# How. Playwright's global setup signs in once per role and saves the browser
# state (e2e/.auth/admin.json, gitignored). `src/scripts/lhci-prime.cjs`
# restores that state before every Lighthouse run. No credential is typed
# here; the demo accounts are the seeded ones in docs/LOCAL_SETUP.md. Results
# land in src/.lighthouseci/authenticated-* (gitignored) and a summary is
# printed; record the summary in
# docs/audit/BASELINE.md by hand, dated, as the public figures are (section 24
# holds the first set).
#
# This proves nothing about the host: same caveat as scripts/local-production.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB="$REPO_ROOT/src"
BASE_URL="${BASE_URL:-http://demo.localhost:8081}"
PLATFORM_BASE_URL="${PLATFORM_BASE_URL:-http://admin.localhost:8081}"
STATE="$WEB/e2e/.auth/admin.json"

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

say "reachability: $BASE_URL/login"
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$BASE_URL/login" || true)"
[ "$code" = "200" ] || { printf 'got %s; start the rehearsal first (scripts/local-production/up.sh)\n' "$code"; exit 1; }

say "sign in through Playwright's global setup (writes $STATE)"
# The auth spec is the smallest run that triggers global-setup; its own result
# is not what we are after, so it is allowed to fail without stopping this.
(cd "$WEB" && BASE_URL="$BASE_URL" PLATFORM_BASE_URL="$PLATFORM_BASE_URL" \
    npx playwright test e2e/auth.spec.ts --project=chromium-desktop --reporter=line) || true
[ -s "$STATE" ] || { printf 'no storage state at %s; sign-in failed\n' "$STATE"; exit 1; }

export LHCI_STATE="$STATE"

summarise() {
    local dir="$1"
    node -e '
const fs = require("fs"), path = require("path");
const dir = process.argv[1];
const manifest = JSON.parse(fs.readFileSync(path.join(dir, "manifest.json"), "utf8"));
const byUrl = {};
for (const run of manifest) {
  const r = JSON.parse(fs.readFileSync(run.jsonPath, "utf8"));
  const a = r.audits;
  (byUrl[run.url] ??= []).push({
    perf: Math.round((r.categories.performance?.score ?? 0) * 100),
    lcp: Math.round(a["largest-contentful-paint"].numericValue),
    tbt: Math.round(a["total-blocking-time"].numericValue),
    cls: Number(a["cumulative-layout-shift"].numericValue.toFixed(3)),
    kb: Math.round((a["total-byte-weight"]?.numericValue ?? 0) / 1024),
  });
}
const med = (xs) => { const s = [...xs].sort((p, q) => p - q); return s[Math.floor(s.length / 2)]; };
console.log("| Route | Perf | LCP ms | TBT ms | CLS | Weight KB |");
console.log("|---|---|---|---|---|---|");
for (const [url, runs] of Object.entries(byUrl)) {
  const p = new URL(url).pathname;
  console.log(`| ${p} | ${med(runs.map(r => r.perf))} | ${med(runs.map(r => r.lcp))} | ${med(runs.map(r => r.tbt))} | ${med(runs.map(r => r.cls))} | ${med(runs.map(r => r.kb))} |`);
}
' "$dir"
}

for preset in desktop mobile; do
    out="$WEB/.lighthouseci/authenticated-$preset"
    rm -rf "$out"
    say "lighthouse, $preset preset, 3 runs per page"
    (cd "$WEB" && LHCI_BASE_URL="$BASE_URL" LHCI_PRESET="$preset" LHCI_OUTPUT_DIR="$out" \
        node node_modules/@lhci/cli/src/cli.js collect --config=.lighthouserc.auth.cjs >/dev/null \
        && LHCI_OUTPUT_DIR="$out" node node_modules/@lhci/cli/src/cli.js upload --config=.lighthouserc.auth.cjs >/dev/null)
    say "median of 3, $preset"
    summarise "$out"
done

printf '\nRecord these in docs/audit/BASELINE.md with the date and preset, as the public figures are.\n'
