import { expect, test, type BrowserContext, type Page } from "@playwright/test";
import { api } from "../helpers";
import {
  addisDate,
  as,
  employeeId,
  live,
  origin,
  plantPresenceCheck,
  recordsOf,
  statePath,
} from "./support";

/**
 * Presence checks, from the phone and from the reviewer's queue.
 *
 * ATT-0005 (emp5) is the one person at the Annex, the branch with a place on
 * the map, in an organisation with checks switched on. A check is planted the
 * way the scheduler's push would have made it (`e2e:plant-presence-check`,
 * local and testing only): it lives 15 minutes and is sent at a derived time,
 * so a journey cannot wait for one.
 *
 *   A  answered from inside the workplace: nothing pending, no finding.
 *   B  answered from 20 km away: a finding in the queue, and "Not excused"
 *      leaves the day's record standing.
 *
 * Both read what the server holds through the API, never a toast. They share
 * one record (one person, one day) and run in this order.
 */
const ANNEX = { latitude: 9.03, longitude: 38.74 };
const FAR_AWAY = { latitude: 9.21, longitude: 38.74 };

type Flag = {
  public_id: string;
  type: string;
  punch: string;
  status: string;
  employee: { public_id: string } | null;
};

test.describe.serial("presence checks", () => {
  let context: BrowserContext;
  let phone: Page;
  let admin: Awaited<ReturnType<typeof as>>;
  let person: string;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext({
      baseURL: origin("e2e-att"),
      storageState: statePath("e2e-att", "emp5"),
      viewport: { width: 360, height: 640 },
      geolocation: ANNEX,
      permissions: ["geolocation"],
    });
    phone = await context.newPage();
    admin = await as(browser, "e2e-att", "admin");
    person = await employeeId(admin.page, "ATT-0005");

    await phone.goto("/dashboard");
    await phone.waitForLoadState("networkidle").catch(() => {});

    // Checked in at the Annex, from inside it: the check needs an open record.
    const shift = await api(phone, "GET", "/attendance/my-shift");
    expect(shift.status).toBe(200);
    expect(
      shift.data.presence.enabled,
      "checks are on and the Annex has a place",
    ).toBe(true);
    if (shift.data.attendance.status !== "checked_in") {
      const punched = await api(phone, "POST", "/attendance/mobile/check-in", {
        idempotency_key: `e2e-presence-${Date.now()}`,
        ...ANNEX,
        accuracy: 10,
      });
      expect(punched.status, "check-in").toBe(201);
    }
  });

  test.afterAll(async () => {
    await context?.close();
    await admin?.context.close();
  });

  async function openCard(): Promise<void> {
    await phone.goto("/attendance/mobile");
    await expect(
      phone.getByText("Confirm you are at work").first(),
    ).toBeVisible({ timeout: 30000 });
  }

  async function presenceFlags(status: "open" | "rejected"): Promise<Flag[]> {
    const found = await api(
      admin.page,
      "GET",
      `/attendance/integrity?per_page=100&filter[status]=${status}`,
    );
    expect(found.status, "integrity list").toBe(200);

    return (found.data.data as Flag[]).filter(
      (flag) =>
        flag.type === "outside_geofence" &&
        flag.punch === "presence" &&
        flag.employee?.public_id === person,
    );
  }

  test("answered from inside the workplace, nothing is pending and nothing is filed", async () => {
    // Today's date, so the day exists for the whole journey.
    const today = addisDate(new Date());
    expect(
      live(await recordsOf(admin.page, person, today, today)),
    ).toHaveLength(1);

    plantPresenceCheck("e2e-att", "ATT-0005", 0);
    const waiting = await api(phone, "GET", "/attendance/my-shift");
    expect(
      waiting.data.presence.pending,
      "the planted check is pending",
    ).not.toBeNull();

    await openCard();
    const answer = phone.getByRole("button", { name: "I am here" });
    await expect(answer).toBeEnabled({ timeout: 30000 });

    // Nothing sits in the way of the phone's own button at 360 px.
    const box = await answer.boundingBox();
    expect(box, "button box").not.toBeNull();
    expect(box!.x + box!.width).toBeLessThanOrEqual(360);

    const answered = phone.waitForResponse(
      (response) =>
        response.url().includes("/attendance/presence/confirm") &&
        response.request().method() === "POST",
    );
    await answer.click();
    const response = await answered;
    expect(response.status()).toBe(200);
    expect((await response.json()).status).toBe("confirmed");

    // What the server holds.
    const after = await api(phone, "GET", "/attendance/my-shift");
    expect(after.data.presence.pending).toBeNull();
    expect(await presenceFlags("open")).toHaveLength(0);
  });

  test("answered from outside, it is filed, and Not excused keeps the day", async () => {
    plantPresenceCheck("e2e-att", "ATT-0005", 1);

    await context.setGeolocation(FAR_AWAY);
    await openCard();
    const answer = phone.getByRole("button", { name: "I am here" });
    await expect(answer).toBeEnabled({ timeout: 30000 });

    const answered = phone.waitForResponse(
      (response) =>
        response.url().includes("/attendance/presence/confirm") &&
        response.request().method() === "POST",
    );
    await answer.click();
    const response = await answered;
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.status).toBe("outside");
    expect(body.distance_meters).toBeGreaterThan(10000);

    expect(
      (await api(phone, "GET", "/attendance/my-shift")).data.presence.pending,
    ).toBeNull();

    // The queue lists one open finding, of the existing type, for this person.
    const open = await presenceFlags("open");
    expect(open).toHaveLength(1);
    const [flag] = open;

    // The reviewer decides it: Not excused.
    await admin.page.goto("/attendance/integrity");
    const card = admin.page.getByTestId(`integrity-flag-${flag.public_id}`);
    await expect(card).toBeVisible({ timeout: 30000 });
    await expect(
      card.getByText("Outside the workplace at a presence check"),
    ).toBeVisible();
    await expect(
      card.getByRole("button", { name: "Excused", exact: true }),
    ).toBeVisible();

    await card.getByRole("button", { name: "Not excused" }).click();
    const dialog = admin.page.getByRole("dialog");
    await expect(dialog.getByText(/The day.s record is kept/)).toBeVisible();
    await dialog.getByLabel(/reason/i).fill("Answered from 20 km away.");

    const reviewed = admin.page.waitForResponse(
      (reply) =>
        reply
          .url()
          .includes(`/attendance/integrity/${flag.public_id}/review`) &&
        reply.request().method() === "PUT",
    );
    await dialog.getByRole("button", { name: "Not excused" }).click();
    expect((await reviewed).status()).toBe(200);

    // The decision is recorded, and the day's record still stands.
    expect(await presenceFlags("open")).toHaveLength(0);
    expect(
      (await presenceFlags("rejected")).map((rejected) => rejected.public_id),
    ).toContain(flag.public_id);

    const today = addisDate(new Date());
    const rows = await recordsOf(admin.page, person, today, today);
    expect(rows).toHaveLength(1);
    expect(live(rows), "the day is not voided").toHaveLength(1);
    expect(rows[0].status).not.toBe("voided");
    expect(rows[0].check_in).not.toBeNull();
  });
});
