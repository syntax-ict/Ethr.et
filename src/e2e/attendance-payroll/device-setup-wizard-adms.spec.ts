import { expect, test, type APIRequestContext } from "@playwright/test";
import { api } from "../helpers";
import {
  addisClock,
  addisDate,
  as,
  employeeId,
  live,
  minutesAgo,
  recordsWhen,
} from "./support";

/**
 * A new ZKTeco terminal is set up through the guided wizard, end to end: the
 * administrator registers it, the terminal's first call is refused (its office
 * address is not on the allowlist), one click on Allow fixes that without
 * typing an address, and the next call is admitted and its punch recorded.
 *
 * The terminal is simulated by plain HTTP to the ADMS endpoints, as the backend
 * ADMS tests do: the serial in `SN`, the organisation's own address in `Host`
 * where the deployment gives organisations one, and nothing that names the
 * organisation otherwise. `/iclock` is a route of the API, not of the Next
 * app, so the calls go to the API's own address (E2E_API_ORIGIN, default
 * http://localhost:8000).
 *
 * The punch is worked by the queue afterwards, so this needs `queue:work`
 * running against the same database, as the other device journeys do.
 */
const API_ORIGIN = process.env.E2E_API_ORIGIN ?? "http://localhost:8000";

async function terminalCall(
  request: APIRequestContext,
  serial: string,
  server: string,
  method: "GET" | "POST",
  path: string,
  query: Record<string, string> = {},
  body?: string,
) {
  const url = new URL(`${API_ORIGIN}/iclock/${path}`);
  url.searchParams.set("SN", serial);
  for (const [key, value] of Object.entries(query))
    url.searchParams.set(key, value);

  const headers: Record<string, string> = {
    Host: server,
    Accept: "text/plain",
  };
  if (body !== undefined) headers["Content-Type"] = "text/plain";

  return method === "GET"
    ? request.get(url.toString(), { headers })
    : request.post(url.toString(), { headers, data: body });
}

test("a ZKTeco terminal is set up in the wizard: refused, allowed with one click, then its punch is recorded", async ({
  browser,
  request,
}) => {
  const serial = `E2E-WIZ-${Date.now()}`;
  const admin = await as(browser, "e2e-dev", "admin");
  const { page } = admin;
  let deviceId: string | null = null;

  try {
    await page.goto("/devices");
    await page
      .getByRole("button", { name: /add device/i })
      .first()
      .click();

    const wizard = page.getByRole("dialog");
    await wizard.getByRole("radio", { name: "ZKTeco" }).check();
    await wizard
      .getByRole("radio", { name: /only sends from the office network/i })
      .check();
    await wizard.getByRole("button", { name: /^next$/i }).click();

    await wizard.getByLabel(/device name/i).fill("E2E wizard terminal");
    await wizard.getByLabel(/serial number/i).fill(serial);
    await wizard
      .getByRole("button", { name: /register and continue/i })
      .click();

    // The terminal step: the values to type, each with a copy button.
    const values = wizard.getByTestId("adms-setup");
    await expect(values).toContainText(serial);
    await expect(
      wizard.getByRole("button", { name: "Copy Server address" }),
    ).toBeVisible();
    const server = (await values.locator("dd").first().innerText()).trim();

    const listed = await api(page, "GET", "/devices?per_page=100");
    deviceId = (
      listed.data.data as { public_id: string; serial_number: string | null }[]
    ).find((device) => device.serial_number === serial)!.public_id;
    expect(deviceId).toBeTruthy();

    await wizard.getByRole("button", { name: /check the connection/i }).click();
    await expect(
      wizard.getByText(/waiting for the terminal's first call/i),
    ).toBeVisible();

    // The first call: the allowlist is empty, so it is refused, and the
    // wizard names the address it came from with a button to allow it.
    const refused = await terminalCall(
      request,
      serial,
      server,
      "GET",
      "cdata",
      { options: "all" },
    );
    expect(
      refused.status(),
      "a call from an address not on the allowlist",
    ).toBeGreaterThanOrEqual(400);

    // The server must have remembered the refusal and where it came from.
    // Without this, a Host the resolver did not take as the organisation's
    // own, or a cache another process cannot read, would look like a missing
    // button.
    const afterRefusal = await api(page, "GET", `/devices/${deviceId}`);
    expect(
      afterRefusal.data.adms?.last_rejected?.ip,
      "the refused call's address, as the device page reads it",
    ).toBeTruthy();

    const allow = wizard.getByRole("button", { name: /^allow /i });
    await expect(allow).toBeVisible({ timeout: 20_000 });
    await expect(
      wizard.getByText(/was refused|and was refused/i),
    ).toBeVisible();
    await allow.click();

    // The allowlist now holds that address, read back from the server.
    await expect
      .poll(async () => {
        const device = await api(page, "GET", `/devices/${deviceId}`);
        return (device.data.webhook_ip_allowlist as string[]).length;
      })
      .toBe(1);

    // The terminal tries again by itself: this call is admitted.
    const admitted = await terminalCall(
      request,
      serial,
      server,
      "GET",
      "cdata",
      { options: "all" },
    );
    expect(admitted.status(), "the same call after Allow").toBe(200);

    await expect(wizard.getByText(/^Connected\./)).toBeVisible({
      timeout: 20_000,
    });
    await wizard.getByRole("button", { name: /^next$/i }).click();

    // Done: an ADMS terminal reports its roster and takes commands.
    await expect(
      wizard.getByRole("button", { name: "Discover enrolled users" }),
    ).toBeVisible();

    // And a punch it sends is recorded for the person whose badge it is.
    const arrived = minutesAgo(30);
    const upload = await terminalCall(
      request,
      serial,
      server,
      "POST",
      "cdata",
      { table: "ATTLOG" },
      `9301\t${addisClock(arrived)}\t0\t1\t0\t0\t0\n`,
    );
    expect(upload.status(), "an ATTLOG upload").toBe(200);

    // The new terminal's own count: one punch received, and recorded for a
    // person rather than held as an unknown badge.
    await expect
      .poll(
        async () => {
          const device = await api(page, "GET", `/devices/${deviceId}`);
          const today = device.data.today ?? {};
          return `${today.received}:${today.recorded}:${today.held}`;
        },
        { timeout: 60_000 },
      )
      .toBe("1:1:0");

    // And it is on the person's day, at the time the terminal said. Other
    // journeys punch for this person too, so the record may already have been
    // open: the punch is looked for by its time, not by the record's.
    const person = await employeeId(page, "DEV-0001");
    const isThisPunch = (punch: { source: string; at: string }) =>
      punch.source === "biometric" &&
      Math.abs(new Date(punch.at).getTime() - arrived.getTime()) < 2000;
    await recordsWhen(
      page,
      person,
      addisDate(minutesAgo(1640)),
      addisDate(new Date()),
      (found) => live(found).some((row) => row.punches.some(isThisPunch)),
    );
  } finally {
    if (deviceId) await api(page, "DELETE", `/devices/${deviceId}`);
    await admin.context.close();
  }
});
