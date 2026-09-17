#!/usr/bin/env node
/**
 * i18n gate. Run from the frontend root (`node ../scripts/i18n-check.js` in src/).
 *
 * Catches five failures that are all silent at runtime — nothing throws, the UI
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
 *  4. PUBLIC KEY OUTSIDE THE SHIPPED SUBSET — a t() call on a public page whose
 *     key is not covered by PUBLIC_KEY_PREFIXES.
 *
 *     /am/* and /en/* are prerendered, and only `am.json` is loaded eagerly, so
 *     the layout hands the browser a ~6.7 KB projection of the locale's
 *     dictionary to register before the first client render (see
 *     lib/i18n/public-keys.ts). A key outside those prefixes is missing from
 *     that projection, so the server renders the real sentence and the browser's
 *     first render produces the raw key — React then discards the server's HTML
 *     and paints `marketing.faq_page.what_is_q` until the lazy chunk lands.
 *
 *     Only calls with **no** string fallback are checked. A t("x.y", "Text")
 *     outside the projection is safe: the browser's first render produces
 *     "Text", the server produced en.json's value, and check 5 keeps those
 *     equal. It is the seventeen template-literal keys on the public pages —
 *     t(`marketing.features.${key}`), which cannot carry an inline fallback —
 *     that have nothing to fall back to. They are checked by their static head,
 *     which is what the projection has to cover.
 *
 *  5. PUBLIC FALLBACK DRIFT — on a page reachable from the public site, a
 *     t("a.b", "Fallback") whose fallback differs from en.json's value.
 *
 *     The fallback is what renders if the key ever escapes the projection above,
 *     so it is the last line of defence for text a crawler reads. Two English
 *     sources for one string is already one too many; two that disagree means
 *     one of them is wrong and nobody can tell which.
 *
 *  Checks 4 and 5 are scoped to the import closure of the public routes,
 *  computed below rather than guessed from directory names: the dashboard has no
 *  such constraint, because it is never prerendered in a non-default locale.
 */
const fs = require("fs");
const path = require("path");

const SRC = "src";
const LOCALES = path.join(SRC, "lib/i18n/locales");

/**
 * Read out of the TypeScript source rather than duplicated here, so the list the
 * layout ships and the list this gate enforces cannot drift apart — which would
 * make the gate green while the page repainted with raw keys.
 */
function publicKeyPrefixes() {
  const src = fs.readFileSync(
    path.join(SRC, "lib/i18n/public-keys.ts"),
    "utf8",
  );
  const block = src.match(/PUBLIC_KEY_PREFIXES\s*=\s*\[([\s\S]*?)\]/);
  if (!block) {
    console.error("Could not read PUBLIC_KEY_PREFIXES from public-keys.ts");
    process.exit(1);
  }
  return [...block[1].matchAll(/"([^"]+)"/g)].map((m) => m[1]);
}

const PUBLIC_PREFIXES = publicKeyPrefixes();

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
  // path.join, not path.resolve: `files` holds paths relative to the frontend
  // root, and an absolute path would never match one of them — so every module
  // reached through a relative import silently fell out of the closure, which
  // is most of the page bodies these two checks exist for.
  else if (spec.startsWith(".")) base = path.join(path.dirname(fromFile), spec);
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
const outsideSubset = [];

// The literal head of a t() key: the whole thing for "a.b", and everything
// before the first interpolation for `a.b.${x}`.
const KEY_HEAD_RE = /\bt\(\s*(["'`])([^"'`$]*)/g;

/**
 * True when this t() call passes a plain string as its second argument.
 *
 * Scans from the key literal's closing delimiter rather than pattern-matching
 * the whole call, so it is not confused by an interpolated key.
 */
function hasStringFallback(src, matchIndex, quote) {
  const keyStart = src.indexOf(quote, matchIndex);
  let i = keyStart + 1;
  while (i < src.length) {
    if (src[i] === "\\") {
      i += 2;
      continue;
    }
    if (src[i] === quote) break;
    i += 1;
  }
  const after = src.slice(i + 1, i + 40);
  return /^\s*,\s*["']/.test(after);
}

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

  KEY_HEAD_RE.lastIndex = 0;
  while ((m = KEY_HEAD_RE.exec(src))) {
    const head = m[2];
    if (!head.includes(".")) continue; // not a translation key
    if (PUBLIC_PREFIXES.some((prefix) => head.startsWith(prefix))) continue;
    if (hasStringFallback(src, m.index, m[1])) continue;
    const line = src.slice(0, m.index).split("\n").length;
    outsideSubset.push(`${rel}:${line}  ${head}`);
  }

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
if (outsideSubset.length)
  problems.push([
    `${outsideSubset.length} public-page key(s) outside PUBLIC_KEY_PREFIXES — ` +
      `they are not in the dictionary the layout ships, so the browser's first ` +
      `render would show the raw key. Add the family to lib/i18n/public-keys.ts`,
    outsideSubset,
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
      `${enKeys.length} keys, en/am in sync, no interpolated fallbacks, ` +
      `every public key inside the shipped subset, no public fallback drift.`,
  );
  process.exit(0);
}

for (const [title, items] of problems) {
  console.error(`\n${title}:`);
  for (const item of items.slice(0, 40)) console.error(`  ${item}`);
  if (items.length > 40) console.error(`  ...and ${items.length - 40} more`);
}
process.exit(1);
