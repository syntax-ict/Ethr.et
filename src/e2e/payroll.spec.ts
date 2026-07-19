import { test, expect } from '@playwright/test';

test.describe('Payroll', () => {
  test.use({ storageState: 'e2e/.auth/admin.json' });

  test('payroll runs page loads', async ({ page }) => {
    await page.goto('/payroll');
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('process payroll button is visible to admin', async ({ page }) => {
    await page.goto('/payroll');
    await expect(page.locator('button:has-text("Process"), button:has-text("Run Payroll")').first()).toBeVisible({ timeout: 8000 });
  });
});
