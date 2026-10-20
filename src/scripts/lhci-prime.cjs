/**
 * Lighthouse CI puppeteer script: carry a signed-in session into each run.
 *
 * `global-setup.ts` signs in once per role and saves the browser state
 * (cookies and the `locale` localStorage key) to `e2e/.auth/*.json`,
 * gitignored. Lighthouse opens a fresh, storage-reset page per run, so a
 * cookie alone would land on an Amharic dashboard: the app reads its language
 * from localStorage (`getLocale()` in lib/i18n/translations.ts), not from a
 * header. This script restores both before every URL. Lighthouse's own storage
 * reset clears the HTTP cache and service workers but not cookies or
 * localStorage (its default `clearStorageTypes`), so the session survives and
 * each run is still a cold load.
 *
 * No credential is typed here and nothing is printed: the state file is the
 * only input, and it is produced by the same sign-in the e2e suite uses.
 */
// CommonJS on purpose: Lighthouse CI loads this with require(), so an ES
// module here would hand it a namespace object instead of a function.
/* eslint-disable @typescript-eslint/no-require-imports */
const { readFileSync } = require("node:fs");
const path = require("node:path");

module.exports = async (browser, { url }) => {
  const statePath = path.resolve(
    process.env.LHCI_STATE || "e2e/.auth/admin.json",
  );
  const state = JSON.parse(readFileSync(statePath, "utf8"));
  const target = new URL(url);

  const page = await browser.newPage();

  const cookies = (state.cookies || [])
    .filter((c) => {
      const domain = String(c.domain || "").replace(/^\./, "");
      return (
        target.hostname === domain || target.hostname.endsWith(`.${domain}`)
      );
    })
    .map((c) => ({
      name: c.name,
      value: c.value,
      domain: c.domain,
      path: c.path || "/",
      httpOnly: !!c.httpOnly,
      secure: !!c.secure,
      sameSite:
        c.sameSite === "None"
          ? "None"
          : c.sameSite === "Strict"
            ? "Strict"
            : "Lax",
    }));
  if (cookies.length === 0) {
    throw new Error(
      `no cookies for ${target.hostname} in ${statePath}; sign in first`,
    );
  }
  await page.setCookie(...cookies);

  // localStorage is per origin, so a same-origin page has to be open to write
  // it. /offline is static and makes no API call.
  await page.goto(`${target.origin}/offline`, {
    waitUntil: "domcontentloaded",
  });
  const origin = (state.origins || []).find((o) => o.origin === target.origin);
  const entries = origin ? origin.localStorage || [] : [];
  await page.evaluate((items) => {
    for (const { name, value } of items) localStorage.setItem(name, value);
    if (!localStorage.getItem("locale")) localStorage.setItem("locale", "en");
  }, entries);

  await page.close();
};
