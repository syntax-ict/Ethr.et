import { chromium } from "@playwright/test";
import fs from "fs/promises";
import path from "path";
import { email, origin, PASSWORD, SESSIONS, statePath } from "./support";

/**
 * Signs each journey's people in once, at their organisation's own address,
 * and keeps the sessions. The session cookie is host-only, so a session for
 * one organisation is of no use at another's address: one file each.
 *
 * It fails rather than warns when a sign-in does not reach the dashboard. A
 * journey that ran signed out would be redirected to /login and its API reads
 * answered 401, which is a failure that says nothing about attendance.
 */
export default async function globalSetup() {
  await fs.mkdir(path.dirname(statePath("e2e-att", "admin")), { recursive: true });

  const browser = await chromium.launch({ channel: process.env.PW_CHANNEL || undefined });

  try {
    for (const { organisation, login } of SESSIONS) {
      const page = await browser.newPage({ baseURL: origin(organisation) });

      await page.goto("/login");
      await page.waitForLoadState("networkidle").catch(() => {});
      // Rendered only where the address does not name the organisation itself.
      const tenantField = page.locator("#tenant");
      if (await tenantField.isEditable().catch(() => false)) {
        await tenantField.fill(organisation);
      }
      await page.fill("#identifier", email(organisation, login));
      await page.fill('input[type="password"]', PASSWORD);
      await page.click('button[type="submit"]');

      try {
        await page.waitForURL("**/dashboard", { timeout: Number(process.env.E2E_LOGIN_TIMEOUT ?? 90000) });
      } catch {
        throw new Error(
          `${email(organisation, login)} could not sign in at ${origin(organisation)}. ` +
            "Seed the organisations first: php artisan db:seed --class=E2eAttendanceSeeder",
        );
      }

      // The app's default language is Amharic; the journeys read English.
      await page.evaluate(() => localStorage.setItem("locale", "en"));
      await page.context().storageState({ path: statePath(organisation, login) });
      await page.close();
    }
  } finally {
    await browser.close();
  }
}
