import { test, expect, Page } from '@playwright/test';
import { DEMO_TENANT, EMP_EMAIL } from './helpers';

/**
 * The sign-in screens around the password form: find my organisation, forgot
 * and reset password, SMS codes, the SSO return and the MFA guard. No spec
 * reached any of them until 2026-10-09 (audit R11).
 *
 * Each signs nobody in. The screens a real secret would complete — a reset
 * token, an SMS code — are driven to the point the server refuses, which is
 * where their own logic lives; the MFA journey that does complete is in
 * mfa.spec.ts.
 */

// Signed-out pages default to Amharic; the assertions read English.
test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('locale', 'en'));
});

/** Fill the organisation field where this host shows it (apex, single-host). */
async function fillTenantIfAsked(page: Page) {
  await page.waitForLoadState('networkidle').catch(() => {});
  const field = page.locator('#tenant');
  if (await field.isEditable().catch(() => false)) {
    await field.fill(DEMO_TENANT);
  }
}

test.describe('Sign-in flows', () => {
  test('find my organisation answers the same for any address', async ({ page }) => {
    await page.goto('/login/find');

    await page.fill('#email', `e2e-find-${Date.now()}@example.com`);
    await page.getByRole('button', { name: 'Email me the link' }).click();

    await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
    await expect(page.getByRole('link', { name: /Back to sign in/ })).toHaveAttribute('href', '/login');
  });

  test('forgot password sends a reset link without saying whether the account exists', async ({ page }) => {
    await page.goto('/login/forgot');
    await fillTenantIfAsked(page);

    await page.fill('#email', `e2e-forgot-${Date.now()}@example.com`);
    await page.getByRole('button', { name: 'Send reset link' }).click();

    await expect(page.getByRole('heading', { name: 'Check your email' })).toBeVisible();
    await expect(page.getByText('The link expires in 60 minutes.')).toBeVisible();
  });

  test('a reset link missing its token is refused, and points to a new one', async ({ page }) => {
    await page.goto('/login/reset');

    await expect(page.getByRole('heading', { name: 'Invalid reset link' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Request new link' })).toHaveAttribute('href', '/login/forgot');
  });

  test('a forged reset token changes nothing and says so', async ({ page }) => {
    await page.goto(
      `/login/reset?token=${'0'.repeat(64)}&email=${encodeURIComponent(EMP_EMAIL)}&tenant=${DEMO_TENANT}`,
    );
    await expect(page.getByRole('heading', { name: 'Set a new password' })).toBeVisible();

    const password = 'Correct-Horse-Battery-9';
    await page.getByLabel('New password').fill(password);
    await page.getByLabel('Confirm password').fill(password);
    await page.getByRole('button', { name: 'Reset password' }).click();

    // The server's own refusal, not a CSRF 419: until 2026-10-09 this page
    // never fetched the CSRF cookie, so every real reset and every account
    // activation opened from an email failed here with "Reset failed."
    await expect(page.getByText('This reset link is invalid or has expired.')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Password reset' })).toHaveCount(0);
    await expect(page).toHaveURL(/\/login\/reset/);
  });

  test('SMS sign-in says plainly when the organisation has no SMS delivery', async ({ page }) => {
    // Both the CI stack and the rehearsal run the `log` SMS driver, which
    // reports itself undeliverable — so the request is refused with a 503
    // and the screen must offer the password route instead of a dead code box.
    await page.goto('/login/otp');
    await fillTenantIfAsked(page);

    await page.getByPlaceholder('9XXXXXXXX').fill('911223344');
    await page.getByRole('button', { name: 'Send code' }).click();

    await expect(page.getByRole('heading', { name: "SMS sign-in isn't available" })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to password sign-in' }).first()).toHaveAttribute('href', '/login');
  });

  test('SMS sign-in refuses a phone number that is not Ethiopian before asking the server', async ({ page }) => {
    let asked = false;
    page.on('request', (r) => {
      if (r.url().includes('/auth/otp')) asked = true;
    });
    await page.goto('/login/otp');
    await fillTenantIfAsked(page);

    await page.getByPlaceholder('9XXXXXXXX').fill('123');
    await page.getByRole('button', { name: 'Send code' }).click();

    await expect(page.getByText(/valid Ethiopian phone number/)).toBeVisible();
    expect(asked).toBe(false);
  });

  test('an SSO return carrying an error explains it instead of signing in', async ({ page }) => {
    await page.goto(`/login/sso?org=${DEMO_TENANT}&error=no_account`);

    await expect(page.getByRole('heading', { name: 'Single sign-on did not complete' })).toBeVisible();
    // Next.js keeps an empty role="alert" route announcer on every page.
    await expect(page.getByRole('alert').filter({ hasText: /no ETHR account for you in this organisation/ })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to sign in' })).toHaveAttribute('href', '/login');
    await expect(page).not.toHaveURL(/\/dashboard/);
  });

  test('an SSO return naming no valid organisation is treated as a failure', async ({ page }) => {
    await page.goto('/login/sso?org=..%2Fevil');

    await expect(page.getByRole('alert').filter({ hasText: /did not confirm who you are/ })).toBeVisible();
    await expect(page).not.toHaveURL(/\/dashboard/);
  });

  test('the MFA code screen is not reachable without a password step first', async ({ page }) => {
    await page.goto('/login/mfa');

    await expect(page).toHaveURL(/\/login(\?|$)/, { timeout: 10000 });
  });
});
