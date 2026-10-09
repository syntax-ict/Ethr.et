import { test, expect } from '@playwright/test';
import { api } from './helpers';

/**
 * The shared kiosk, from a fresh tablet:
 *
 *   - activation: a tablet with no cookies at all posts the token. The server
 *     refuses a write without Sanctum's CSRF cookie (419); this proves the page
 *     arrives with one;
 *   - exit: the lock screen accepted any four digits until 2026-10-09 (audit
 *     R1). The admin PIN set at registration is now checked by the server.
 */
test.describe('Kiosk', () => {
  test('a fresh tablet activates, and leaves kiosk mode only with the admin PIN', async ({ browser }) => {
    // Register a kiosk as the tenant admin; its token is shown once, at creation.
    const admin = await (await browser.newContext({ storageState: 'e2e/.auth/admin.json' })).newPage();
    await admin.goto('/attendance/kiosks');
    await admin.waitForLoadState('networkidle').catch(() => {});
    const branches = await api(admin, 'GET', '/organization/branches?per_page=1');
    expect(branches.status).toBe(200);
    const pin = '4821';
    const created = await api(admin, 'POST', '/kiosk-sessions', {
      name: `E2E kiosk ${Date.now()}`,
      branch_public_id: branches.data.data[0].public_id,
      admin_pin: pin,
    });
    expect(created.status, JSON.stringify(created.data)).toBe(201);
    const token: string = created.data.token ?? created.data.data?.token;
    const kioskId: string = created.data.public_id ?? created.data.data?.public_id;
    expect(token).toBeTruthy();

    try {
      // A tablet with no cookies and nothing in storage.
      const tablet = await (await browser.newContext()).newPage();
      await tablet.addInitScript(() => localStorage.setItem('locale', 'en'));
      await tablet.goto('/kiosk');

      await tablet.getByPlaceholder('Paste kiosk token here').fill(token);
      await tablet.getByRole('button', { name: 'Activate Kiosk' }).click();
      await expect(tablet.getByRole('button', { name: 'Exit' })).toBeVisible({ timeout: 15000 });

      // Any four digits used to be enough.
      await tablet.getByRole('button', { name: 'Exit' }).click();
      await expect(tablet.getByText('Admin PIN Required')).toBeVisible();
      await tablet.getByPlaceholder('Admin PIN').fill('0000');
      await tablet.getByRole('button', { name: 'Exit Kiosk' }).click();
      await expect(tablet.getByText('Incorrect admin PIN.')).toBeVisible();
      await expect(tablet.getByText('Kiosk Setup')).toHaveCount(0);

      await tablet.getByPlaceholder('Admin PIN').fill(pin);
      await tablet.getByRole('button', { name: 'Exit Kiosk' }).click();
      await expect(tablet.getByText('Kiosk Setup')).toBeVisible();
      expect(await tablet.evaluate(() => localStorage.getItem('kiosk_token'))).toBeNull();

      await tablet.context().close();
    } finally {
      if (kioskId) await api(admin, 'DELETE', `/kiosk-sessions/${kioskId}`);
      await admin.context().close();
    }
  });
});
