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
 */
const fs = require("fs");
const path = require("path");

const SRC = "src";
const LOCALES = path.join(SRC, "lib/i18n/locales");

function loadLocale(name) {
  return JSON.parse(fs.readFileSync(path.join(LOCALES, `${name}.json`), "utf8"));
}

function sourceFiles(dir, acc = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (!/node_modules|\.next|__snapshots__/.test(p)) sourceFiles(p, acc);
    } else if (/\.(tsx|ts)$/.test(e.name) && !/\.test\.|[\\/]test[\\/]/.test(p)) {
      acc.push(p);
    }
  }
  return acc;
}

const en = loadLocale("en");
const am = loadLocale("am");
const files = sourceFiles(SRC);

const KEY_RE = /\bt\(\s*["']([a-z0-9_]+(?:\.[a-z0-9_]+)+)["']/gi;
const TPL_RE = /\bt\(\s*["']([a-z0-9_]+(?:\.[a-z0-9_]+)+)["']\s*,\s*`([^`]*)`/gis;

const missing = [];
const interpolated = [];

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
}

// Locale drift
const enKeys = Object.keys(en);
const amKeys = new Set(Object.keys(am));
const onlyEn = enKeys.filter((k) => !amKeys.has(k));
const onlyAm = [...amKeys].filter((k) => en[k] === undefined);

const placeholders = (s) => (String(s).match(/:[a-z_]+/gi) || []).sort().join(",");
const mismatched = enKeys
  .filter((k) => amKeys.has(k))
  .filter((k) => placeholders(en[k]) !== placeholders(am[k]));

const problems = [];
if (missing.length) problems.push([`${missing.length} key(s) used but not defined`, missing]);
if (interpolated.length)
  problems.push([
    `${interpolated.length} interpolated fallback(s) — use t(key, ":x", { x })`,
    interpolated,
  ]);
if (onlyEn.length) problems.push([`${onlyEn.length} key(s) in en but not am`, onlyEn]);
if (onlyAm.length) problems.push([`${onlyAm.length} key(s) in am but not en`, onlyAm]);
if (mismatched.length)
  problems.push([
    `${mismatched.length} key(s) whose :placeholders differ between locales`,
    mismatched.map((k) => `${k}  en(${placeholders(en[k])}) am(${placeholders(am[k])})`),
  ]);

if (!problems.length) {
  console.log(
    `i18n OK — ${files.length} files, ${enKeys.length} keys, en/am in sync, no interpolated fallbacks.`,
  );
  process.exit(0);
}

for (const [title, items] of problems) {
  console.error(`\n${title}:`);
  for (const item of items.slice(0, 40)) console.error(`  ${item}`);
  if (items.length > 40) console.error(`  ...and ${items.length - 40} more`);
}
process.exit(1);
