import { test, expect } from '@playwright/test';

test.describe('Leave Management', () => {
  test.describe('as employee', () => {
    test.use({ storageState: 'e2e/.auth/employee.json' });

    test('employee can view leave balance page', async ({ page }) => {
      await page.goto('/leave');
      await expect(page.locator('h1, h2').first()).toBeVisible();
    });

    test('employee can see leave request button', async ({ page }) => {
      await page.goto('/leave');
      await expect(page.locator('button:has-text("Apply"), button:has-text("Request Leave")').first()).toBeVisible({ timeout: 8000 });
    });
  });

  test.describe('as admin', () => {
    test.use({ storageState: 'e2e/.auth/admin.json' });

    test('admin can view team leave', async ({ page }) => {
      await page.goto('/leave');
      await expect(page.locator('h1, h2').first()).toBeVisible();
    });
  });
});
