import { Page } from '@playwright/test';

export const BASE = process.env.BASE_URL ?? 'http://demo.localhost:3000';
export const API  = process.env.API_URL  ?? 'http://demo.localhost:8000/api/v1';

export const DEMO_EMAIL  = process.env.DEMO_EMAIL  ?? 'admin@demo.ethr.et';
export const DEMO_PASS   = process.env.DEMO_PASS   ?? 'password';
export const HR_EMAIL    = process.env.HR_EMAIL    ?? 'hr@demo.ethr.et';
export const HR_PASS     = process.env.HR_PASS     ?? 'password';
export const EMP_EMAIL   = process.env.EMP_EMAIL   ?? 'emp@demo.ethr.et';
export const EMP_PASS    = process.env.EMP_PASS    ?? 'password';

export async function login(page: Page, email: string, password: string) {
  await page.goto('/login');
  await page.fill('[data-testid="email-input"], input[type="email"], input[name="email"]', email);
  await page.fill('[data-testid="password-input"], input[type="password"], input[name="password"]', password);
  await page.click('[data-testid="login-button"], button[type="submit"]');
  await page.waitForURL('**/dashboard', { timeout: 10000 });
}

export async function logout(page: Page) {
  await page.goto('/');
  // Click logout via sidebar or header
  const logoutBtn = page.locator('button:has-text("Logout"), a:has-text("Logout"), button:has-text("Sign out")');
  if (await logoutBtn.isVisible()) {
    await logoutBtn.click();
  } else {
    await page.evaluate(() => {
      localStorage.clear();
      sessionStorage.clear();
    });
    await page.goto('/login');
  }
}
