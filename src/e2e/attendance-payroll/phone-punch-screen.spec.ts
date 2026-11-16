import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import { addisDate, as, employeeId, live, origin, recordsOf, statePath } from "./support";

/**
 * The phone's punch screen, end to end at a phone's size: the button is on the
 * first screen, two taps in the same moment are one punch, the confirmation and
 * the shift rail come up, and what the database holds afterwards is one record
 * that names the phone.
 *
 * It signs in as emp4 (ATT-0004), who no other journey punches for: emp1 is
 * the device-in-web-out journey's, emp3 the organisation-boundaries journey's,
 * and ATT-0002 has no sign-in. Journeys share the day's records, so only a
 * person nobody else touches has a day this one can say starts empty.
 */
test("a phone punch is one tap, one record, in and out", async ({ browser }) => {
  const context = await browser.newContext({
    baseURL: origin("e2e-att"),
    storageState: statePath("e2e-att", "emp4"),
    viewport: { width: 360, height: 640 },
    geolocation: { latitude: 9.0192, longitude: 38.7525 },
    permissions: ["geolocation"],
  });
  const page = await context.newPage();
  const admin = await as(browser, "e2e-att", "admin");

  try {
    await page.goto("/dashboard");
    await page.waitForLoadState("networkidle").catch(() => {});

    // Nobody has punched for this person today.
    const before = await api(page, "GET", "/attendance/my-shift");
    expect(before.status).toBe(200);
    expect(before.data.attendance.status).toBe("not_checked_in");

    const posts: string[] = [];
    page.on("request", (request) => {
      if (request.method() === "POST" && request.url().includes("/attendance/mobile/check-in")) {
        posts.push(request.url());
      }
    });

    await page.goto("/attendance/mobile");
    const checkIn = page.getByRole("button", { name: /^check in$/i });
    await expect(checkIn).toBeEnabled({ timeout: 30000 });

    // The first screen: the button sits above the bottom navigation.
    const button = await checkIn.boundingBox();
    const nav = await page.locator('nav[aria-label="Mobile navigation"]').boundingBox();
    expect(button, "punch button box").not.toBeNull();
    expect(nav, "bottom navigation box").not.toBeNull();
    expect(button!.y + button!.height).toBeLessThan(nav!.y);

    // Two taps in the same moment.
    const answered = page.waitForResponse(
      (response) => response.url().includes("/attendance/mobile/check-in") && response.request().method() === "POST",
    );
    await checkIn.dblclick();
    expect((await answered).status()).toBe(201);
    await page.waitForTimeout(500);
    expect(posts).toHaveLength(1);

    // The confirmation, and the day's shift rail (drawn only on a working day
    // that has a shift, so only asserted where the server says it has one).
    await expect(page.getByRole("link", { name: "Done" })).toBeVisible();
    if (before.data.shift && before.data.working_day) {
      await expect(page.locator('[data-testid="shift-rail"]')).toBeVisible();
    }

    // What the server holds.
    const during = await api(page, "GET", "/attendance/my-shift");
    expect(during.data.attendance.status).toBe("checked_in");

    const person = await employeeId(admin.page, "ATT-0004");
    const today = addisDate(new Date());
    const opened = await recordsOf(admin.page, person, today, today);
    expect(opened).toHaveLength(1);
    expect(live(opened)).toHaveLength(1);
    expect(opened[0].check_in).not.toBeNull();
    expect(opened[0].source).toBe("mobile");

    // Later in the day: the button now says what it will do.
    await page.reload();
    const checkOut = page.getByRole("button", { name: /^check out$/i });
    await expect(checkOut).toBeEnabled({ timeout: 30000 });

    const left = page.waitForResponse(
      (response) => response.url().includes("/attendance/mobile/check-out") && response.request().method() === "POST",
    );
    await checkOut.click();
    expect((await left).status()).toBe(200);

    const after = await api(page, "GET", "/attendance/my-shift");
    expect(after.data.attendance.status).toBe("checked_out");

    const closed = await recordsOf(admin.page, person, today, today);
    expect(live(closed)).toHaveLength(1);

    const [record] = closed;
    expect(record.check_in).not.toBeNull();
    expect(record.check_out).not.toBeNull();
    expect(record.punches.map((punch) => `${punch.kind}:${punch.source}:${punch.outcome}`)).toEqual([
      "check_in:mobile:created",
      "check_out:mobile:applied",
    ]);
  } finally {
    await context.close();
    await admin.context.close();
  }
});
