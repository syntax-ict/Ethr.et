import { test, expect, type Locator } from "@playwright/test";

/** Sets an Ethiopian-calendar date field: year, then month, then day. */
async function setEthiopianDate(
  scope: Locator,
  label: string,
  year: number,
  month: string,
  day: number,
) {
  const page = scope.page();
  await scope
    .getByRole("spinbutton", { name: `${label}, Ethiopian year` })
    .fill(String(year));
  await scope
    .getByRole("combobox", { name: `${label}, Ethiopian month` })
    .click();
  await page.getByRole("option", { name: month, exact: true }).click();
  await scope
    .getByRole("combobox", { name: `${label}, Ethiopian day` })
    .click();
  await page.getByRole("option", { name: String(day), exact: true }).click();
}

/**
 * The dry run before a payroll (2026-11-09, Phase 3): Run Payroll previews
 * every payslip and the law each figure was worked out under, and runs
 * nothing until the figures are ticked as checked. The test stops there, so
 * the demo organisation's payroll is left as it was.
 */
test.describe("Payroll preview", () => {
  test.use({ storageState: "e2e/.auth/admin.json" });
  test.setTimeout(120_000);

  test("previews the payslips and the rules, and runs only after confirmation", async ({
    page,
  }) => {
    await page.addInitScript(() => localStorage.setItem("locale", "en"));
    await page.goto("/payroll");

    await page
      .getByRole("button", { name: /run payroll/i })
      .first()
      .click({ timeout: 20_000 });
    const dialog = page.getByRole("dialog");
    // The organisation's calendar is Ethiopian: Tikimt 2019 is
    // 11 October to 9 November 2026.
    await setEthiopianDate(dialog, "Period Start", 2019, "Tikimt", 1);
    await setEthiopianDate(dialog, "Period End", 2019, "Tikimt", 30);

    const previewed = page.waitForResponse(
      (r) => r.url().includes("/payroll/preview") && r.status() === 200,
    );
    await dialog.getByRole("button", { name: /preview payslips/i }).click();
    const body = await (await previewed).json();
    expect(body.payslips.length).toBeGreaterThan(0);

    // Every payslip of the period, and what it was worked out under.
    await expect(
      dialog.getByText(body.payslips[0].employee.name).first(),
    ).toBeVisible({ timeout: 30_000 });
    await expect(dialog.getByText("Worked out under")).toBeVisible();
    await expect(dialog.getByText(/^Income tax: /)).toBeVisible();
    await expect(dialog.getByText(/Statutory rules version /)).toBeVisible();

    // Nothing runs until the figures are checked.
    const run = dialog.getByRole("button", { name: /process payroll/i });
    await expect(run).toBeDisabled();
    await dialog
      .getByRole("checkbox", { name: /I have checked these figures/i })
      .check();
    await expect(run).toBeEnabled();
  });
});
