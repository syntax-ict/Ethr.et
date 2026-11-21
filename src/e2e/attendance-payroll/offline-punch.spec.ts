import { expect, test, type Page } from "@playwright/test";
import { api } from "../helpers";
import {
  addisDate,
  as,
  employeeId,
  live,
  origin,
  recordsOf,
  statePath,
} from "./support";

/**
 * A punch saved with no signal, sent from a page that is not the punch screen,
 * and read back from what the server holds.
 *
 * It signs in as emp6 (ATT-0006), whom no other journey punches for. The phone
 * holds the server's offline grant before the signal goes (fetched by the punch
 * screen while online), the tap saves the punch in IndexedDB instead of posting
 * it, and the signal comes back while the person is on another page: the
 * shell's sync agent sends it, not the punch screen. Afterwards the server has
 * one record that names the offline source, and the phone no longer holds the
 * punch.
 *
 * Service workers are blocked: the worker does not intercept in local
 * development, and with it out of the way the page is the only thing that can
 * send the punch.
 */

const QUEUE = { db: "ethr-offline", store: "attendance_queue" };

type QueuedPunch = {
  idempotency_key: string;
  type: string;
  synced: number;
  sync_error: string | null;
  offline_token: string;
};

/** Every record the phone holds for the queue, read straight from IndexedDB. */
function queuedPunches(page: Page): Promise<QueuedPunch[]> {
  return page.evaluate(
    ({ db, store }) =>
      new Promise<QueuedPunch[]>((resolve, reject) => {
        const open = indexedDB.open(db, 1);
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
          const all = open.result
            .transaction(store, "readonly")
            .objectStore(store)
            .getAll();
          all.onsuccess = () => resolve(all.result as QueuedPunch[]);
          all.onerror = () => reject(all.error);
        };
      }),
    QUEUE,
  );
}

test("a punch saved offline is sent from another page and recorded as an offline punch", async ({
  browser,
}) => {
  const context = await browser.newContext({
    baseURL: origin("e2e-att"),
    storageState: statePath("e2e-att", "emp6"),
    viewport: { width: 360, height: 640 },
    geolocation: { latitude: 9.0192, longitude: 38.7525 },
    permissions: ["geolocation"],
    serviceWorkers: "block",
  });
  const page = await context.newPage();
  const admin = await as(browser, "e2e-att", "admin");

  try {
    let key = "";

    await test.step("online: nobody has punched, and the phone takes the offline grant", async () => {
      await page.goto("/dashboard");
      await page.waitForLoadState("networkidle").catch(() => {});

      const before = await api(page, "GET", "/attendance/my-shift");
      expect(before.status).toBe(200);
      expect(before.data.attendance.status).toBe("not_checked_in");

      await page.goto("/attendance/mobile");
      await expect(
        page.getByRole("button", { name: /^check in$/i }),
      ).toBeEnabled({ timeout: 30000 });

      await expect
        .poll(
          () =>
            page.evaluate(() =>
              Object.keys(window.localStorage).some((name) =>
                name.startsWith("ethr.offline_grant."),
              ),
            ),
          { timeout: 30000 },
        )
        .toBe(true);
    });

    await test.step("offline: the tap saves the punch on the phone and posts nothing", async () => {
      const posted: string[] = [];
      page.on("request", (request) => {
        if (
          request.method() === "POST" &&
          request.url().includes("/attendance/mobile/check-in")
        ) {
          posted.push(request.url());
        }
      });

      await context.setOffline(true);
      await expect
        .poll(() => page.evaluate(() => navigator.onLine))
        .toBe(false);

      const checkIn = page.getByRole("button", { name: /^check in$/i });
      await expect(checkIn).toBeEnabled();
      await checkIn.click();

      await expect
        .poll(async () => (await queuedPunches(page)).length, {
          timeout: 15000,
        })
        .toBe(1);
      const [queued] = await queuedPunches(page);
      expect(queued.type).toBe("check_in");
      expect(queued.synced).toBe(0);
      // The grant fetched while online travels with the punch.
      expect(queued.offline_token).not.toMatch(/^offline-\d+$/);
      key = queued.idempotency_key;

      expect(posted).toEqual([]);
    });

    await test.step("the signal returns while the person is on the dashboard", async () => {
      // Held off the punch screen first: it would send the punch itself the
      // moment the signal returned, and this step is about the other pages.
      await context.route("**/attendance/sync", (route) => route.abort());
      const held = page.waitForEvent(
        "requestfailed",
        (request) => request.url().includes("/attendance/sync"),
        { timeout: 30000 },
      );
      await context.setOffline(false);
      await held;
      expect((await queuedPunches(page)).map((row) => row.synced)).toEqual([0]);
      await context.unroute("**/attendance/sync");

      const sent = page.waitForResponse(
        (response) =>
          response.url().includes("/attendance/sync") &&
          response.request().method() === "POST",
        { timeout: 60000 },
      );
      await page.goto("/dashboard");
      expect((await sent).status()).toBe(200);
    });

    await test.step("the server holds one offline punch, and the phone has let go of it", async () => {
      const after = await api(page, "GET", "/attendance/my-shift");
      expect(after.data.attendance.status).toBe("checked_in");

      const person = await employeeId(admin.page, "ATT-0006");
      const today = addisDate(new Date());
      const records = await recordsOf(admin.page, person, today, today);
      expect(live(records)).toHaveLength(1);

      const [record] = records;
      expect(record.check_in).not.toBeNull();
      expect(record.source).toBe("offline_mobile");
      expect(
        record.punches.map(
          (punch) => `${punch.kind}:${punch.source}:${punch.outcome}`,
        ),
      ).toEqual(["check_in:offline_mobile:created"]);

      await expect
        .poll(
          async () =>
            (await queuedPunches(page)).some(
              (row) => row.idempotency_key === key,
            ),
          { timeout: 15000 },
        )
        .toBe(false);
    });
  } finally {
    await context.close();
    await admin.context.close();
  }
});
