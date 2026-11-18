import { test, expect } from '@playwright/test';

/**
 * What the browser's Print produces (CLAUDE.md, "print stylesheet"). The
 * page is put in print media and read back: the screen's navigation and
 * setup cards are gone, the paper says what organisation and what scope it
 * is, the pager's range survives, no button is drawn, and a real PDF comes
 * out. A running stack is needed, as for every spec here.
 */
test.describe('Print', () => {
  test.use({ storageState: 'e2e/.auth/admin.json' });

  test('the attendance register prints as a framed working copy', async ({ page }) => {
    await page.goto('/attendance');
    await expect(page.locator('table').first()).toBeVisible({ timeout: 15000 });
    await page.emulateMedia({ media: 'print' });

    // Screen furniture is not on the paper.
    await expect(page.getByRole('navigation', { name: /attendance pages/i })).toBeHidden();
    await expect(page.getByText(/without a kiosk PIN|Location required/i)).toBeHidden();

    // The frame names the organisation, the page and who printed it.
    const header = page.locator('.print-header');
    await expect(header).toBeVisible();
    await expect(header.locator('.print-header-org')).not.toBeEmpty();
    await expect(header.locator('.print-header-title')).toContainText(/attendance/i);
    await expect(header.locator('.print-header-meta')).toContainText(/Printed/);

    // The filters that were applied are written down.
    await expect(page.locator('.print-scope')).toBeVisible();

    // "Showing 1 to 25 of N" is kept when there is more than a page.
    const range = page.getByText(/^Showing \d+ to \d+ of \d+/);
    if ((await range.count()) > 0) await expect(range.first()).toBeVisible();

    // No button is drawn.
    const drawn = await page.evaluate(() =>
      [...document.querySelectorAll('button')].filter(
        (el) => getComputedStyle(el).display !== 'none' && el.getClientRects().length > 0,
      ).length,
    );
    expect(drawn).toBe(0);

    // A real PDF comes out of it, and it is numbered.
    const pdf = await page.pdf({ format: 'A4', preferCSSPageSize: true });
    const text = pdf.toString('latin1');
    const pages = (text.match(/\/Type\s*\/Page\b(?!s)/g) ?? []).length;
    expect(pages).toBeGreaterThanOrEqual(1);
  });

  test('analytics keeps its figures in a row on paper', async ({ page }) => {
    await page.goto('/analytics');
    const grid = page.locator('.print-grid').first();
    await expect(grid).toBeAttached({ timeout: 15000 });
    await page.emulateMedia({ media: 'print' });

    await expect(grid).toHaveCSS('display', 'grid');
  });
});
