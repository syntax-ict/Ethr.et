#!/usr/bin/env node
/**
 * Markdown link checker.
 *
 * Every relative link in every tracked .md file must resolve to a file that
 * exists. Exits non-zero listing the ones that do not.
 *
 * This exists because the documentation had rotted in a way that reading could
 * not catch. Phase 1 found 33 broken links: ten phase documents pointed at
 * `ENTERPRISE_ROADMAP.md` as a sibling when it lives one directory up, and
 * twenty cited audit files (`ETHR_AUDIT.md`, `ETHR_AUDIT_2026-08-14.md`) that
 * are not in the tree and, as far as git history shows, never were. A header
 * whose only job was to route readers to current truth had been routing them to
 * nothing, in ten files, unnoticed.
 *
 * Written to be wired into scripts/gates.sh (Phase 2/3) alongside i18n-check.js
 * so it cannot happen again.
 *
 * NOTE — what this deliberately does NOT catch: backticked prose references
 * like `DEPLOYMENT.md`. There are roughly 150 of those across 37 files, and
 * they are the reason docs/decisions/DECISIONS.md D-002 defers reorganizing the
 * documentation tree: a moved file leaves them rendered, plausible and wrong.
 * Teaching this script to resolve them would make that move safe and is the
 * natural next step if the reorganization is ever taken up.
 *
 *   node scripts/docs-link-check.js
 */

const fs = require("fs");
const path = require("path");

const ROOT = path.resolve(__dirname, "..");
const SKIP = new Set(["node_modules", "vendor", ".git", ".next", "dist", "coverage"]);

// [text](target)  — ignore the #anchor, we only check the file exists.
const LINK = /\[[^\]]*\]\(([^)\s#]+)(?:#[^)]*)?\)/g;

/** @returns {string[]} every .md file under dir, recursively */
function markdownFiles(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(entry.name)) continue;
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) out.push(...markdownFiles(full));
    else if (entry.name.endsWith(".md")) out.push(full);
  }
  return out;
}

const files = markdownFiles(ROOT);
const broken = [];
let checked = 0;

for (const file of files) {
  const body = fs.readFileSync(file, "utf8");
  const from = path.dirname(file);

  for (const match of body.matchAll(LINK)) {
    const target = match[1];

    // External and protocol links are somebody else's problem.
    if (/^(https?:|mailto:|tel:)/.test(target)) continue;
    // Root-relative links are not meaningful in a repo checkout.
    if (target.startsWith("/")) continue;

    checked++;
    if (!fs.existsSync(path.resolve(from, target))) {
      broken.push(`${path.relative(ROOT, file)} -> ${target}`);
    }
  }
}

console.log(`docs-link-check: ${checked} relative links across ${files.length} files`);

if (broken.length > 0) {
  console.error(`\n\x1b[31m${broken.length} broken link(s):\x1b[0m`);
  for (const b of broken) console.error(`  ${b}`);
  console.error(
    "\nA link to a file that is not there is worse than no link: it reads as" +
      "\nevidence. Fix the path, or remove the citation.",
  );
  process.exit(1);
}

console.log("\x1b[32mall links resolve\x1b[0m");
