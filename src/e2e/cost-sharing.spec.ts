import { test, expect } from '@playwright/test';
import { api } from './helpers';

/**
 * Higher-education cost sharing: record an obligation against an employee
 * picked by name, then suspend it.
 *
 * The form asked for the employee's public_id, pasted, until 2026-10-09 — an
 * identifier no screen shows. Suspending at the end is what keeps the spec
 * rerunnable: only an *active* obligation blocks a second one.
 */
test.describe('Payroll — cost sharing', () => {
  test.use({ storageState: 'e2e/.auth/admin.json' });

  test('an obligation is recorded for an employee picked by name, then suspended', async ({ page }) => {
    await page.goto('/payroll/cost-sharing');
    await page.waitForLoadState('networkidle').catch(() => {});

    // Someone with no active obligation, or the create is refused (one each).
    const obligations = await api(page, 'GET', '/payroll/cost-sharing?per_page=100&filter[status]=active');
    expect(obligations.status).toBe(200);
    const taken = new Set(
      obligations.data.data.map((o: { employee_public_id?: string; employee?: { public_id: string } }) =>
        o.employee_public_id ?? o.employee?.public_id,
      ),
    );
    const employees = await api(page, 'GET', '/employees?per_page=50');
    const employee = employees.data.data.find((e: { public_id: string }) => !taken.has(e.public_id));
    expect(employee, 'an employee with no active obligation').toBeTruthy();

    await page.getByRole('button', { name: /New Obligation/ }).first().click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Employee').click();
    await page.getByRole('option', { name: new RegExp(employee.name) }).first().click();
    await dialog.getByLabel('Total Obligation (ETB)').fill('1200');
    await dialog.getByLabel('Deduction Rate (%)').fill('10');
    await dialog.getByLabel('Started On').fill(new Date().toISOString().slice(0, 10));
    await dialog.getByRole('button', { name: 'Record Obligation' }).click();

    await expect(page.getByText('Cost-sharing obligation recorded')).toBeVisible();

    const row = page.locator('tr', { hasText: employee.name }).filter({
      has: page.getByRole('button', { name: 'Suspend' }),
    });
    await expect(row).toHaveCount(1);
    await row.getByRole('button', { name: 'Suspend' }).click();

    await expect(page.getByText('Status updated')).toBeVisible();
    await expect(
      page.locator('tr', { hasText: employee.name }).filter({ has: page.getByRole('button', { name: 'Suspend' }) }),
    ).toHaveCount(0);
  });
});
