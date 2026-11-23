/**
 * The signed-out half of the PWA cases, against the build production serves
 * (`docs/production testing/09-NON-FUNCTIONAL.md`, section 8).
 *
 * CI drives `php artisan serve` and `next dev`, never the static export behind
 * Apache, and the rest of this suite blocks service workers (the second lock in
 * `playwright.production.config.ts`). Until 2026-11-24 that left the offline
 * shell on production checked by hand or not at all. These run in a project of
 * their own, `production-offline`, the one place the worker is allowed:
 *
 *   npm run test:e2e:production:offline
 *
 * Why the worker is safe here: every test starts from an empty profile and
 * never signs in, so nothing is queued on the phone and `sw.js` has nothing to
 * replay. The guard still aborts any write a page makes. Do not add a test
 * here that signs in or loads a saved session.
 *
 * The network is cut by the browser (`context.setOffline`), not stubbed in the
 * page, so what answers is the worker and its cache, as on a real phone.
 */
import type { BrowserContext, Page } from "@playwright/test";
import { test, expect } from "./guard";

test.use({ storageState: { cookies: [], origins: [] } });

// A first visit, the worker's install, then navigations with no network.
test.setTimeout(120_000);

/** The offline page's heading, as each dictionary has it. */
const OFFLINE_HEADING = {
  en: "You are offline",
  am: "ከመስመር ውጭ ነዎት",
  om: "Toora ala jirtu",
} as const;

/**
 * The worker installs and claims the page a few seconds into a first visit
 * (measured 2026-11-24: still installing at 1 s, in control by 3 s). Cutting
 * the network before that shows the browser's own error, which is not a
 * defect and not what these tests are about.
 */
async function firstVisit(page: Page, path = "/en") {
  await page.goto(path, { waitUntil: "load" });
  await expect
    .poll(
      () =>
        page
          .evaluate(() => Boolean(navigator.serviceWorker.controller))
          // The root redirects to a language; a read during that is "not yet".
          .catch(() => false),
      {
        message: "the service worker takes control of a first visit",
        timeout: 30_000,
      },
    )
    .toBe(true);
}

async function goOffline(context: BrowserContext, page: Page) {
  await context.setOffline(true);
  await expect.poll(() => page.evaluate(() => navigator.onLine)).toBe(false);
}

/** After the guard's own script, so this language wins. */
async function speak(
  context: BrowserContext,
  locale: keyof typeof OFFLINE_HEADING,
) {
  await context.addInitScript((value) => {
    try {
      window.localStorage.setItem("locale", value);
    } catch {
      // Storage can be unavailable on an error page; the check goes on.
    }
  }, locale);
}

test.describe("offline shell, signed out", () => {
  test(
    "PWA-012 the worker installs, takes control and keeps the offline page",
    { tag: ["@pwa", "@P1", "@R"] },
    async ({ page }, testInfo) => {
      await firstVisit(page);

      const worker = await page.evaluate(async () => {
        const registrations = await navigator.serviceWorker.getRegistrations();
        const names = await caches.keys();
        let entries = 0;
        let offlinePage = false;
        for (const name of names) {
          const keys = await (await caches.open(name)).keys();
          entries += keys.length;
          offlinePage ||= keys.some(
            (key) => new URL(key.url).pathname === "/offline",
          );
        }
        return {
          state: registrations[0]?.active?.state ?? "none",
          names,
          entries,
          offlinePage,
        };
      });

      testInfo.annotations.push({
        type: "service-worker",
        description: `${worker.state}, caches ${worker.names.join(", ")}, ${worker.entries} entries`,
      });
      expect(worker.state).toBe("activated");
      expect(worker.offlinePage, "the offline page is in the cache").toBe(true);
      expect(worker.entries, "the shell is precached").toBeGreaterThan(20);
    },
  );

  for (const locale of ["en", "am", "om"] as const) {
    test(
      `PWA-002 a page never visited falls back to the offline page (${locale})`,
      { tag: ["@pwa", "@P2", "@R"] },
      async ({ context, page }) => {
        await speak(context, locale);
        await firstVisit(page, `/${locale}`);
        // Once more with the worker in charge. On a first visit the page's
        // files arrive before the worker controls it, so the Afaan Oromoo
        // dictionary (a chunk of its own; English is the built-in text and
        // Amharic is inlined) is not kept yet, and the offline page came up
        // in English (measured 2026-11-24). The case is about an installed
        // app, which has been opened before.
        await page.reload({ waitUntil: "load" });
        await goOffline(context, page);

        await page.goto(`/never-visited-${Date.now()}`, {
          waitUntil: "domcontentloaded",
        });

        await expect(
          page.getByRole("heading", { name: OFFLINE_HEADING[locale] }),
        ).toBeVisible({ timeout: 15_000 });
        // The page's own retry, never the error boundary (#466).
        await expect(page.getByRole("button")).toHaveCount(1);
        await expect(page.getByText(/something went wrong/i)).toHaveCount(0);
      },
    );
  }

  test(
    "PWA-013 the home page and the kiosk open with the network down, and the site comes back",
    { tag: ["@pwa", "@P2", "@R"] },
    async ({ context, page }, testInfo) => {
      await firstVisit(page);
      // Seen once online, so each has a copy to serve.
      await page.goto("/kiosk", { waitUntil: "domcontentloaded" });
      await page.goto("/en", { waitUntil: "load" });
      await goOffline(context, page);

      for (const path of ["/en", "/kiosk"]) {
        const response = await page.goto(path, {
          waitUntil: "domcontentloaded",
        });
        expect(response?.status(), `${path} is served offline`).toBe(200);
        await expect(page.getByText(/ERR_INTERNET_DISCONNECTED/)).toHaveCount(
          0,
        );
        const length = await page.evaluate(
          () => document.body.innerText.trim().length,
        );
        testInfo.annotations.push({
          type: "offline-page",
          description: `${path}: "${await page.title()}", ${length} characters`,
        });
        expect(length, `${path} shows real content offline`).toBeGreaterThan(
          40,
        );
      }

      await context.setOffline(false);
      await expect.poll(() => page.evaluate(() => navigator.onLine)).toBe(true);
      const online = await page.goto("/en/pricing", {
        waitUntil: "domcontentloaded",
      });
      expect(online?.status(), "the site answers again").toBeLessThan(400);
    },
  );
});
