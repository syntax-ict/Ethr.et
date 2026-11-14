import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import { addisDate, as, devicePunch, earlierToday, employeeId, live, minutesAgo, recordsOf } from "./support";

/**
 * J3, acceptance matrix A5 and A7: one arrival reported again and again. The
 * terminal sends it, sends the same event a second time, sees the person
 * again a minute later, and hours on someone punches them in at the kiosk.
 * It is one live record, and nobody is told they have a conflict to resolve.
 *
 * What the employee's own screen says of the day is left out on purpose: it
 * reads a voided duplicate as "checked in" today, which is the first fix
 * planned after this suite.
 */
test("one arrival reported four times is one live record", async ({ browser, request }) => {
  const arrived = earlierToday(120);
  const seenAgain = new Date(arrived.getTime() + 60_000);

  expect(await devicePunch(request, "e2e-att", "9002", "check_in", arrived)).toBe(1);
  // The terminal resends the event it already delivered.
  await devicePunch(request, "e2e-att", "9002", "check_in", arrived);
  // And reads the same person a minute later.
  await devicePunch(request, "e2e-att", "9002", "check_in", seenAgain);

  const admin = await as(browser, "e2e-att", "admin");

  try {
    const kiosk = await api(admin.page, "POST", "/attendance/kiosk", {
      idempotency_key: `j3-kiosk-${Date.now()}`,
      employee_code: "ATT-0002",
      type: "check_in",
    });
    expect(kiosk.status).toBeLessThan(300);
    expect(kiosk.data.was_duplicate).toBe(true);
    expect(kiosk.data.outcome.code).toBe("already_checked_in");
    expect(kiosk.data.outcome.by).toBe("biometric");

    const person = await employeeId(admin.page, "ATT-0002");
    const rows = await recordsOf(admin.page, person, addisDate(minutesAgo(1560)), addisDate(new Date()));
    const open = live(rows);

    expect(open).toHaveLength(1);
    expect(open[0].check_out).toBeNull();
    expect(open[0].source).toBe("biometric");
    // The earliest sighting is the arrival.
    expect(Math.abs(new Date(open[0].check_in!).getTime() - arrived.getTime())).toBeLessThan(2000);
    // The kiosk's punch is on the day's record as a repeat, not a second record.
    expect(open[0].punches.some((punch) => punch.source === "kiosk" && punch.outcome === "duplicate")).toBe(true);

    // Whatever the merge voided points at the record that was kept.
    for (const voided of rows.filter((row) => row.status === "voided")) {
      expect(voided.conflict?.with_record_public_id ?? open[0].public_id).toBe(open[0].public_id);
    }

    const conflicts = await api(admin.page, "GET", "/attendance/conflicts");
    expect(conflicts.status).toBe(200);
    expect(JSON.stringify(conflicts.data)).not.toContain(person);
  } finally {
    await admin.context.close();
  }
});
