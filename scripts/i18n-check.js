#!/usr/bin/env node
/**
 * i18n gate. Run from the frontend root (`node ../scripts/i18n-check.js` in src/).
 *
 * Catches three failures that are all silent at runtime — nothing throws, the UI
 * just quietly renders the wrong thing:
 *
 *  1. MISSING KEY — t("a.b", "Fallback") where "a.b" is absent from a locale file.
 *     Renders the English fallback in every language. This is how the whole
 *     dashboard stayed English under an Amharic UI.
 *
 *  2. INTERPOLATED FALLBACK — t("a.b", `${n} items`). useT() returns the
 *     translation and ignores the fallback once the key exists, so the values
 *     vanish. This dropped the employee count on /employees. Use the third
 *     argument instead: t("a.b", ":count items", { count: n }).
 *
 *  3. LOCALE DRIFT — en.json and am.json disagree on which keys exist, or a
 *     :placeholder present in one locale is missing from the other (which would
 *     drop the value in that language only).
 *
 *  4. PUBLIC FALLBACK DRIFT — on a page reachable from the public site, a
 *     t("a.b", "Fallback") whose fallback differs from en.json's value.
 *
 *     Only `am.json` is imported eagerly, so the server renders /en/* from these
 *     fallback strings and the browser swaps in en.json a moment after
 *     hydration. That is what lets an English page be prerendered without
 *     shipping a 186 KB dictionary — and it is only invisible while the two
 *     agree. Let them drift and the public page rewrites itself under the
 *     reader, and a crawler indexes text no visitor ends up seeing.
 *
 *     Scoped to the import closure of the public routes, computed below rather
 *     than guessed from directory names: the dashboard has no such constraint,
 *     because it is never prerendered in English.
 */
const fs = require("fs");
const path = require("path");

const SRC = "src";
const LOCALES = path.join(SRC, "lib/i18n/locales");

function loadLocale(name) {
  return JSON.parse(
    fs.readFileSync(path.join(LOCALES, `${name}.json`), "utf8"),
  );
}

function sourceFiles(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (!/node_modules|\.next|__snapshots__/.test(p)) sourceFiles(p, acc);
    } else if (
      /\.(tsx|ts)$/.test(e.name) &&
      !/\.test\.|[\\/]test[\\/]/.test(p)
    ) {
      acc.push(p);
    }
  }
  return acc;
}

/**
 * Every module a public page can reach, followed through `@/` and relative
 * imports from the marketing route entry points.
 *
 * A directory allow-list would have been shorter and wrong: the pricing page's
 * text comes as much from `components/layouts/marketing-footer.tsx` and the
 * shared language switcher as from its own file, and the next shared component
 * someone reuses there would silently escape the check.
 */
function resolveImport(fromFile, spec) {
  let base;
  if (spec.startsWith("@/")) base = path.join(SRC, spec.slice(2));
  else if (spec.startsWith("."))
    base = path.resolve(path.dirname(fromFile), spec);
  else return null; // a package, not ours

  for (const candidate of [
    base,
    `${base}.tsx`,
    `${base}.ts`,
    path.join(base, "index.tsx"),
    path.join(base, "index.ts"),
  ]) {
    if (fs.existsSync(candidate) && fs.statSync(candidate).isFile())
      return candidate;
  }
  return null;
}

const IMPORT_RE =
  /(?:^|\n)\s*(?:import|export)[\s\S]*?from\s*["']([^"']+)["']/g;

function importClosure(entries) {
  const seen = new Set();
  const queue = [...entries];

  while (queue.length) {
    const file = queue.pop();
    if (seen.has(file)) continue;
    seen.add(file);

    const src = fs.readFileSync(file, "utf8");
    let m;
    IMPORT_RE.lastIndex = 0;
    while ((m = IMPORT_RE.exec(src))) {
      const resolved = resolveImport(file, m[1]);
      if (resolved && !seen.has(resolved)) queue.push(resolved);
    }
  }
  return seen;
}

const en = loadLocale("en");
const am = loadLocale("am");
const files = sourceFiles(SRC);

const PUBLIC_ROOT = path.join(SRC, "app", "(marketing)");
const publicEntries = files.filter(
  (f) =>
    f.startsWith(PUBLIC_ROOT + path.sep) && /[\\/](page|layout)\.tsx$/.test(f),
);
const publicFiles = importClosure(publicEntries);

const KEY_RE = /\bt\(\s*["']([a-z0-9_]+(?:\.[a-z0-9_]+)+)["']/gi;
const TPL_RE =
  /\bt\(\s*["']([a-z0-9_]+(?:\.[a-z0-9_]+)+)["']\s*,\s*`([^`]*)`/gis;

const missing = [];
const interpolated = [];
const fallbackDrift = [];

// t("a.b", "Fallback") — the fallback must be a plain string literal for this
// to mean anything; template literals are rule 2's problem, not this one.
const FALLBACK_RE =
  /\bt\(\s*["']([a-z0-9_]+(?:\.[a-z0-9_]+)+)["']\s*,\s*"((?:[^"\\]|\\.)*)"/gis;

for (const file of files) {
  const src = fs.readFileSync(file, "utf8");
  const rel = file.replace(/\\/g, "/");

  let m;
  while ((m = KEY_RE.exec(src))) {
    const key = m[1];
    const gaps = [];
    if (en[key] === undefined) gaps.push("en");
    if (am[key] === undefined) gaps.push("am");
    if (gaps.length) {
      const line = src.slice(0, m.index).split("\n").length;
      missing.push(`${rel}:${line}  ${key}  (missing in ${gaps.join(", ")})`);
    }
  }

  while ((m = TPL_RE.exec(src))) {
    if (!m[2].includes("${")) continue;
    const line = src.slice(0, m.index).split("\n").length;
    interpolated.push(`${rel}:${line}  ${m[1]}`);
  }

  if (!publicFiles.has(file)) continue;

  FALLBACK_RE.lastIndex = 0;
  while ((m = FALLBACK_RE.exec(src))) {
    const key = m[1];
    const fallback = m[2].replace(/\\(["\\])/g, "$1");
    if (en[key] === undefined || en[key] === fallback) continue;
    const line = src.slice(0, m.index).split("\n").length;
    fallbackDrift.push(
      `${rel}:${line}  ${key}\n      en.json:  ${JSON.stringify(en[key])}\n      fallback: ${JSON.stringify(fallback)}`,
    );
  }
}

// Locale drift
const enKeys = Object.keys(en);
const amKeys = new Set(Object.keys(am));
const onlyEn = enKeys.filter((k) => !amKeys.has(k));
const onlyAm = [...amKeys].filter((k) => en[k] === undefined);

const placeholders = (s) =>
  (String(s).match(/:[a-z_]+/gi) || []).sort().join(",");
const mismatched = enKeys
  .filter((k) => amKeys.has(k))
  .filter((k) => placeholders(en[k]) !== placeholders(am[k]));

const problems = [];
if (missing.length)
  problems.push([`${missing.length} key(s) used but not defined`, missing]);
if (interpolated.length)
  problems.push([
    `${interpolated.length} interpolated fallback(s) — use t(key, ":x", { x })`,
    interpolated,
  ]);
if (fallbackDrift.length)
  problems.push([
    `${fallbackDrift.length} public-page fallback(s) that disagree with en.json — ` +
      `the prerendered English page would rewrite itself after hydration`,
    fallbackDrift,
  ]);
if (onlyEn.length)
  problems.push([`${onlyEn.length} key(s) in en but not am`, onlyEn]);
if (onlyAm.length)
  problems.push([`${onlyAm.length} key(s) in am but not en`, onlyAm]);
if (mismatched.length)
  problems.push([
    `${mismatched.length} key(s) whose :placeholders differ between locales`,
    mismatched.map(
      (k) => `${k}  en(${placeholders(en[k])}) am(${placeholders(am[k])})`,
    ),
  ]);

if (!problems.length) {
  console.log(
    `i18n OK — ${files.length} files (${publicFiles.size} reachable from a public page), ` +
      `${enKeys.length} keys, en/am in sync, no interpolated fallbacks, no public fallback drift.`,
  );
  process.exit(0);
}

for (const [title, items] of problems) {
  console.error(`\n${title}:`);
  for (const item of items.slice(0, 40)) console.error(`  ${item}`);
  if (items.length > 40) console.error(`  ...and ${items.length - 40} more`);
}
process.exit(1);
