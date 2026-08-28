import { test, expect } from '@playwright/test';
import { login, logout, DEMO_EMAIL, DEMO_PASS, DEMO_TENANT } from './helpers';

test.describe('Authentication', () => {
  test('login with valid credentials redirects to dashboard', async ({ page }) => {
    await login(page, DEMO_EMAIL, DEMO_PASS);
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('login with invalid credentials shows error', async ({ page }) => {
    await page.goto('/login');
    const tenantField = page.locator('#tenant');
    if (await tenantField.isVisible().catch(() => false)) {
      await tenantField.fill(DEMO_TENANT);
    }
    await page.fill('input[type="email"]', DEMO_EMAIL);
    await page.fill('input[type="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    // Locale-agnostic: the app defaults to Amharic, so assert the error summary
    // (role="alert") appears rather than matching English wording, and that the
    // bad credentials did not authenticate.
    await expect(page.locator('[role="alert"]').first()).toBeVisible({ timeout: 5000 });
    await expect(page).toHaveURL(/\/login/);
  });

  test('logout clears session and redirects to login', async ({ page }) => {
    await login(page, DEMO_EMAIL, DEMO_PASS);
    await logout(page);
    await expect(page).toHaveURL(/\/login/);
  });

  test('unauthenticated user redirected from dashboard to login', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login/, { timeout: 5000 });
  });
});
