import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import { as, earlierToday, hikvisionPunch } from "./support";

/**
 * A device page says what the terminal did today, and the numbers move.
 *
 * A badge nobody holds is pushed twice, as a terminal does when it is not
 * answered in time. Read back from the device's own resource (never a toast):
 * both calls are received, one is stored and held, and the second is counted
 * as sent again. The punches are worked by the queue, so this needs
 * `queue:work` against the API's database, as the other device journeys do.
 */

type Today = {
  day: string;
  received: number;
  recorded: number;
  held: number;
  duplicate: number;
  quarantined: number;
  waiting: number;
};

test("today's counts move when a terminal pushes, and a resend counts as sent again", async ({
  browser,
  request,
}) => {
  test.setTimeout(240_000);
  // A badge no one holds, new on every run, so it is held and not recorded.
  const badge = `8${String(Date.now()).slice(-6)}`;

  const admin = await as(browser, "e2e-dev", "admin");
  try {
    const { page } = admin;

    const devices = await api(page, "GET", "/devices?per_page=50");
    expect(devices.status, "device list").toBe(200);
    const terminal = (
      devices.data.data as Array<{ public_id: string; adapter_type: string }>
    ).find((row) => row.adapter_type === "hikvision");
    expect(terminal, "the organisation's Hikvision terminal").toBeTruthy();

    const today = async (): Promise<Today> => {
      const device = await api(page, "GET", `/devices/${terminal!.public_id}`);
      expect(device.status, "device").toBe(200);
      return device.data.today as Today;
    };

    const before = await today();
    expect(before.day).toMatch(/^\d{4}-\d{2}-\d{2}$/);

    const at = earlierToday(30);
    expect(await hikvisionPunch(request, "e2e-dev", badge, at, "checkIn")).toBe(
      "accepted",
    );
    expect(await hikvisionPunch(request, "e2e-dev", badge, at, "checkIn")).toBe(
      "accepted",
    );

    // Received and sent again are counted on arrival; held when the queue has
    // worked the row, so wait for it.
    let after = await today();
    const deadline = Date.now() + 90_000;
    while (after.held < before.held + 1 && Date.now() < deadline) {
      await page.waitForTimeout(2000);
      after = await today();
    }

    expect(
      after.held,
      "the unknown badge held, 90s after the push: the queue may not be draining",
    ).toBe(before.held + 1);
    expect(after.received).toBe(before.received + 2);
    expect(after.duplicate).toBe(before.duplicate + 1);
    expect(after.recorded).toBe(before.recorded);
    expect(after.quarantined).toBe(before.quarantined);
  } finally {
    await admin.context.close();
  }
});
