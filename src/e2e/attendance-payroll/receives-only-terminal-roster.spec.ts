import { expect, test } from "@playwright/test";
import { api } from "../helpers";
import { as } from "./support";

/**
 * A terminal that only sends cannot be asked who is enrolled on it, and the
 * app says so instead of listing nobody.
 *
 * A Hikvision terminal in push mode posts its punches to ETHR and has no
 * address ETHR can call. Asking it for a roster used to stage an empty list,
 * which read as "nobody is enrolled". The API now refuses with 409
 * `device-roster-unavailable`, "Discover enrolled users" is not offered where
 * every terminal an organisation has is of that kind, the page says why and
 * points at Devices, and the terminal's own Enrollments dialog shows the
 * refusal's reason with no way to bring anyone in.
 */
test("a terminal that only sends is refused a roster, and nothing offers to read one", async ({ browser }) => {
  // A development server compiles each page the first time it is asked for.
  test.setTimeout(240_000);
  const admin = await as(browser, "e2e-dev", "admin");
  try {
    const { page } = admin;

    // The API, for the organisation's one terminal.
    const devices = await api(page, "GET", "/devices");
    expect(devices.status, "device list").toBe(200);
    const terminal = (
      devices.data.data as Array<{
        public_id: string;
        adapter_type: string;
        receives_only?: boolean;
      }>
    )[0];
    expect(terminal.adapter_type).toBe("hikvision");
    expect(terminal.receives_only).toBe(true);

    const roster = await api(page, "GET", `/devices/${terminal.public_id}/enrollments`);
    expect(roster.status).toBe(409);
    expect(roster.data.code).toBe("device-roster-unavailable");
    expect(roster.data.detail).toContain("sends its punches to ETHR and cannot be asked who is enrolled on it");

    // Bringing in people from a device: nothing to discover, and why.
    await page.goto("/employees/from-device");
    await expect(page.getByText("From a spreadsheet or list")).toBeVisible();
    await expect(page.getByRole("button", { name: "Discover enrolled users" })).toHaveCount(0);
    await expect(
      page.getByText("Your terminal sends its punches to ETHR and cannot be asked who is enrolled on it."),
    ).toBeVisible();

    // The terminal's own Enrollments dialog shows the refusal's reason.
    await page.goto("/devices");
    await page.getByRole("button", { name: "Actions" }).first().click();
    await page.getByRole("menuitem", { name: "Discover enrolled users" }).click();
    // The refusal is asked for once more before it is shown.
    const dialog = page.getByRole("dialog");
    await expect(dialog.getByText("This terminal cannot be asked who is enrolled")).toBeVisible({ timeout: 30_000 });
    await expect(dialog.getByText(roster.data.detail)).toBeVisible();
    await expect(page.getByRole("link", { name: "Bring these people into ETHR" })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Bring these people into ETHR" })).toHaveCount(0);
  } finally {
    await admin.context.close();
  }
});
