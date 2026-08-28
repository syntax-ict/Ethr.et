import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  outputDir: "./test-results",
  globalSetup: "./e2e/global-setup.ts",
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : "list",
  timeout: 30000,

  use: {
    baseURL: process.env.BASE_URL ?? "http://demo.localhost:3000",
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    video: "on-first-retry",
  },

  projects: [
    {
      name: "chromium-desktop",
      // PW_CHANNEL lets a machine without Playwright's downloaded browsers
      // (e.g. an offline/network-restricted dev box) drive the system Chrome
      // or Edge instead. Unset in CI, where the bundled Chromium is installed.
      use: { ...devices["Desktop Chrome"], channel: process.env.PW_CHANNEL || undefined },
    },
    {
      // Named for the engine it actually launches. `devices["iPhone 14"]` sets
      // defaultBrowserType: "webkit", so this was never Chromium despite being
      // called "chromium-mobile" — and when the WebKit binary was missing, all 83
      // mobile cases failed with "Executable doesn't exist at .../webkit-2311"
      // while the name sent everyone looking at a Chromium install that was fine.
      // Needs `npx playwright install webkit` (58.8 MiB) — not to be confused with
      // the 2 GB mcr.microsoft.com/playwright image the Dockerised path needs.
      name: "webkit-mobile",
      use: { ...devices["iPhone 14"], channel: process.env.PW_CHANNEL || undefined },
    },
  ],
});
