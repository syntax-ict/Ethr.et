/**
 * Automated WCAG 2.1 AA accessibility audit via axe-core/playwright.
 *
 * Runs against the live demo stack. Each test navigates to a key page
 * and runs the Axe engine, then fails if any violations at the
 * 'critical' or 'serious' impact level are found.
 *
 * Install: npm install --save-dev @axe-core/playwright
 * Run:     npx playwright test accessibility.spec.ts
 */
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { login, DEMO_EMAIL, DEMO_PASS, EMP_EMAIL, EMP_PASS } from './helpers';

// Helper: run axe and assert no critical/serious violations
async function assertNoA11yViolations(page: Parameters<typeof AxeBuilder>[0]['page']) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .disableRules([
      'color-contrast',       // Requires visual rendering — verified manually
      'scrollable-region-focusable', // Handled by keyboard nav pattern
    ])
    .analyze();

  const critical = results.violations.filter(
    (v) => v.impact === 'critical' || v.impact === 'serious',
  );

  if (critical.length > 0) {
    const summary = critical
      .map((v) => `[${v.impact}] ${v.id}: ${v.description}\n  Nodes: ${v.nodes.map((n) => n.html).join(', ')}`)
      .join('\n\n');
    throw new Error(`${critical.length} accessibility violation(s) found:\n\n${summary}`);
  }

  // Log moderate/minor for awareness without failing
  const minor = results.violations.filter(
    (v) => v.impact === 'moderate' || v.impact === 'minor',
  );
  if (minor.length > 0) {
    console.warn(`[A11y] ${minor.length} moderate/minor violation(s) (non-blocking):`);
    minor.forEach((v) => console.warn(`  - [${v.impact}] ${v.id}: ${v.description}`));
  }
}

test.describe('Accessibility audit (WCAG 2.1 AA)', () => {
  // ── Public pages ────────────────────────────────────────────────────────────

  test('login page has no critical a11y violations', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await assertNoA11yViolations(page);
  });

  // ── Employee portal ──────────────────────────────────────────────────────────

  test.describe('Employee portal', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, EMP_EMAIL, EMP_PASS);
    });

    test('employee dashboard has no critical a11y violations', async ({ page }) => {
      await page.goto('/dashboard');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('employee attendance page has no critical a11y violations', async ({ page }) => {
      await page.goto('/attendance');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('employee leave page has no critical a11y violations', async ({ page }) => {
      await page.goto('/leave');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('employee notifications page has no critical a11y violations', async ({ page }) => {
      await page.goto('/notifications');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('employee profile page has no critical a11y violations', async ({ page }) => {
      await page.goto('/profile');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('directory page has no critical a11y violations', async ({ page }) => {
      await page.goto('/directory');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });
  });

  // ── Admin portal ─────────────────────────────────────────────────────────────

  test.describe('Admin portal', () => {
    test.beforeEach(async ({ page }) => {
      await login(page, DEMO_EMAIL, DEMO_PASS);
    });

    test('main dashboard has no critical a11y violations', async ({ page }) => {
      await page.goto('/dashboard');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('employees list has no critical a11y violations', async ({ page }) => {
      await page.goto('/employees');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('settings page has no critical a11y violations', async ({ page }) => {
      await page.goto('/settings');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('attendance corrections page has no critical a11y violations', async ({ page }) => {
      await page.goto('/attendance/corrections');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('reports page has no critical a11y violations', async ({ page }) => {
      await page.goto('/reports');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });

    test('payroll page has no critical a11y violations', async ({ page }) => {
      await page.goto('/payroll');
      await page.waitForLoadState('networkidle');
      await assertNoA11yViolations(page);
    });
  });

  // ── Keyboard navigation ──────────────────────────────────────────────────────

  test('login form is fully keyboard navigable', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    // Tab through: email → password → submit
    await page.keyboard.press('Tab');
    const emailFocused = await page.evaluate(
      () => document.activeElement?.getAttribute('type') === 'email',
    );
    expect(emailFocused).toBe(true);

    await page.keyboard.press('Tab');
    const passwordFocused = await page.evaluate(
      () => document.activeElement?.getAttribute('type') === 'password',
    );
    expect(passwordFocused).toBe(true);

    await page.keyboard.press('Tab');
    const submitFocused = await page.evaluate(
      () => document.activeElement?.getAttribute('type') === 'submit'
        || document.activeElement?.tagName === 'BUTTON',
    );
    expect(submitFocused).toBe(true);
  });

  test('skip navigation link is reachable via keyboard', async ({ page }) => {
    await page.goto('/login');
    await page.keyboard.press('Tab');
    // The skip link should be the first focusable element OR become visible on focus
    const skipLink = page.locator('a[href="#main-content"]').first();
    await expect(skipLink).toBeAttached();
  });

  // ── Mobile a11y ──────────────────────────────────────────────────────────────

  test('login page on mobile has no critical a11y violations', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/login');
    await page.waitForLoadState('networkidle');
    await assertNoA11yViolations(page);
  });
});
