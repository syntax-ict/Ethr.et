import fs from 'fs';
import { test, expect } from '@playwright/test';
import {
  ensureFreshSuperAdminSession,
  PLATFORM_BASE,
  PLATFORM_HOST_IS_SEPARATE,
  SUPER_ADMIN_EMAIL,
  SUPER_ADMIN_STATE,
} from './helpers';

/**
 * The platform admin console is a *host*, not a path.
 *
 * These specs previously signed the super admin in on the tenant host and then
 * navigated to a relative `/admin`. That worked only under the single-host
 * model. Under production-like subdomain tenancy it cannot: `middleware.ts`
 * redirects `/admin` to `admin.<root>`, the session cookie is host-only so the
 * tenant session does not arrive with it, and `EnsurePlatformContext` 404s the
 * admin API for any request where a tenant resolved. Every assertion here
 * therefore runs against PLATFORM_BASE with a session minted on that host.
 *
 * The sign-in itself is done once in global-setup rather than per test, both to
 * stay under the 5/min/IP login limit and because the super-admin login differs
 * from the tenant one in ways worth encoding exactly once (see the notes there).
 */
const superAdminAvailable = fs.existsSync(SUPER_ADMIN_STATE);

test.describe('Admin console — platform host', () => {
  // Skipped, never silently passed, when global-setup could not sign the super
  // admin in: a green admin suite that never reached the console is precisely
  // the "reports success while doing nothing" failure this project keeps
  // finding. The warning naming the cause is printed by global-setup.
  test.skip(
    !superAdminAvailable,
    `no super-admin session (${SUPER_ADMIN_EMAIL} at ${PLATFORM_BASE}) — see the global-setup warning`,
  );

  // Access tokens live 15 minutes. global-setup mints this one at t=0 and a
  // full suite does not reach these specs until ~36 minutes in, so without a
  // re-mint they run against a dead session and fail on 401s — which is exactly
  // what a full run did while these same specs passed standalone.
  test.beforeAll(async () => {
    await ensureFreshSuperAdminSession();
  });

  test.use({ storageState: SUPER_ADMIN_STATE, baseURL: PLATFORM_BASE });

  test('super admin can access admin console', async ({ page }) => {
    await page.goto('/admin');

    // Assert we are actually on the console, not bounced to /login with a
    // heading that happens to be visible — the failure mode the old spec's
    // bare `h1, h2` check could not tell apart.
    await expect(page).toHaveURL(/\/admin(\/|\?|$)/);
    await expect(page.locator('h1, h2').first()).toBeVisible();
  });

  test('tenant management page shows table', async ({ page }) => {
    await page.goto('/admin/tenants');

    await expect(page).toHaveURL(/\/admin\/tenants/);
    await expect(page.locator('table, h1, h2').first()).toBeVisible({ timeout: 8000 });
  });
});

test.describe('Admin console — refused to a tenant admin', () => {
  // The tenant admin's storage state, deliberately pointed at the console host.
  test.use({ storageState: 'e2e/.auth/admin.json', baseURL: PLATFORM_BASE });

  test('regular admin cannot access admin console', async ({ page }) => {
    await page.goto('/admin');

    // Two legitimate shapes of refusal, depending on the hosting model, and the
    // test accepts either rather than asserting the one that happens to apply:
    //
    //   separate host  — the tenant cookie is host-only, so this arrives
    //                    unauthenticated and the app routes to /login;
    //   single host    — the session is valid but `RoleGate minRole="super_admin"`
    //                    renders Access Denied, or the user is sent to /dashboard.
    //
    // What must never happen is the console rendering, so that is asserted
    // negatively too.
    const refused = page.locator(
      'text=/unauthorized|forbidden|not allowed|access denied|don.t have permission|sign in|log in/i',
    );
    const bounced = page.locator('h1, h2').filter({ hasText: /dashboard/i });

    await expect(refused.or(bounced).first()).toBeVisible({ timeout: 8000 });

    if (PLATFORM_HOST_IS_SEPARATE) {
      // No tenant-admin session exists on this origin at all, so the console
      // must not be what rendered.
      await expect(page).not.toHaveURL(/\/admin\/tenants/);
    }
  });
});
