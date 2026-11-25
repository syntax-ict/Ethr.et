import { test, expect, type Page } from "@playwright/test";
import { api } from "./helpers";

// PR #899, one device form: Register and the list's Edit open the same form,
// so Edit has the Location and clock-zone fields it used to lack, and an ADMS
// terminal's allowlist is optional with no Auto-Sync switch. Every assertion
// that matters reads the device back from the API rather than trusting a toast.
// Devices need the tenant admin: the API returns webhook_ip_allowlist only to it.
test.describe("Device form", () => {
  test.use({ storageState: "e2e/.auth/admin.json" });

  const stamp = Date.now();
  const name = `E2E Form ${stamp}`;
  const serial = `E2EFORM${stamp}`;
  const location = `Gate ${stamp}`;
  let publicId: string | null = null;

  async function findDevice(page: Page) {
    const res = await api(
      page,
      "GET",
      `/devices?search=${encodeURIComponent(serial)}`,
    );
    expect(res.status).toBe(200);
    return (res.data.data as Array<Record<string, unknown>>).find(
      (d) => d.serial_number === serial,
    );
  }

  test.afterAll(async ({ browser }) => {
    const context = await browser.newContext({
      storageState: "e2e/.auth/admin.json",
    });
    try {
      const page = await context.newPage();
      await page.goto("/devices");
      const device = await findDevice(page);
      const id = (device?.public_id as string | undefined) ?? publicId;
      if (id) await api(page, "DELETE", `/devices/${id}`);
    } finally {
      await context.close();
    }
  });

  test("register an ADMS terminal, then edit it from the list", async ({
    page,
  }) => {
    await page.goto("/devices");
    await page.getByRole("button", { name: "Add Device" }).click();

    const form = page.getByRole("dialog", { name: "Register Device" });
    await expect(form).toBeVisible();
    await form.locator("#device-name").fill(name);
    await form.locator("#adapter-type").click();
    await page.getByRole("option", { name: /^ZKTeco \(ADMS/ }).click();
    await form.locator("#serial-number").fill(serial);

    // One branch is filled in for the admin; with several, the first is picked.
    const branch = form.locator("#branch");
    if (await branch.isVisible()) {
      if ((await branch.innerText()).includes("Select branch")) {
        await branch.click();
        await page.getByRole("option").first().click();
      }
    }

    await expect(form.locator("#device-allowlist")).toBeVisible();
    await expect(
      form.getByText(/Optional for a ZKTeco ADMS terminal/),
    ).toBeVisible();
    await expect(form.getByRole("switch", { name: /auto-?sync/i })).toHaveCount(
      0,
    );
    await expect(form.locator("#device-allowlist")).toHaveValue("");

    await form.getByRole("button", { name: "Register", exact: true }).click();

    // The setup dialog for an ADMS terminal opens once the device exists.
    const setup = page.getByRole("dialog", { name: "Connect the terminal" });
    await expect(setup).toBeVisible();
    await setup.getByRole("button", { name: "Done", exact: true }).click();
    await expect(setup).toBeHidden();

    const created = await findDevice(page);
    expect(created?.name).toBe(name);
    publicId = created!.public_id as string;

    // Edit from the list's card actions menu.
    const card = page
      .locator("div.rounded-xl")
      .filter({ has: page.getByRole("link", { name }) })
      .filter({ has: page.getByRole("button", { name: "Actions" }) });
    await expect(card).toBeVisible();
    await card.getByRole("button", { name: "Actions" }).click();
    await page.getByRole("menuitem", { name: "Edit", exact: true }).click();

    const edit = page.getByRole("dialog", { name: "Edit Device" });
    await expect(edit).toBeVisible();
    await expect(edit.locator("#location-description")).toBeVisible();
    await expect(edit.getByText("Device clock time zone")).toBeVisible();
    await expect(edit.locator("#device-timezone")).toBeVisible();

    await edit.locator("#device-allowlist").fill("203.0.113.7");
    await edit.locator("#location-description").fill(location);
    await edit.getByRole("button", { name: "Save changes" }).click();
    await expect(edit).toBeHidden();

    // What the API reads back, not the toast.
    await expect
      .poll(async () => (await findDevice(page))?.location_description)
      .toBe(location);
    const saved = await findDevice(page);
    expect(saved?.webhook_ip_allowlist).toEqual(["203.0.113.7"]);
  });
});
