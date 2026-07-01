import { test, expect } from '@playwright/test';
import { login, EMP_EMAIL, EMP_PASS, DEMO_EMAIL, DEMO_PASS } from './helpers';

test.describe('Leave Management', () => {
  test('employee can view leave balance page', async ({ page }) => {
    await login(page, EMP_EMAIL, EMP_PASS);
    await page.goto('/leave/my');
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('employee can see leave request button', async ({ page }) => {
    await login(page, EMP_EMAIL, EMP_PASS);
    await page.goto('/leave/my');
    await expect(page.locator('button:has-text("Apply"), button:has-text("Request Leave")').first()).toBeVisible({ timeout: 8000 });
  });

  test('admin can view team leave', async ({ page }) => {
    await login(page, DEMO_EMAIL, DEMO_PASS);
    await page.goto('/leave');
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });
});
