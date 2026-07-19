import { test, expect } from '@playwright/test';

test.describe('Attendance — Employee Self-Service', () => {
  test.use({ storageState: 'e2e/.auth/employee.json' });

  test('employee can view attendance page', async ({ page }) => {
    await page.goto('/attendance');
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('attendance page shows check-in button or current status', async ({ page }) => {
    await page.goto('/attendance');
    const checkIn = page.locator('button:has-text("Check In"), button:has-text("Check-In")');
    const status = page.locator('text=/checked in|checked out|present/i');
    await expect(checkIn.or(status).first()).toBeVisible({ timeout: 8000 });
  });
});
