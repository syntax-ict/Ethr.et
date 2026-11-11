import { expect, test } from "@playwright/test";
import { addisDate, as, devicePunch, employeeId, live, minutesAgo, recordsOf } from "./support";

/**
 * A terminal's punch is recorded when only its token says whose it is.
 *
 * A terminal posts to the address the app gives it, with nothing in the
 * request that names the organisation: no organisation in the host, and a
 * terminal cannot send `X-Tenant`. The token finds the device and the device
 * finds its organisation.
 *
 * Until 2026-11-10 the request was never given that organisation as its
 * context. The engine looked the employee up with none, found nobody (the
 * scope fails closed), and the terminal was answered `processed` with nothing
 * recorded. This journey found it on its first run; the backend tests had
 * always passed, because their helper sets the organisation in the test's own
 * process, where a real request has none
 * (api/tests/Feature/Device/DeviceWebhookTenantContextTest.php now takes it
 * away first).
 */
test("a terminal's punch is recorded when only its token says whose it is", async ({ browser, request }) => {
  const arrived = minutesAgo(200);
  const left = minutesAgo(140);

  expect(await devicePunch(request, "e2e-pay", "9101", "check_in", arrived)).toBe(1);
  expect(await devicePunch(request, "e2e-pay", "9101", "check_out", left)).toBe(1);

  const admin = await as(browser, "e2e-pay", "admin");
  try {
    const person = await employeeId(admin.page, "PAY-0001");
    const rows = await recordsOf(admin.page, person, addisDate(minutesAgo(1640)), addisDate(new Date()));

    expect(live(rows)).toHaveLength(1);
    expect(rows[0].source).toBe("biometric");
    expect(Math.abs(new Date(rows[0].check_in!).getTime() - arrived.getTime())).toBeLessThan(2000);
    expect(Math.abs(new Date(rows[0].check_out!).getTime() - left.getTime())).toBeLessThan(2000);
    expect(rows[0].punches.map((punch) => `${punch.kind}:${punch.source}:${punch.outcome}`)).toEqual([
      "check_in:biometric:created",
      "check_out:biometric:applied",
    ]);
  } finally {
    await admin.context.close();
  }
});
