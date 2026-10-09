import { test, expect, Browser, Page } from '@playwright/test';
import { api, totp, DEMO_TENANT, HR_EMAIL, HR_PASS } from './helpers';

/**
 * Two-factor authentication, end to end, on the HR account:
 *
 *   1. HR enrols an authenticator (the code computed here from the secret).
 *   2. A fresh browser signs in as HR, is stopped at the code screen, enters
 *      the current code and lands on the dashboard.
 *   3. HR "loses the phone": the tenant admin resets it from Settings → Users,
 *      which did not exist until 2026-10-09 — there was no way back at all.
 *
 * The reset is also the cleanup. afterAll repeats it through the API if a step
 * failed, so a broken run never leaves HR locked behind a code the next spec
 * cannot produce.
 */

test.describe.configure({ mode: 'serial' });

async function adminPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext({ storageState: 'e2e/.auth/admin.json' });
  const page = await context.newPage();
  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle').catch(() => {});
  return page;
}

async function hrAccount(page: Page): Promise<{ public_id: string; mfa_enabled: boolean }> {
  const res = await api(page, 'GET', `/users?search=${encodeURIComponent(HR_EMAIL)}`);
  expect(res.status).toBe(200);
  const user = res.data.data.find((u: { email: string }) => u.email === HR_EMAIL);
  expect(user, `${HR_EMAIL} is listed in Settings → Users`).toBeTruthy();
  return user;
}

test.describe('Two-factor authentication', () => {
  test.afterAll(async ({ browser }) => {
    const page = await adminPage(browser);
    const hr = await hrAccount(page);
    if (hr.mfa_enabled) {
      await api(page, 'POST', `/users/${hr.public_id}/mfa/reset`);
    }
    await page.context().close();
  });

  let secret = '';

  test('HR enrols an authenticator', async ({ browser }) => {
    const context = await browser.newContext({ storageState: 'e2e/.auth/hr.json' });
    const page = await context.newPage();
    await page.goto('/profile/security');
    await page.waitForLoadState('networkidle').catch(() => {});

    const setup = await api(page, 'POST', '/auth/mfa/setup');
    expect(setup.status, JSON.stringify(setup.data)).toBe(200);
    secret = setup.data.secret;
    expect(secret).toMatch(/^[A-Z2-7]+=*$/);

    const enable = await api(page, 'POST', '/auth/mfa/enable', { secret, code: totp(secret) });
    expect(enable.status, JSON.stringify(enable.data)).toBe(200);

    await context.close();
  });

  test('signing in asks for the code, and the right code lets HR in', async ({ browser }) => {
    test.skip(!secret, 'enrolment did not complete');
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.addInitScript(() => localStorage.setItem('locale', 'en'));

    await page.goto('/login');
    await page.waitForLoadState('networkidle').catch(() => {});
    const tenantField = page.locator('#tenant');
    if (await tenantField.isEditable().catch(() => false)) {
      await tenantField.fill(DEMO_TENANT);
    }
    await page.fill('input[type="email"]', HR_EMAIL);
    await page.fill('input[type="password"]', HR_PASS);
    await page.click('button[type="submit"]');

    await page.waitForURL(/\/login\/mfa/, { timeout: Number(process.env.E2E_LOGIN_TIMEOUT ?? 45000) });
    await expect(page.getByRole('heading', { name: 'Two-factor authentication' })).toBeVisible();
    // The way back from a lost phone is an administrator now, not a recovery
    // page that never existed.
    await expect(page.getByRole('link', { name: 'Use a recovery code' })).toHaveCount(0);
    await expect(page.getByText(/Ask your administrator to reset two-factor authentication/)).toBeVisible();

    // A wrong code first: refused, and still on the code screen.
    await page.locator('input[inputmode="numeric"]').first().click();
    await page.keyboard.type(String((Number(totp(secret)) + 1) % 1_000_000).padStart(6, '0'));
    await page.getByRole('button', { name: 'Verify' }).click();
    await expect(page.locator('[role="alert"]')).toBeVisible();
    await expect(page).toHaveURL(/\/login\/mfa/);

    await page.locator('input[inputmode="numeric"]').first().click();
    await page.keyboard.type(totp(secret));
    await page.getByRole('button', { name: 'Verify' }).click();

    await page.waitForURL('**/dashboard', { timeout: 20000 });
    await context.close();
  });

  test('a tenant admin resets it for someone who lost their phone', async ({ browser }) => {
    test.skip(!secret, 'enrolment did not complete');
    const page = await adminPage(browser);

    await page.goto('/settings/users');
    await page.getByPlaceholder('Search by name or email').fill(HR_EMAIL);
    const row = page.locator('tr', { hasText: HR_EMAIL });
    await expect(row.getByText('2FA')).toBeVisible({ timeout: 10000 });

    await row.getByRole('button', { name: 'Reset two-factor authentication' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText(HR_EMAIL)).toBeVisible();
    await dialog.getByRole('button', { name: 'Reset' }).click();

    // The reset emails the person. With no SMTP host (the rehearsal) the send
    // waits out a ten-second connection timeout before the server gives up on
    // the email and completes the reset, so allow for it.
    await expect(page.getByText('Two-factor authentication reset')).toBeVisible({ timeout: 20000 });
    await expect(row.getByText('2FA')).toHaveCount(0);
    expect((await hrAccount(page)).mfa_enabled).toBe(false);

    await page.context().close();
  });
});
