import { test, expect } from '@playwright/test';
import { login, DEMO_EMAIL, DEMO_PASS, HR_EMAIL, HR_PASS } from './helpers';

test.describe('Employee Management', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, HR_EMAIL, HR_PASS);
  });

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
    await firstRow.click();
    await expect(page).toHaveURL(/\/employees\/.+/);
  });
});
