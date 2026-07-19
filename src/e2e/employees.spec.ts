import { test, expect } from '@playwright/test';

test.describe('Employee Management', () => {
  test.use({ storageState: 'e2e/.auth/hr.json' });

  test('employee list loads with data', async ({ page }) => {
    await page.goto('/employees');
    await expect(page.locator('h1, h2').first()).toBeVisible();
    // Table or card list should have at least one row
    const rows = page.locator('table tbody tr, [data-testid="employee-card"]');
    await expect(rows.first()).toBeVisible({ timeout: 10000 });
  });

  test('employee search filters results', async ({ page }) => {
    await page.goto('/employees');
    const search = page.locator('input[placeholder*="Search"], input[type="search"]').first();
    await search.fill('test_nonexistent_xyz');
    await page.waitForTimeout(500);
    await expect(page.locator('text=/no employees|no results|empty/i').first()).toBeVisible({ timeout: 5000 });
  });

  test('clicking employee opens detail page', async ({ page }) => {
    await page.goto('/employees');
    const firstRow = page.locator('table tbody tr, [data-testid="employee-card"]').first();
    await expect(firstRow).toBeVisible({ timeout: 10000 });
    // Rows aren't clickable themselves — navigation happens via the nested link.
    await firstRow.locator('a').first().click();
    await expect(page).toHaveURL(/\/employees\/.+/);
  });
});
