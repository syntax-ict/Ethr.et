/**
 * The `test` every production spec imports: Playwright's own, plus a guard
 * that keeps the run read-only whatever a page does.
 *
 * Every request to the API that could change something (anything but GET,
 * HEAD and OPTIONS) is aborted before it leaves the browser. The one exception
 * is `POST /api/v1/auth/refresh`, which keeps a saved session alive and writes
 * nothing a tester can see. An aborted call is listed on the test as a
 * `blocked-write` annotation: a page that writes merely by being opened is
 * worth a look, but it is the page's behaviour, not a failure of the check.
 *
 * It also collects uncaught page errors, which the smoke checklist calls
 * "console clean" (S-10), and switches the interface to English, which the
 * pack's expected messages are written in.
 */
import { test as base, expect, type Page } from "@playwright/test";

const ALLOWED_WRITES = [/\/api\/v1\/auth\/refresh$/];
const SAFE_METHODS = new Set(["GET", "HEAD", "OPTIONS"]);

type GuardFixtures = {
  readOnly: { blocked: string[] };
  pageErrors: string[];
};

export const test = base.extend<GuardFixtures>({
  readOnly: [
    async ({ context }, provide, testInfo) => {
      const blocked: string[] = [];
      await context.route(/\/api\/v1\//, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const allowed =
          SAFE_METHODS.has(request.method()) ||
          ALLOWED_WRITES.some((pattern) => pattern.test(url.pathname));
        if (allowed) {
          await route.fallback();
          return;
        }
        blocked.push(`${request.method()} ${url.pathname}`);
        await route.abort("blockedbyclient");
      });
      await context.addInitScript(() => {
        try {
          window.localStorage.setItem("locale", "en");
        } catch {
          // Storage can be unavailable on an error page; the check goes on.
        }
      });
      await provide({ blocked });
      for (const call of blocked) {
        testInfo.annotations.push({ type: "blocked-write", description: call });
      }
    },
    { auto: true },
  ],

  pageErrors: async ({ page }, provide) => {
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await provide(errors);
  },
});

export { expect };

/** Error toasts on screen (the app's toaster is Sonner). */
export function errorToasts(page: Page) {
  return page.locator('[data-sonner-toast][data-type="error"]');
}
