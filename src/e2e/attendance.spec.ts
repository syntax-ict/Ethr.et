import { test, expect } from '@playwright/test';
import { login, EMP_EMAIL, EMP_PASS } from './helpers';

test.describe('Attendance — Employee Self-Service', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, EMP_EMAIL, EMP_PASS);
  });

  test('employee can view attendance page', async ({ page }) => {
    await page.goto('/attendance/my');
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('attendance page shows check-in button or current status', async ({ page }) => {
    await page.goto('/attendance/my');
    const checkIn = page.locator('button:has-text("Check In"), button:has-text("Check-In")');
    const status = page.locator('text=/checked in|checked out|present/i');
    await expect(checkIn.or(status).first()).toBeVisible({ timeout: 8000 });
  });
});
