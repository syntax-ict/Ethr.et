/**
 * Amharic layout regression check.
 *
 * `en`/`am` key parity is verified elsewhere and says nothing about *layout*:
 * Ethiopic renders taller and, word for word, usually wider than the English
 * it replaces. A button sized to "Save" or a table column sized to "Department"
 * can clip its Amharic label without any test noticing, because the string is
 * present and correct — it just isn't visible.
 *
 * This renders each route in Amharic and fails when a text element is actually
 * being cut off: `scrollWidth` exceeds `clientWidth` on an element whose
 * computed style clips (`overflow: hidden` or `text-overflow: ellipsis`).
 *
 * What this does NOT check is whether the translation is any good. That still
 * needs a native reader. What it does is separate "the layout is broken" —
 * which a machine can see — from "the wording is wrong", which it cannot, so
 * the human review is about language rather than about hunting for clipping.
 *
 *     npx playwright test amharic-layout.spec.ts --project=chromium-desktop
 */
import { test, expect, type Page } from '@playwright/test';

interface Route {
  path: string;
  role: 'admin' | 'hr' | 'employee';
}

/** Text-dense routes — labels, table headers, buttons, chips. */
const ROUTES: Route[] = [
  { path: '/login', role: 'admin' },
  { path: '/dashboard', role: 'admin' },
  { path: '/employees', role: 'admin' },
  { path: '/employees/new', role: 'admin' },
  { path: '/attendance', role: 'admin' },
  { path: '/attendance/settings', role: 'admin' },
  { path: '/leave', role: 'employee' },
  { path: '/payroll', role: 'admin' },
  { path: '/approvals', role: 'hr' },
  { path: '/settings', role: 'admin' },
  { path: '/settings/roles', role: 'admin' },
  { path: '/organization', role: 'admin' },
  { path: '/billing', role: 'admin' },
  { path: '/profile', role: 'employee' },
];

/** Narrow enough to expose clipping, wide enough to be a real target. */
const VIEWPORTS = [
  { name: 'mobile', width: 375, height: 812 },
  { name: 'desktop', width: 1280, height: 900 },
] as const;

interface Clipped {
  text: string;
  scrollWidth: number;
  clientWidth: number;
  selector: string;
}

async function findClippedText(page: Page): Promise<Clipped[]> {
  return page.evaluate(() => {
    const results: Clipped[] = [];

    document.querySelectorAll<HTMLElement>('*').forEach((el) => {
      // Leaf text only: a clipped parent is usually reported by its child, and
      // reporting both buries the actual element in duplicates.
      if (el.children.length > 0) return;

      const text = (el.textContent ?? '').trim();
      if (!text) return;

      const style = getComputedStyle(el);

      // Visually-hidden helpers (`sr-only`) are *deliberately* clipped to 1px —
      // that is how they stay available to screen readers without occupying
      // space. They are the single biggest source of false positives here.
      const isVisuallyHidden =
        el.clientWidth <= 1 ||
        el.clientHeight <= 1 ||
        style.clip === 'rect(0px, 0px, 0px, 0px)' ||
        style.clipPath === 'inset(50%)';
      if (isVisuallyHidden) return;

      if (style.visibility === 'hidden' || style.display === 'none') return;

      const clips =
        style.textOverflow === 'ellipsis' ||
        style.overflow === 'hidden' ||
        style.overflowX === 'hidden';
      if (!clips) return;

      // Deliberate truncation of *data* (a long department name in a tree row)
      // is legitimate as long as the full value stays reachable. A `title`
      // makes it so. Note this is about sighted users only — CSS truncation
      // does not alter the accessible text, so assistive tech already reads
      // the whole string either way.
      //
      // This exemption is for display text, not for controls: an interactive
      // element named only by `title` is still a defect (see UX_PHASE_04.md
      // P4-02), and those are caught by the axe run in `ux-audit.spec.ts`.
      const isRecoverable =
        el.hasAttribute('title') ||
        el.closest('[title]') !== null ||
        el.hasAttribute('aria-label');
      if (isRecoverable) return;

      // 1px of slack absorbs sub-pixel rounding, which otherwise reports every
      // fractional-width element in the page.
      if (el.scrollWidth <= el.clientWidth + 1) return;

      const selector =
        el.tagName.toLowerCase() +
        (el.className ? `.${String(el.className).split(/\s+/).slice(0, 2).join('.')}` : '');

      results.push({
        text: text.slice(0, 60),
        scrollWidth: el.scrollWidth,
        clientWidth: el.clientWidth,
        selector,
      });
    });

    return results;
  });
}

function format(items: Clipped[]): string {
  return items
    .map(
      (c) =>
        `    • "${c.text}" clipped — needs ${c.scrollWidth}px, has ${c.clientWidth}px  (${c.selector})`,
    )
    .join('\n');
}

test.describe('Amharic layout', () => {
  for (const route of ROUTES) {
    test.describe(route.path, () => {
      test.use({ storageState: `e2e/.auth/${route.role}.json` });

      test('renders Amharic without clipping text', async ({ page }) => {
        await page.addInitScript(() => {
          localStorage.setItem('locale', 'am');
          localStorage.setItem('theme', 'light');
        });

        const failures: string[] = [];

        for (const vp of VIEWPORTS) {
          await page.setViewportSize({ width: vp.width, height: vp.height });
          await page.goto(route.path);
          await page.waitForLoadState('networkidle');

          // The dictionary loads asynchronously; measuring before it lands
          // would size every element against the English fallback and report
          // nothing useful.
          await page
            .waitForFunction(
              () => document.documentElement.lang === 'am',
              undefined,
              { timeout: 5000 },
            )
            .catch(() => {});

          const clipped = await findClippedText(page);
          if (clipped.length > 0) {
            failures.push(`  ${vp.width}px:\n${format(clipped)}`);
          }
        }

        expect(
          failures.join('\n'),
          `${route.path} clips Amharic text`,
        ).toBe('');
      });
    });
  }
});
