#!/usr/bin/env node
//
// Assert that `out/` is a usable Bronze shared-hosting artifact.
//
// WHY THIS EXISTS. Until 2026-09-27 no gate built the frontend at all —
// `gates.sh frontend` runs i18n, Prettier, ESLint, tsc and Vitest, none of which
// invoke `next build`. So the artifact the production target actually serves was
// verified exactly once, by hand, on one Windows machine (docs/audit/BASELINE.md
// §20g), and `src/lib/build-target.ts` claimed the ETHR_TARGET switch made the
// export "runnable in CI, which is what turns 'verified once by hand' into
// something a gate can check" while nothing checked it.
//
// The failure modes this catches are all silent ones. A static export that
// quietly falls back to a server build still exits 0. A fifth `[id]` route added
// without its `.htaccess` rule still exits 0. A missing `404.html` still exits 0
// — and then every unknown URL on the host has no error document to serve.
//
// WHAT IT DOES NOT DO. It does not fetch anything and it is not evidence about
// Ethio Telecom's Apache. Whether `[F,L]` is honoured there is M1 and still open.
// This asserts the build produced what the deployment expects, nothing more.
//
// Usage:
//   ETHR_TARGET=shared-hosting npm run build
//   npm run verify:static-export
//
// Exit codes: 0 pass · 1 assertion failed · 2 usage or missing-build error.

import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const WEB_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const OUT = path.join(WEB_DIR, "out");

/**
 * The four `[id]` routes, and the sentinel they are built under.
 *
 * This list must equal the four route prefixes in
 * `docs/deployment/shared-hosting/.htaccess` group 1. A fifth dynamic route
 * needs a line in both, or it 404s on the host while the build stays green —
 * which is precisely the class of defect this script exists to make loud.
 * `src/lib/static-export.ts` owns the sentinel value.
 */
const ID_ROUTES = ["employees", "payroll", "devices", "admin/tenants"];
const SENTINEL = "__id__";

/** Routes whose absence breaks a documented behaviour rather than one page. */
const REQUIRED_FILES = [
  // ErrorDocument 404 target. Without it Apache falls back to its own page and
  // the measured "/nope is a real 404" result in BASELINE.md §21d stops holding.
  "404.html",
  "index.html",
  // The /admin host boundary in .htaccess group 0 denies this from tenant hosts;
  // it has to exist for that deny to be meaningful rather than vacuous.
  "admin.html",
  "login.html",
  // Both gained `force-static` specifically to unblock the export.
  "robots.txt",
  "sitemap.xml",
];

/** Build outputs that prove this is NOT a static export. */
const FORBIDDEN = [
  // `output: "export"` emits no server entry point. If one of these exists the
  // build silently produced a Node target, which this host cannot run.
  "server.js",
  "index.js",
  "standalone",
];

const failures = [];
const notes = [];

function check(condition, message) {
  if (!condition) failures.push(message);
}

function countFiles(dir) {
  let n = 0;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    n += entry.isDirectory() ? countFiles(path.join(dir, entry.name)) : 1;
  }
  return n;
}

// ── preconditions ───────────────────────────────────────────────────────────

if (!fs.existsSync(OUT) || !fs.statSync(OUT).isDirectory()) {
  console.error(
    `verify-static-export: ${OUT} does not exist.\n` +
      "Run `ETHR_TARGET=shared-hosting npm run build` first. Without that variable the\n" +
      "build is `standalone` and emits .next/, not out/ — which is itself the thing this\n" +
      "script is here to notice.",
  );
  process.exit(2);
}

// ── the export is a real export ─────────────────────────────────────────────

for (const forbidden of FORBIDDEN) {
  check(
    !fs.existsSync(path.join(OUT, forbidden)),
    `out/${forbidden} exists — this is a server build, not a static export. ` +
      "Check that ETHR_TARGET=shared-hosting reached next.config.ts.",
  );
}

// A standalone build leaves .next/standalone behind. Its presence alongside out/
// is not fatal on a developer machine that built both targets, so this is a note
// rather than a failure — but in CI the export job builds only one target.
if (fs.existsSync(path.join(WEB_DIR, ".next", "standalone"))) {
  notes.push(
    ".next/standalone is also present — a standalone build ran in this workspace too.",
  );
}

// ── the files the deployment names ──────────────────────────────────────────

for (const file of REQUIRED_FILES) {
  check(fs.existsSync(path.join(OUT, file)), `missing out/${file}`);
}

// ── the four sentinel shells ────────────────────────────────────────────────
//
// One shell per route, not one per entity: ids are tenant data created after the
// build, so there is nothing to enumerate. `.htaccess` group 1 hands these to
// every id and `lib/hooks/useRouteId.ts` reads the real id back out of the URL.

for (const route of ID_ROUTES) {
  const html = path.join(OUT, route, `${SENTINEL}.html`);
  check(
    fs.existsSync(html),
    `missing out/${route}/${SENTINEL}.html — the shell .htaccess group 1 rewrites to. ` +
      "Without it every entity page on that route 404s on the host.",
  );

  // The RSC payload the second group-1 rule serves. A client-side navigation
  // into the route asks for this; without it Next cannot hydrate the transition.
  check(
    fs.existsSync(path.join(OUT, route, `${SENTINEL}.txt`)),
    `missing out/${route}/${SENTINEL}.txt — the RSC payload for that shell.`,
  );
}

// And the inverse: no real id may be prerendered. `generateStaticParams` returns
// one sentinel precisely because ids are tenant data, so a ULID in out/ means
// tenant data reached the build.
//
// Matched on the ULID shape, NOT on "anything that is not the sentinel". These
// prefixes legitimately contain static sibling routes — /employees/new,
// /payroll/payslips, /devices/dashboard and three more — and an equality check
// here flags all six. That over-strict first draft is what surfaced the
// .htaccess group-1 defect fixed on 2026-09-27, so it earned its keep, but the
// property actually worth asserting is the narrow one.
const ULID = /^[0-9A-HJKMNP-TV-Z]{26}\.html$/;

for (const route of ID_ROUTES) {
  const dir = path.join(OUT, route);
  if (!fs.existsSync(dir)) continue;

  for (const entry of fs.readdirSync(dir)) {
    check(
      !ULID.test(entry),
      `out/${route}/${entry} looks like a prerendered ULID. Ids are tenant data; ` +
        "generateStaticParams must return only the sentinel.",
    );
  }

  // Every static sibling needs a real .html of its own, or .htaccess group 1
  // falls back to the entity shell for it. Group 1's second condition is what
  // makes that fallback conditional; this asserts the file it tests for exists.
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (!entry.isDirectory() || entry.name.startsWith("__")) continue;
    check(
      fs.existsSync(path.join(dir, `${entry.name}.html`)),
      `out/${route}/${entry.name}/ exists but out/${route}/${entry.name}.html does not — ` +
        "so .htaccess group 1 will serve the entity shell for that route.",
    );
  }
}

// ── static assets ───────────────────────────────────────────────────────────

const nextDir = path.join(OUT, "_next");
check(
  fs.existsSync(nextDir),
  "missing out/_next — the JS/CSS bundle. .htaccess excludes /_next from the " +
    "group-0 redirect precisely because it must be served as files.",
);

if (fs.existsSync(nextDir)) {
  const assets = countFiles(nextDir);
  check(assets > 20, `out/_next holds only ${assets} files, which cannot be a full bundle`);
  notes.push(`out/_next: ${assets} files`);
}

// A whole-artifact floor. BASELINE.md §20g measured 689 files on 2026-09-26.
// This is deliberately a floor and not an equality: the count legitimately moves
// when a route or a chunk is added, and pinning it exactly would produce a gate
// that fails for correct changes. What it catches is a build that emitted a
// fraction of the site — the same failure shape as `vendor/bin/pest` collecting
// 21 of 132 classes and exiting 0 green.
const total = countFiles(OUT);
check(
  total >= 400,
  `out/ holds ${total} files. BASELINE.md §20g measured 689 on 2026-09-26; a count ` +
    "this low means the export stopped early rather than failed.",
);
notes.push(`out/: ${total} files total`);

// ── report ──────────────────────────────────────────────────────────────────

for (const note of notes) console.log(`verify-static-export: ${note}`);

if (failures.length > 0) {
  console.error("\nverify-static-export: FAILED");
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}

console.log(
  `verify-static-export: OK — ${ID_ROUTES.length} sentinel shells, ` +
    `${REQUIRED_FILES.length} required files, no server entry point.`,
);
console.log(
  "verify-static-export: this is a build-artifact check. It is NOT host verification —\n" +
    "verify-static-export: M1 ([F,L] honoured on Ethio Telecom's Apache) is still open.",
);
