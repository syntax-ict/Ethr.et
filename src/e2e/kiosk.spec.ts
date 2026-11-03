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
 *     The way to that screen is a three-second hold on the organisation name,
 *     not an Exit button anyone in the queue could tap.
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
      const exit = tablet.getByRole('button', { name: 'Press and hold for 3 seconds to exit kiosk mode' });
      await expect(exit).toBeVisible({ timeout: 15000 });

      // Out of full screen, a resting cover sits over the kiosk until tapped.
      const cover = tablet.getByRole('button', { name: 'Tap to start' });
      if (await cover.isVisible()) await cover.click();
      await expect(cover).toHaveCount(0);

      // A tap does nothing; only a held press reaches the admin PIN.
      await exit.click();
      await expect(tablet.getByText('Admin PIN Required')).toHaveCount(0);
      await exit.hover();
      await tablet.mouse.down();
      await tablet.waitForTimeout(3300);
      await tablet.mouse.up();

      // Any four digits used to be enough.
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
