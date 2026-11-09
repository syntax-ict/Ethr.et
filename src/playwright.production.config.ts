import { defineConfig, devices } from "@playwright/test";

/**
 * Read-only checks against production, from the QA pack
 * (`docs/production testing/11-CLAUDE-CODE-AND-PLAYWRIGHT.md`).
 *
 * Kept apart from `playwright.config.ts` on purpose: that suite signs in with
 * demo passwords in a global setup and writes data. This one never types a
 * password (a person saves each role's session by hand, MFA included) and
 * aborts every state-changing API call (`e2e-production/guard.ts`).
 *
 *   npx playwright test -c playwright.production.config.ts
 *
 * Traces and videos are off: both record cookies and request headers, and the
 * pack's rule 3 keeps secrets out of everything a tester hands over.
 */
export default defineConfig({
  testDir: "./e2e-production",
  outputDir: "./test-results/production",
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  // One at a time: production rate-limits by address, and the host's firewall
  // bans an address after a handful of refused requests (01 rule 10).
  workers: 1,
  timeout: 45_000,
  reporter: [
    ["list"],
    ["json", { outputFile: "test-results/production/results.json" }],
    ["html", { outputFolder: "playwright-report/production", open: "never" }],
  ],
  use: {
    baseURL: process.env.PROD_BASE_URL ?? "https://www.ethr.et",
    trace: "off",
    video: "off",
    screenshot: "only-on-failure",
    locale: "en-US",
    timezoneId: "Africa/Addis_Ababa",
  },
  projects: [
    {
      name: "production-chromium",
      use: {
        ...devices["Desktop Chrome"],
        channel: process.env.PW_CHANNEL || undefined,
        launchOptions: {
          executablePath: process.env.PW_EXECUTABLE_PATH || undefined,
        },
      },
    },
  ],
});
