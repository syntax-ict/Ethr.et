import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import {
  addis,
  addisDate,
  as,
  employeeId,
  hikvisionPunch,
  live,
  recordsOf,
} from "./support";

/**
 * A two-session working day is one record.
 *
 * The terminal of an office that works a morning and an afternoon sends four
 * punches: in, out for lunch, in, out for the day. Until 2026-11-18 the second
 * check-in made a second record flagged as a conflict, the morning an early
 * leave and the afternoon "late by hours". Now the return reopens the day's
 * record (a break within three hours, before the shift's end).
 *
 * Read back from the API: one record for the date, its check-in and check-out
 * as the terminal sent them, the punch log showing the return as `rejoined`,
 * and nothing waiting for this person in Conflicts. The API does not publish
 * `metadata.breaks`; the `rejoined` outcome in `punches` is what shows the
 * return was joined and the two break entries are pinned by the backend test
 * (tests/Feature/Attendance/RejoinSplitDayTest.php).
 *
 * Needs `queue:work` against the API's database: the terminal is answered
 * `accepted` at once and the punches are worked by the queue.
 */

const SPLIT_DAY = [
  { clock: "08:50", status: "checkIn" },
  { clock: "12:05", status: "checkOut" },
  { clock: "13:55", status: "checkIn" },
  { clock: "17:00", status: "checkOut" },
] as const;

/** The most recent weekday at least `from` days back, as a `YYYY-MM-DD` Addis date. */
function weekdayBack(from: number, skipping: Set<string>): string {
  for (let back = from; back < from + 90; back += 1) {
    const day = addisDate(new Date(Date.now() - back * 86_400_000));
    const weekday = new Date(`${day}T12:00:00+03:00`).getUTCDay();
    if (weekday !== 0 && weekday !== 6 && !skipping.has(day)) return day;
  }
  throw new Error("no free weekday in the last 90 days");
}

test("in, out, in, out from the terminal reads back as one record and no conflict", async ({
  browser,
  request,
}) => {
  test.setTimeout(240_000);
  const badge = "9301";

  const admin = await as(browser, "e2e-dev", "admin");
  try {
    const { page } = admin;
    const person = await employeeId(page, "DEV-0001");

    // A recent working day this person has no record on, so a second run
    // finds its own day and nothing earlier is rejoined into.
    const taken = new Set<string>();
    const earlier = await recordsOf(
      page,
      person,
      addisDate(new Date(Date.now() - 120 * 86_400_000)),
      addisDate(new Date()),
    );
    for (const row of earlier) taken.add(row.date);
    const date = weekdayBack(7, taken);

    for (const punch of SPLIT_DAY) {
      expect(
        await hikvisionPunch(
          request,
          "e2e-dev",
          badge,
          addis(date, punch.clock),
          punch.status,
        ),
      ).toBe("accepted");
    }

    // The queue works them; wait for the day to read back complete.
    let rows = live(await recordsOf(page, person, date, date));
    const deadline = Date.now() + 90_000;
    while (
      (rows.length !== 1 ||
        rows[0].check_out === null ||
        rows[0].punches.length < 4) &&
      Date.now() < deadline
    ) {
      await page.waitForTimeout(2000);
      rows = live(await recordsOf(page, person, date, date));
    }

    expect(
      rows,
      "one record for the day, 90s after the punches: the queue may not be draining, or the day was split in two",
    ).toHaveLength(1);

    const [record] = rows;
    expect(record.source).toBe("biometric");
    expect(record.conflict).toBeNull();
    expect(
      Math.abs(
        new Date(record.check_in!).getTime() - addis(date, "08:50").getTime(),
      ),
    ).toBeLessThan(2000);
    expect(
      Math.abs(
        new Date(record.check_out!).getTime() - addis(date, "17:00").getTime(),
      ),
    ).toBeLessThan(2000);
    expect(
      record.punches.map((punch) => `${punch.kind}:${punch.outcome}`),
    ).toEqual([
      "check_in:created",
      "check_out:applied",
      "check_in:rejoined",
      "check_out:applied",
    ]);

    // Nothing is waiting for this person in Conflicts.
    const conflicts = await api(page, "GET", "/attendance/conflicts");
    expect(conflicts.status).toBe(200);
    expect(JSON.stringify(conflicts.data)).not.toContain(person);
  } finally {
    await admin.context.close();
  }
});
