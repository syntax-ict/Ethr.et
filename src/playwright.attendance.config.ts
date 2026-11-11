import { defineConfig, devices } from "@playwright/test";

/**
 * The attendance-to-payslip journeys (e2e/attendance-payroll).
 *
 *   ./scripts/gates.sh e2e-attendance
 *
 * A config of their own, not a project in playwright.config.ts, because three
 * things differ: they sign in to the organisations E2eAttendanceSeeder makes
 * rather than to `demo`, they never retry (a second attempt would punch a
 * second time and process payroll a second time, and so prove nothing), and
 * they are meant to block a merge while the rest of the suite is advisory.
 *
 * Every journey reads what the database holds afterwards through the API. A
 * toast is not an assertion.
 */
export default defineConfig({
  testDir: "./e2e/attendance-payroll",
  outputDir: "./test-results/attendance-payroll",
  globalSetup: "./e2e/attendance-payroll/global-setup.ts",
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: process.env.CI
    ? [["github"], ["html", { open: "never", outputFolder: "playwright-report/attendance-payroll" }]]
    : "list",
  timeout: 120000,

  use: {
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },

  projects: [
    {
      name: "attendance-payroll",
      use: { ...devices["Desktop Chrome"], channel: process.env.PW_CHANNEL || undefined },
    },
  ],
});
