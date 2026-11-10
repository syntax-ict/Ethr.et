import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import { addisDate, as, devicePunch, employeeId, minutesAgo, recordsOf } from "./support";

/**
 * J10, acceptance matrix F1 to F3 and F6, through real sessions: another
 * organisation's administrator, a terminal that belongs to somebody else, and
 * an employee looking at a colleague.
 *
 * CrossTenantRouteSweepTest calls every route as the other organisation
 * inside one PHP process. This is the same question asked the way a person
 * would: two browsers, two addresses, two sessions.
 */
test("another organisation cannot read or write this one's attendance", async ({ browser, request }) => {
  const day = { from: addisDate(minutesAgo(1560)), to: addisDate(new Date()) };

  // A record to aim at: ATT-0003 arrives at their own terminal.
  expect(await devicePunch(request, "e2e-att", "9003", "check_in", minutesAgo(90))).toBe(1);

  const owner = await as(browser, "e2e-att", "admin");
  const stranger = await as(browser, "e2e-other", "admin");

  try {
    const person = await employeeId(owner.page, "ATT-0003");
    const [record] = await recordsOf(owner.page, person, day.from, day.to);
    expect(record).toBeTruthy();

    const devices = await api(owner.page, "GET", "/devices");
    expect(devices.status).toBe(200);
    const terminal = (devices.data.data as Array<{ public_id: string }>)[0];
    expect(terminal).toBeTruthy();

    // Named outright by their ids, each is somebody else's and not found.
    for (const path of [`/attendance/${record.public_id}`, `/employees/${person}`, `/devices/${terminal.public_id}`]) {
      const answer = await api(stranger.page, "GET", path);
      expect([403, 404], `GET ${path} as another organisation`).toContain(answer.status);
    }

    // Asked for through a filter, the answer is empty rather than theirs.
    const filtered = await api(
      stranger.page,
      "GET",
      `/attendance?filter[employee_public_id]=${person}&filter[date_from]=${day.from}&filter[date_to]=${day.to}`,
    );
    expect(filtered.status).toBe(200);
    expect(filtered.data.data).toHaveLength(0);

    // A manual entry for the other organisation's employee is refused.
    const written = await api(stranger.page, "POST", "/attendance/manual", {
      idempotency_key: `j10-manual-${Date.now()}`,
      employee_public_id: person,
      date: day.from,
      check_in: "08:00",
      check_out: "17:00",
      reason: "Entered from another organisation",
    });
    expect([403, 404, 422]).toContain(written.status);

    // This organisation's terminal reports a badge that exists only in the
    // other one. The token decides whose terminal it is, so nobody is punched.
    const otherPerson = await employeeId(stranger.page, "OTH-0001");
    expect(await devicePunch(request, "e2e-att", "9201", "check_in", minutesAgo(60))).toBe(0);
    expect(await recordsOf(stranger.page, otherPerson, day.from, day.to)).toHaveLength(0);

    // Nothing above changed the record it was aimed at.
    const [unchanged] = await recordsOf(owner.page, person, day.from, day.to);
    expect(unchanged).toEqual(record);
  } finally {
    await owner.context.close();
    await stranger.context.close();
  }
});

test("an employee reads their own attendance and nobody else's", async ({ browser, request }) => {
  const day = { from: addisDate(minutesAgo(1560)), to: addisDate(new Date()) };

  // Idempotent with the journey above when both run: the same badge and minute
  // would be a second sighting, so this one is its own, earlier in the day.
  await devicePunch(request, "e2e-att", "9003", "check_in", minutesAgo(91));

  const admin = await as(browser, "e2e-att", "admin");
  const employee = await as(browser, "e2e-att", "emp3");

  try {
    const colleague = await employeeId(admin.page, "ATT-0001");

    const mine = await api(employee.page, "GET", `/attendance/my?date_from=${day.from}&date_to=${day.to}`);
    expect(mine.status).toBe(200);
    expect(JSON.stringify(mine.data)).not.toContain(colleague);

    const theirs = await api(
      employee.page,
      "GET",
      `/attendance?filter[employee_public_id]=${colleague}&filter[date_from]=${day.from}&filter[date_to]=${day.to}`,
    );
    // Refused outright, or answered with nothing of the colleague's.
    if (theirs.status === 200) {
      expect(theirs.data.data).toHaveLength(0);
    } else {
      expect(theirs.status).toBe(403);
    }

    // Payroll is not an employee's to read.
    const runs = await api(employee.page, "GET", "/payroll/runs");
    expect(runs.status).toBe(403);

    // Nor can they punch a colleague in at the signed-in kiosk route.
    const punched = await api(employee.page, "POST", "/attendance/kiosk", {
      idempotency_key: `j10-kiosk-${Date.now()}`,
      employee_code: "ATT-0002",
      type: "check_in",
    });
    expect(punched.status).toBe(403);
  } finally {
    await admin.context.close();
    await employee.context.close();
  }
});
