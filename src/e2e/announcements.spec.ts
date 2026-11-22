import { test, expect } from '@playwright/test';
import { api, DEMO_TENANT } from './helpers';

/**
 * Announcements, end to end (2026-11-22). Each journey creates its own story
 * through the API, asserts on what the screens read back, and deletes it. A
 * running stack is needed, as for every spec here.
 *
 * One announcement, one presentation: the same story says the same priority
 * word in the app's list, on the dashboard card and on the public news page.
 * They drew priority three different ways until this, so a story that read
 * "High" in the app read "Important" on the organisation's own website.
 */
const TITLE = `QA presentation ${Date.now()}`;

test.describe('Announcements: one presentation', () => {
  test.use({ storageState: 'e2e/.auth/hr.json' });

  let publicId = '';

  test.beforeEach(async ({ page }) => {
    await page.goto('/dashboard');
    const created = await api(page, 'POST', '/announcements', {
      title: TITLE,
      body: 'Plain words.\n\nA second paragraph.',
      priority: 'high',
      category: 'notice',
      show_on_landing: true,
      publish_now: true,
    });
    expect(created.status).toBe(201);
    publicId = (created.data.data ?? created.data).public_id;
  });

  test.afterEach(async ({ page }) => {
    if (publicId) await api(page, 'DELETE', `/announcements/${publicId}`);
    publicId = '';
  });

  test('says Important for a high priority story in the list, on the card and on /news', async ({ page, browser }) => {
    // The announcements page.
    await page.goto('/announcements');
    const row = page.getByRole('listitem').filter({ hasText: TITLE });
    await expect(row).toBeVisible({ timeout: 15000 });
    await expect(row.getByText('Important', { exact: true })).toBeVisible();
    // The server's excerpt, not a clamp of the raw body.
    await expect(row).toContainText('Plain words.');

    // The dashboard card.
    await page.goto('/dashboard');
    const card = page.getByRole('link').filter({ hasText: TITLE }).first();
    await expect(card).toBeVisible({ timeout: 15000 });
    await expect(card.getByText('Important', { exact: true })).toBeVisible();

    // The organisation's public news page, read signed out. On a single-host
    // development stack the organisation travels in `?org=`, as it does for
    // every other public-page spec; on an authoritative host it is the host.
    const anonymous = await browser.newContext();
    const news = await anonymous.newPage();
    await news.goto(`/news?org=${DEMO_TENANT}`);
    const story = news.getByRole('link').filter({ hasText: TITLE }).first();
    await expect(story).toBeVisible({ timeout: 15000 });
    await expect(story.getByText('Important', { exact: true })).toBeVisible();
    await anonymous.close();
  });
});
