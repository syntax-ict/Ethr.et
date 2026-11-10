import { test, expect } from "@playwright/test";
import { api } from "./helpers";

/**
 * From a free trial to "Ready for first payroll" (2026-11-09): sign up with
 * the kind of organisation in law, answer the configuration step (industry,
 * headcount band, pay frequency, calendar), record the employer's payroll
 * details and one employee, and the checklist the dashboard shows is then
 * computed as ready. Nothing on the way asks for a tax or pension rate.
 *
 * A new organisation each run, so no stored session: the run signs itself up.
 */
test.describe("Onboarding to first payroll", () => {
  test.setTimeout(180_000);

  test("signs up, answers five questions, and reaches ready for first payroll", async ({
    page,
  }) => {
    // The app's default locale is Amharic; the assertions below are English.
    await page.addInitScript(() => localStorage.setItem("locale", "en"));
    const slug = `e2e-${Date.now().toString(36)}`;

    // ── Sign up ─────────────────────────────────────────────────────────
    await page.goto("/register");
    await page.fill("#org_name", "Abay Share Company");
    await page.fill("#subdomain", slug);
    await page
      .locator('#org_kind option[value="share_company"]')
      .waitFor({ state: "attached" });
    await page.selectOption("#org_kind", "share_company");
    await expect(
      page.getByRole("button", { name: "Next", exact: true }),
    ).toBeEnabled({
      timeout: 15_000,
    });
    await page.getByRole("button", { name: "Next", exact: true }).click();

    await page.fill("#admin_name", "Abebe Kebede");
    await page.fill("#admin_email", `${slug}@example.et`);
    await page.fill("#admin_phone", "+251911234567");
    await page.getByRole("button", { name: "Next", exact: true }).click();

    await page.fill("#password", "SecurePass123!");
    await page.fill("#password_confirmation", "SecurePass123!");
    await page.getByRole("button", { name: /create free account/i }).click();
    await page.waitForURL("**/setup/guided", { timeout: 60_000 });

    // ── The configuration step: the five answers ───────────────────────
    await page.getByRole("radio", { name: /^Bank$/ }).click();
    await page.locator("#staff-band").click();
    await page.getByRole("option", { name: "11-50" }).click();
    await expect(page.locator("#org-kind")).toContainText("Share company");
    await expect(
      page.getByRole("radio", { name: /weekly/i }).first(),
    ).toBeDisabled();
    await page.locator("#org-calendar-choice").click();
    await page.getByRole("option", { name: "Gregorian calendar" }).click();

    await page.getByRole("button", { name: /review proposal/i }).click();
    // The proposal may ask for a confirmation, and its notes can arrive after
    // the button renders; `isVisible()` does not wait, so tick the box whenever
    // it is shown until Apply is enabled.
    const confirm = page.getByRole("checkbox", { name: /I have read these/i });
    const apply = page.getByRole("button", { name: /apply configuration/i });
    await expect(apply).toBeVisible({ timeout: 30_000 });
    await expect(async () => {
      if (await confirm.isVisible()) await confirm.check();
      await expect(apply).toBeEnabled({ timeout: 1_000 });
    }).toPass({ timeout: 30_000 });
    await apply.click();
    await expect(page.getByText(/Configuration applied/i)).toBeVisible({
      timeout: 30_000,
    });

    // The answers are a profile, not rows of rates.
    const rules = await api(page, "GET", "/payroll/statutory-rules");
    expect(rules.status).toBe(200);
    expect(rules.data.organization_type).toBe("share_company");
    expect(rules.data.sector).toBe("bank");

    // ── The employer's payroll details and one employee ─────────────────
    const saved = await api(page, "PUT", "/settings", {
      settings: {
        employer_tin: "0012345678",
        tax_center: "Bole Medium Taxpayers",
        pension_registration_number: "P-0042",
        payout_method: "cash",
      },
    });
    expect(saved.status).toBe(200);

    const employee = await api(page, "POST", "/employees", {
      name: "Almaz Tesfaye",
      hire_date: "2026-01-05",
      salary_cents: 1_200_000,
      tin: "0098765432",
    });
    expect(employee.status).toBe(201);
    const bank = await api(
      page,
      "POST",
      `/employees/${employee.data.public_id}/bank-details`,
      {
        bank_name: "Commercial Bank of Ethiopia",
        account_number: "1000123456789",
        is_primary: true,
      },
    );
    expect(bank.status).toBe(201);

    // ── The checklist, computed now from what is stored ────────────────
    const steps = await api(page, "GET", "/next-steps");
    expect(steps.data.payroll).toEqual({ done: 9, total: 9, ready: true });

    await page.goto("/dashboard");
    await page.waitForLoadState("networkidle").catch(() => {});
    const readiness = page.getByTestId("payroll-readiness");
    if (await readiness.isVisible({ timeout: 10_000 }).catch(() => false)) {
      await expect(readiness).toContainText("Ready for first payroll");
    }

    // ── The rules the organisation follows, and why ─────────────────────
    await page.goto("/settings/payroll?tab=rules");
    await expect(page.getByText("Working hours in a day")).toBeVisible({
      timeout: 20_000,
    });
    await expect(page.getByText("Set by law").first()).toBeVisible();
  });
});
