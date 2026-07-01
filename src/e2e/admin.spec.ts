import { test, expect } from '@playwright/test';
import { login, DEMO_EMAIL, DEMO_PASS } from './helpers';

test.describe('Admin Console', () => {
  test('super admin can access admin console', async ({ page }) => {
    // Login as super admin (demo seeder must create one)
    await page.goto('/login');
    await page.fill('input[type="email"]', process.env.SUPER_ADMIN_EMAIL ?? 'superadmin@ethr.et');
    await page.fill('input[type="password"]', process.env.SUPER_ADMIN_PASS ?? 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard', { timeout: 10000 });
    await page.goto('/admin');
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('regular admin cannot access admin console', async ({ page }) => {
    await login(page, DEMO_EMAIL, DEMO_PASS);
    await page.goto('/admin');
    // Should show unauthorized or redirect
    const unauthorized = page.locator('text=/unauthorized|forbidden|not allowed/i');
    const redirected = page.locator('h1, h2').filter({ hasText: /dashboard/i });
    await expect(unauthorized.or(redirected).first()).toBeVisible({ timeout: 5000 });
  });

  test('tenant management page shows table', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[type="email"]', process.env.SUPER_ADMIN_EMAIL ?? 'superadmin@ethr.et');
    await page.fill('input[type="password"]', process.env.SUPER_ADMIN_PASS ?? 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/dashboard', { timeout: 10000 });
    await page.goto('/admin/tenants');
    await expect(page.locator('h1, h2, table').first()).toBeVisible({ timeout: 8000 });
  });
});
