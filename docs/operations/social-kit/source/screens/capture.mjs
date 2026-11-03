// Captures real ETHR screens from a local install, for the social kit.
//
//   node capture.mjs [--base http://localhost:3000] [--only name]
//
// Needs the app running locally (docs/LOCAL_SETUP.md): the API on :8000 and
// `npm run dev` on :3000, against a database seeded with DemoTenantSeeder and
// then ethiopian-names.php. It signs in as the demo accounts, which exist
// only in a seeded local database, and never touches a real organisation.
//
// Writes PNGs to ../../assets/screenshots/. render.mjs puts them in frames.

import { mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const { chromium } = require("playwright");

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, "..", "..", "assets", "screenshots");
const arg = (name, fallback) => {
  const i = process.argv.indexOf(name);
  return i > -1 ? process.argv[i + 1] : fallback;
};
const BASE = arg("--base", "http://localhost:3000");
const ONLY = arg("--only", "");

// The demo seeder's accounts. Local only; see docs/LOCAL_SETUP.md.
const ACCOUNTS = {
  admin: "admin@demo.ethr.et",
  employee: "emp@demo.ethr.et",
};
const DESKTOP = { width: 1440, height: 900, deviceScaleFactor: 2 };
const PHONE = { width: 390, height: 844, deviceScaleFactor: 3, isMobile: true, hasTouch: true };

// name, who, viewport, path, languages
export const SCREENS = [
  ["dashboard", "admin", DESKTOP, "/dashboard", ["en", "am"]],
  ["employees", "admin", DESKTOP, "/employees", ["en", "am"]],
  ["attendance", "admin", DESKTOP, "/attendance", ["en", "am"]],
  ["payroll", "admin", DESKTOP, "/payroll", ["en", "am"]],
  ["leave-admin", "admin", DESKTOP, "/leave", ["en", "am"]],
  ["phone-home", "employee", PHONE, "/dashboard", ["en", "am"]],
  ["phone-leave", "employee", PHONE, "/leave", ["en", "am"]],
  ["phone-payslips", "employee", PHONE, "/payroll/payslips", ["en", "am"]],
  ["phone-checkin", "employee", PHONE, "/attendance/mobile", ["en", "am"]],
];

async function signIn(context, who) {
  const page = await context.newPage();
  await page.goto(`${BASE}/login`, { waitUntil: "networkidle" });
  const org = page.locator('input[autocomplete="organization"]');
  if (await org.isVisible().catch(() => false)) await org.fill("demo");
  await page.locator('input[autocomplete="email"]').fill(ACCOUNTS[who]);
  await page.locator('input[autocomplete="current-password"]').fill("password");
  await page.locator('button[type="submit"]').first().click();
  await page.waitForURL((url) => !url.pathname.startsWith("/login"), { timeout: 30000 });
  await page.close();
}

mkdirSync(OUT, { recursive: true });
const browser = await chromium.launch();
const sessions = {};
for (const [name, who, viewport, path, langs] of SCREENS) {
  if (ONLY && !name.includes(ONLY)) continue;
  const key = `${who}-${viewport.width}`;
  if (!sessions[key]) {
    const { width, height, ...rest } = viewport;
    sessions[key] = await browser.newContext({
      viewport: { width, height },
      ...rest,
      timezoneId: "Africa/Addis_Ababa",
      colorScheme: "light",
    });
    await signIn(sessions[key], who);
  }
  for (const lang of langs) {
    const page = await sessions[key].newPage();
    await page.addInitScript((l) => localStorage.setItem("locale", l), lang);
    await page.goto(`${BASE}${path}`, { waitUntil: "networkidle" });
    // Charts and skeletons settle after the data arrives.
    await page.waitForTimeout(2500);
    await page.evaluate(() => document.fonts.ready);
    const file = join(OUT, `${name}-${lang}.png`);
    await page.screenshot({ path: file });
    console.log(file);
    await page.close();
  }
}
await browser.close();
