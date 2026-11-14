import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import { addisDate, as, devicePunch, earlierToday, employeeId, live, minutesAgo, recordsOf } from "./support";

/**
 * J1, acceptance matrix A2: the day is opened by the biometric terminal and
 * closed by the employee on the web page. It is one record that names both
 * methods, and every screen agrees the day is over.
 */
test("a day opened at the terminal and closed on the web is one record", async ({ browser, request }) => {
  const arrived = earlierToday(180);

  expect(await devicePunch(request, "e2e-att", "9001", "check_in", arrived)).toBe(1);

  const employee = await as(browser, "e2e-att", "emp1");
  const admin = await as(browser, "e2e-att", "admin");

  try {
    // The terminal's punch is what the employee's own screens start from.
    const before = await api(employee.page, "GET", "/attendance/my-shift");
    expect(before.status).toBe(200);
    expect(before.data.attendance.status).toBe("checked_in");

    await employee.page.goto("/attendance");
    const checkOut = employee.page.getByRole("button", { name: /^check out$/i });
    await expect(checkOut).toBeVisible({ timeout: 30000 });

    const answered = employee.page.waitForResponse(
      (response) => response.url().includes("/attendance/check-out") && response.request().method() === "POST",
    );
    await checkOut.click();
    expect((await answered).status()).toBe(200);

    // What the database holds, read by an administrator.
    const person = await employeeId(admin.page, "ATT-0001");
    const rows = await recordsOf(admin.page, person, addisDate(minutesAgo(1620)), addisDate(new Date()));

    expect(rows).toHaveLength(1);
    expect(live(rows)).toHaveLength(1);

    const [record] = rows;
    expect(record.source).toBe("biometric");
    expect(record.check_in).not.toBeNull();
    expect(record.check_out).not.toBeNull();
    expect(record.conflict).toBeNull();
    expect(record.punches.map((punch) => `${punch.kind}:${punch.source}:${punch.outcome}`)).toEqual([
      "check_in:biometric:created",
      "check_out:web:applied",
    ]);

    // The terminal dated the arrival; the server did not use its own clock.
    expect(Math.abs(new Date(record.check_in!).getTime() - arrived.getTime())).toBeLessThan(2000);

    const after = await api(employee.page, "GET", "/attendance/my-shift");
    expect(after.data.attendance.status).toBe("checked_out");
    expect(after.data.attendance.record_public_id).toBe(record.public_id);

    const conflicts = await api(admin.page, "GET", "/attendance/conflicts");
    expect(conflicts.status).toBe(200);
    expect(JSON.stringify(conflicts.data)).not.toContain(person);
  } finally {
    await employee.context.close();
    await admin.context.close();
  }
});
