import { test, expect } from '@playwright/test';
import { api } from './helpers';

/**
 * An attendance conflict, from the clash to its resolution.
 *
 * Two manual entries for one employee on one day, three and a half hours
 * apart, are a clash the engine flags for review. That only works because a
 * manual entry is checked at the time entered — until 2026-10-09 it was
 * checked at the moment HR pressed Save, against today's punches, and a clash
 * on the entered day was never flagged at all.
 *
 * The day is a random one long past, so a rerun does not land on the same
 * records, and the seed's recent history is not in the way.
 */
test.describe('Attendance conflicts', () => {
  test.use({ storageState: 'e2e/.auth/admin.json' });

  test('a clash on one day is flagged, shown with its day, and resolved', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle').catch(() => {});

    // Anyone but the admin themself: one's own conflict is reviewed by someone else.
    const me = await api(page, 'GET', '/auth/me');
    const mine = me.data?.user?.employee_public_id ?? me.data?.employee_public_id ?? null;
    const list = await api(page, 'GET', '/employees?per_page=50');
    expect(list.status).toBe(200);
    // Someone still employed: the engine refuses punches from anyone who left.
    const left = ['resigned', 'terminated', 'retired'];
    const employee = list.data.data.find(
      (e: { public_id: string; status?: string }) => e.public_id !== mine && !left.includes(e.status ?? ''),
    );
    expect(employee, 'a current employee other than the admin').toBeTruthy();

    const daysAgo = 200 + Math.floor(Math.random() * 400);
    const day = new Date(Date.now() - daysAgo * 86_400_000).toISOString().slice(0, 10);

    for (const checkIn of ['07:13', '10:47']) {
      const entry = await api(page, 'POST', '/attendance/manual', {
        idempotency_key: `e2e-conflict-${day}-${checkIn}-${Date.now()}`,
        employee_public_id: employee.public_id,
        date: day,
        check_in: checkIn,
        reason: 'E2E conflict',
      });
      expect(entry.status, JSON.stringify(entry.data)).toBe(201);
    }

    await page.goto('/attendance/conflicts');
    const dayText = [
      new Date(day).toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' }),
      new Date(day).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }),
    ].join(' ');
    const card = page
      .locator('div')
      .filter({ hasText: employee.name })
      .filter({ hasText: dayText })
      .filter({ has: page.getByRole('button', { name: 'Review' }) })
      .last();
    await expect(card).toBeVisible({ timeout: 15000 });
    await expect(card.getByText('Distant sources')).toBeVisible();

    await card.getByRole('button', { name: 'Review' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByLabel('Decision').click();
    await page.getByRole('option', { name: 'Keep record A (void B)' }).click();
    await dialog.getByLabel(/Notes/).fill('E2E: the earlier punch is the real one');
    await dialog.getByRole('button', { name: 'Resolve conflict' }).click();

    await expect(page.getByText('Conflict resolved')).toBeVisible();
    // Gone from the pending list it was on.
    await expect(card).toHaveCount(0);
  });
});
