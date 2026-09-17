/**
 * The public site, as an anonymous visitor.
 *
 * `ux-audit.spec.ts` already visits these pages, which is why this branch
 * withdrew finding F10 as false. But it visits them as a **logged-in admin**,
 * with the locale pinned to `en`, and only when `UX_PHASE` allows — so the one
 * state every real visitor arrives in was the one state nothing covered. A
 * public page that broke without an auth token, or rendered the wrong language
 * for someone with no stored preference, would have passed every existing spec.
 *
 * Deliberately no `storageState`: these tests run with no cookies, no
 * localStorage and no session. That is the point.
 *
 * Not wired into `gates.sh`: no Playwright spec is, and `gates.sh` does not
 * mention `e2e` anywhere. That is an observed fact about this repository rather
 * than a decision anyone wrote down, so this spec is run on demand:
 *
 *     npx playwright test marketing.spec.ts --project=chromium-desktop
 *
 * The properties it checks that Vitest cannot: that the routes actually resolve
 * over HTTP, that `<html lang>` survives hydration, and that switching language
 * navigates rather than silently rewriting the current page.
 */
import { test, expect } from '@playwright/test';

/** The seven public routes, in both languages, as they are actually served. */
const PATHS = ['', '/features', '/pricing', '/faq', '/contact', '/privacy', '/terms'];
const LOCALES = ['en', 'am'] as const;

// Anonymous means anonymous — override any storageState a project sets.
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('public site, anonymous visitor', () => {
  for (const locale of LOCALES) {
    for (const path of PATHS) {
      const url = `/${locale}${path}`;

      test(`${url} renders in ${locale} with no session`, async ({ page }) => {
        const response = await page.goto(url);
        expect(response?.status(), `${url} should serve a page`).toBeLessThan(400);

        // The defect this whole branch started from: the server emitted Amharic
        // inside <html lang="en"> for everyone. The attribute must name the
        // language the URL asked for, and must still name it after hydration.
        await expect(page.locator('html')).toHaveAttribute('lang', locale);

        // Exactly one main landmark. The landing page briefly had two, because
        // it carried its own chrome into a layout that already supplied some.
        await expect(page.locator('main')).toHaveCount(1);
        await expect(page.locator('header')).toHaveCount(1);
        await expect(page.locator('footer')).toHaveCount(1);

        await expect(page.locator('h1').first()).toBeVisible();
      });
    }
  }

  test('every in-site link resolves to a real route', async ({ page }) => {
    await page.goto('/en');

    // A dead link is a claim that a page exists. The footer had five.
    const hrefs = await page.$$eval('a[href]', (as) =>
      as.map((a) => a.getAttribute('href') ?? '').filter((h) => h.startsWith('/')),
    );
    expect(hrefs.length).toBeGreaterThan(0);
    expect(hrefs.filter((h) => h === '#' || h === '')).toHaveLength(0);

    for (const href of [...new Set(hrefs)]) {
      const response = await page.request.get(href);
      expect(response.status(), `${href} should not 404`).toBeLessThan(400);
    }
  });

  test('the unprefixed entry point sends a visitor into a language', async ({ page }) => {
    // No cookie and no localStorage, so this exercises the negotiated path
    // rather than a stored preference.
    await page.goto('/');
    await page.waitForURL(/\/(en|am)$/);

    const locale = new URL(page.url()).pathname.replace('/', '');
    await expect(page.locator('html')).toHaveAttribute('lang', locale);
  });

  test('the language switcher changes the URL, not just the text', async ({ page }) => {
    await page.goto('/en/pricing');

    await page.getByRole('button', { name: /change language/i }).first().click();
    await page.getByRole('menuitem', { name: /አማርኛ/ }).click();

    // Navigating rather than rewriting in place is what makes the choice
    // survive a reload and a shared link.
    await page.waitForURL(/\/am\/pricing$/);
    await expect(page.locator('html')).toHaveAttribute('lang', 'am');
  });

  test('publishes no claim an operator has not entered', async ({ page }) => {
    await page.goto('/en');

    const body = (await page.locator('body').innerText()).toLowerCase();

    // Every one of these shipped on the landing page as a literal. They are
    // database columns now, and the database is empty.
    for (const claim of ['abebe kebede', 'addis manufacturing', '99.9%', '500+', '50,000+']) {
      expect(body, `"${claim}" should not appear`).not.toContain(claim);
    }
  });
});
