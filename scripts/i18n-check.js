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
 *  3. LOCALE DRIFT — en.json and a translation (am.json, om.json) disagree on
 *     which keys exist, or a :placeholder present in one locale is missing from
 *     the other (which would drop the value in that language only). Every
 *     translation is held to the same rules: TRANSLATIONS below lists them.
 *
 *  4. PUBLIC KEY OUTSIDE THE SHIPPED SUBSET — a t() call on a public page whose
 *     key is not covered by PUBLIC_KEY_PREFIXES.
 *
 *     /am/* and /en/* are prerendered and no dictionary is loaded eagerly on a
 *     public page, so the layout hands the browser a projection of the locale's
 *     dictionary to register before the first client render (see
 *     lib/i18n/public-keys.ts). A key outside those prefixes is missing from
 *     that projection, so the server renders the real sentence and the browser's
 *     first render produces something else — React then discards the server's
 *     HTML and paints it until the lazy chunk lands, if it ever does.
 *
 *     **Every** call is checked, fallback or not (since 2026-10-03). This rule
 *     used to skip a t("x.y", "Text") with a string fallback, reasoning that the
 *     browser's first render produces "Text", the server produced en.json's
 *     value, and check 5 keeps those equal. That held only while `am.json` was
 *     eager on every page. Once Amharic also arrives as a projection, the server
 *     renders the Amharic sentence and the browser renders "Text": a mismatch a
 *     reader sees as the page switching language under them. Five keys in
 *     product-flow.tsx used that exemption; they moved under `marketing.`.
 *     Template-literal keys — t(`marketing.features.${key}`) — are checked by
 *     their static head, which is what the projection has to cover.
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

/**
 * Every locale that ships a full dictionary besides English. `ti`, `so` and
 * `sid` are stubs (`lib/i18n/config.ts` marks them `coming_soon`) and are not
 * held to this until they are promoted.
 *
 * `sameAsEnglish` lists the values that are rightly identical to English in
 * that language — acronyms, the language's own name, example values — each one
 * deliberately. Anything else identical to English is English shown to a reader
 * who chose another language.
 */
const TRANSLATIONS = {
  am: [
    "admin_tenant_detail_page.custom_domain_placeholder", // hr.example.com
    "attendance.source_qr", // QR
    "audit_logs_page.ip", // IP
    "nav.sso", // SSO / SAML
    "settings.tab_sso", // SSO
    "notification_prefs_page.channel_sms", // SMS
    "settings.english", // a language is named in its own language
    "password.strength_none", // empty
    "employee.education.degree_placeholder", // BSc, MSc, MBA...
    "webhooks_page.url", // URL
    "shifts_page.name_english_placeholder", // the English-name field's example
    "settings.landing_field_lang", // ":label (:language)" — a format
    "settings.landing_list_field_lang", // ":label :n (:language)" — a format
    "audit_logs_page.action_placeholder", // employee.created — an action code
  ],
  // Oromo is written in the Latin script, so it shares more with English
  // than Amharic does: formats made only of placeholders, and loan words.
  om: [
    "admin_tenant_detail_page.custom_domain_placeholder", // hr.example.com
    "admin_features.changed", // ":when: :reason" — a format
    "admin_sms.test_status", // "HTTP :status"
    "attendance.source_qr", // QR
    "attendance.kiosks_page.lock_ipad_title", // iPad
    "audit_logs_page.ip", // IP
    "audit.subject.webhook", // Webhook — the technical term, kept
    "audit.entity.webhook", // Webhook
    "roles_page.module_webhook", // Webhooks — the technical term, kept
    "route.settings.webhooks.label", // Webhooks
    "employee.education.degree_placeholder", // BSc, MSc, MBA...
    "employee.education.gpa", // GPA
    "employee.tin", // TIN
    "employees.missing.tin", // TIN
    "leave_page.calendar_range", // ":from – :to" — a format
    "recruitment.score_of", // ":score / 100" — a format
    "settings.english", // a language is named in its own language
    "settings.tab_sso", // SSO
    "users_page.mfa_on", // 2FA
    "password.strength_none", // empty
    "profile.tin", // TIN
    "nav.sso", // SSO / SAML
    "notification_prefs_page.channel_sms", // SMS
    "webhooks_page.url", // URL
    "work_sites.radius_short", // ":m m" — metres
    "settings.landing_field_lang", // ":label (:language)" — a format
    "settings.landing_list_field_lang", // ":label :n (:language)" — a format
    "settings.oromo", // Afaan Oromoo: a language is named in its own language
    "audit_logs_page.action_placeholder", // employee.created — an action code
    // Plan names are brands: billing and invoices print them as stored.
    "marketing.pricing_page.starter", // Starter
    "marketing.pricing_page.professional", // Professional
    "marketing.pricing_page.enterprise", // Enterprise
  ],
};
const translations = Object.fromEntries(
  Object.keys(TRANSLATIONS).map((code) => [code, loadLocale(code)]),
);
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
    for (const [code, dict] of Object.entries(translations)) {
      if (dict[key] === undefined) gaps.push(code);
    }
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

// Locale drift, and English left in a translation.
const enKeys = Object.keys(en);
const placeholders = (s) =>
  (String(s).match(/:[a-z_]+/gi) || []).sort().join(",");

const drift = {};
for (const [code, dict] of Object.entries(translations)) {
  const keys = new Set(Object.keys(dict));
  const same = new Set(TRANSLATIONS[code]);
  drift[code] = {
    onlyEn: enKeys.filter((k) => !keys.has(k)),
    onlyHere: [...keys].filter((k) => en[k] === undefined),
    mismatched: enKeys
      .filter((k) => keys.has(k))
      .filter((k) => placeholders(en[k]) !== placeholders(dict[k]))
      .map(
        (k) =>
          `${k}  en(${placeholders(en[k])}) ${code}(${placeholders(dict[k])})`,
      ),
    // An identical value passes every key check above, which is how 103 of
    // them accumulated unseen in am.json (measured 2026-10-14).
    untranslated: enKeys.filter(
      (k) => keys.has(k) && dict[k] === en[k] && !same.has(k),
    ),
  };
}

const problems = [];
for (const [code, d] of Object.entries(drift)) {
  if (d.untranslated.length)
    problems.push([
      `${d.untranslated.length} ${code} value(s) identical to en — translate, or add the key to TRANSLATIONS.${code} with a reason`,
      d.untranslated,
    ]);
}
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
      `render would show the raw key or the English fallback. Add the family to lib/i18n/public-keys.ts`,
    outsideSubset,
  ]);
if (fallbackDrift.length)
  problems.push([
    `${fallbackDrift.length} public-page fallback(s) that disagree with en.json — ` +
      `the prerendered English page would rewrite itself after hydration`,
    fallbackDrift,
  ]);
for (const [code, d] of Object.entries(drift)) {
  if (d.onlyEn.length)
    problems.push([`${d.onlyEn.length} key(s) in en but not ${code}`, d.onlyEn]);
  if (d.onlyHere.length)
    problems.push([
      `${d.onlyHere.length} key(s) in ${code} but not en`,
      d.onlyHere,
    ]);
  if (d.mismatched.length)
    problems.push([
      `${d.mismatched.length} key(s) whose :placeholders differ between en and ${code}`,
      d.mismatched,
    ]);
}

if (!problems.length) {
  console.log(
    `i18n OK — ${files.length} files (${publicFiles.size} reachable from a public page), ` +
      `${enKeys.length} keys, en/${Object.keys(translations).join("/")} in sync, every translated value translated, no interpolated fallbacks, ` +
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
