import { test, expect } from '@playwright/test';
import { login, logout, DEMO_EMAIL, DEMO_PASS } from './helpers';

test.describe('Authentication', () => {
  test('login with valid credentials redirects to dashboard', async ({ page }) => {
    await login(page, DEMO_EMAIL, DEMO_PASS);
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('login with invalid credentials shows error', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[type="email"]', DEMO_EMAIL);
    await page.fill('input[type="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    await expect(page.locator('text=/invalid|incorrect|wrong/i').first()).toBeVisible({ timeout: 5000 });
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
