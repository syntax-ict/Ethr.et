/**
 * Theme switching — the interaction, not the rendering.
 *
 * Replaces `dark-mode.spec.ts`, which could not fail. Its theme-toggle
 * assertion was wrapped in `if ((await themeToggle.count()) > 0)`, so it passed
 * silently whenever the control was missing — the one condition it existed to
 * catch. It also asserted
 * `documentElement.getAttribute("data-theme") === "dark"`, but `providers.tsx`
 * configures next-themes with `attribute="class"`, so that branch could never
 * be true. Its remaining checks tolerated "up to 3" broken white blocks in dark
 * mode and asserted only that `body.textContent.length > 10` for Amharic.
 *
 * Rendering in all three themes is covered properly by `ux-audit.spec.ts`
 * (axe at WCAG AA with `color-contrast` enabled, per route, per breakpoint),
 * and Amharic layout by `amharic-layout.spec.ts`. What neither covers is the
 * *act of switching* — the menu, the class swap, and persistence across a
 * reload. That is what this file tests, with assertions that can fail.
 */
import { test, expect } from '@playwright/test';

const THEMES = [
  { id: 'light', label: 'Light', expectedClass: 'light' },
  { id: 'dark', label: 'Dark', expectedClass: 'dark' },
  { id: 'high-contrast', label: 'High Contrast', expectedClass: 'high-contrast' },
] as const;

test.describe('Theme switching', () => {
  test.use({ storageState: 'e2e/.auth/admin.json' });

  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('locale', 'en'));
  });

  test('the theme control is present and reachable', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    // Unconditional: a missing control is a failure, not a skip.
    const toggle = page.getByRole('button', { name: /toggle theme/i });
    await expect(toggle).toBeVisible();
  });

  for (const theme of THEMES) {
    test(`switching to ${theme.id} applies it and persists`, async ({
      page,
    }) => {
      await page.goto('/dashboard');
      await page.waitForLoadState('networkidle');

      await page.getByRole('button', { name: /toggle theme/i }).click();
      await page.getByRole('menuitem', { name: theme.label }).click();

      // next-themes is configured with `attribute="class"`, so the class list
      // on <html> is the thing to assert — not `data-theme`.
      await expect(page.locator('html')).toHaveClass(
        new RegExp(`\\b${theme.expectedClass}\\b`),
      );

      // A theme that resets on navigation is not a preference.
      await page.reload();
      await page.waitForLoadState('networkidle');
      await expect(page.locator('html')).toHaveClass(
        new RegExp(`\\b${theme.expectedClass}\\b`),
      );
    });
  }

  test('dark mode actually repaints the page surface', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('theme', 'dark'));
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    const bg = await page.evaluate(
      () => getComputedStyle(document.body).backgroundColor,
    );

    // Parse rather than string-compare: the exact token value may change, but
    // a *dark* theme whose body is light is broken regardless of the hex.
    const [r, g, b] = bg.match(/\d+/g)!.map(Number);
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

    expect(luminance, `body background ${bg} is not dark`).toBeLessThan(0.3);
  });
});
