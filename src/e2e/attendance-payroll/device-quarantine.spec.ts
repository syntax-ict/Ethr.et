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
 * A punch the engine will not take is kept, not lost.
 *
 * The terminal sends the evening check-out of a day before anything opened
 * that day, so the engine has no check-in to close and refuses it. Until
 * 2026-11-20 that row was marked processed and deleted a week later. Now it
 * waits in "Punches that need attention" with its reason. After the missing
 * check-in is in, Replay sends the check-out through again and the day is one
 * complete record.
 *
 * Everything is read back from the API (the device's quarantine list and the
 * attendance records), never from a toast. Needs `queue:work` against the
 * API's database: the terminal is answered `accepted` at once and the punches
 * are worked by the queue.
 */

/** The most recent weekday at least `from` days back, as a `YYYY-MM-DD` Addis date. */
function weekdayBack(from: number, skipping: Set<string>): string {
  for (let back = from; back < from + 90; back += 1) {
    const day = addisDate(new Date(Date.now() - back * 86_400_000));
    const weekday = new Date(`${day}T12:00:00+03:00`).getUTCDay();
    if (weekday !== 0 && weekday !== 6 && !skipping.has(day)) return day;
  }
  throw new Error("no free weekday in the last 90 days");
}

type QuarantineRow = {
  public_id: string;
  badge: string;
  failure: string | null;
  occurred_raw: string;
  raw: string | null;
};

test("a check-out with no check-in waits on the device, and replays once the day is open", async ({
  browser,
  request,
}) => {
  test.setTimeout(300_000);
  const badge = "9301";

  const admin = await as(browser, "e2e-dev", "admin");
  try {
    const { page } = admin;
    const person = await employeeId(page, "DEV-0001");

    const devices = await api(page, "GET", "/devices?per_page=50");
    expect(devices.status, "device list").toBe(200);
    const terminal = (
      devices.data.data as Array<{ public_id: string; adapter_type: string }>
    ).find((row) => row.adapter_type === "hikvision");
    expect(terminal, "the organisation's Hikvision terminal").toBeTruthy();
    const deviceId = terminal!.public_id;

    const taken = new Set<string>();
    const earlier = await recordsOf(
      page,
      person,
      addisDate(new Date(Date.now() - 120 * 86_400_000)),
      addisDate(new Date()),
    );
    for (const row of earlier) taken.add(row.date);
    const date = weekdayBack(7, taken);
    const checkOutAt = addis(date, "17:05");

    expect(
      await hikvisionPunch(request, "e2e-dev", badge, checkOutAt, "checkOut"),
    ).toBe("accepted");

    // The queue refuses it: wait for it to show on the device's list.
    let waiting: QuarantineRow | undefined;
    const deadline = Date.now() + 90_000;
    while (waiting === undefined && Date.now() < deadline) {
      const list = await api(page, "GET", `/devices/${deviceId}/quarantine`);
      expect(list.status, "quarantine list").toBe(200);
      waiting = (list.data.data as QuarantineRow[]).find(
        (row) =>
          row.badge === badge &&
          row.failure === "refused:no_open_check_in" &&
          row.occurred_raw.includes(date),
      );
      if (waiting === undefined) await page.waitForTimeout(2000);
    }
    expect(
      waiting,
      "the refused check-out on the device's list, 90s after the punch: the queue may not be draining",
    ).toBeTruthy();
    expect(waiting!.raw, "an administrator sees what the terminal sent").toContain(
      badge,
    );
    expect(live(await recordsOf(page, person, date, date))).toHaveLength(0);

    // The morning check-in arrives late and opens the day.
    expect(
      await hikvisionPunch(
        request,
        "e2e-dev",
        badge,
        addis(date, "08:55"),
        "checkIn",
      ),
    ).toBe("accepted");
    let rows = live(await recordsOf(page, person, date, date));
    const opened = Date.now() + 90_000;
    while (rows.length !== 1 && Date.now() < opened) {
      await page.waitForTimeout(2000);
      rows = live(await recordsOf(page, person, date, date));
    }
    expect(rows, "the check-in opened one record").toHaveLength(1);
    expect(rows[0].check_out).toBeNull();

    // Replay the kept check-out.
    const replay = await api(page, "POST", "/devices/quarantine/replay", {
      ids: [waiting!.public_id],
    });
    expect(replay.status, "replay").toBe(200);
    expect(replay.data.replayed).toBe(1);

    rows = live(await recordsOf(page, person, date, date));
    const closed = Date.now() + 90_000;
    while (rows[0]?.check_out === null && Date.now() < closed) {
      await page.waitForTimeout(2000);
      rows = live(await recordsOf(page, person, date, date));
    }
    expect(rows, "still one record for the day").toHaveLength(1);
    expect(
      Math.abs(new Date(rows[0].check_out!).getTime() - checkOutAt.getTime()),
    ).toBeLessThan(2000);

    // It has left the list, and a second replay of the same id changes nothing.
    const after = await api(page, "GET", `/devices/${deviceId}/quarantine`);
    expect(
      (after.data.data as QuarantineRow[]).some(
        (row) => row.public_id === waiting!.public_id,
      ),
    ).toBe(false);
    const again = await api(page, "POST", "/devices/quarantine/replay", {
      ids: [waiting!.public_id],
    });
    expect(again.data.replayed).toBe(0);
    expect(live(await recordsOf(page, person, date, date))).toHaveLength(1);
  } finally {
    await admin.context.close();
  }
});
