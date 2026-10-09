import { chromium, Page } from '@playwright/test';
import crypto from 'crypto';
import fs from 'fs';
import { fileURLToPath } from 'url';

export const BASE = process.env.BASE_URL ?? 'http://demo.localhost:3000';
export const API  = process.env.API_URL  ?? 'http://demo.localhost:8000/api/v1';

/**
 * The origin that serves the platform admin console.
 *
 * Under subdomain tenancy the console is not a path on the tenant host, it is a
 * different host, and three independent mechanisms enforce that:
 *
 *   - `src/middleware.ts` redirects `/admin` off any non-platform host,
 *   - `EnsurePlatformContext` 404s `/api/v1/admin/*` whenever a tenant resolved,
 *   - the session cookie is host-only (`SESSION_DOMAIN` is deliberately empty —
 *     it is what stops one tenant's cookie reaching another tenant's host), so a
 *     session minted on `demo.ethr.test` does not travel to `admin.ethr.test`.
 *
 * Admin specs therefore need their own origin *and* their own storage state;
 * reusing the tenant session against a relative `/admin` lands unauthenticated.
 *
 * Defaults to BASE, which is correct for the single-host model (`BASE_URL` of
 * `demo.localhost:3000` with `NEXT_PUBLIC_ROOT_DOMAIN` unset): there the
 * middleware is inert and the console is served from the tenant origin.
 * Set `PLATFORM_BASE_URL` when the console has its own host — against the
 * local shared-hosting server that is `http://admin.localhost:8081`
 * (scripts/local-production/).
 */
export const PLATFORM_BASE = process.env.PLATFORM_BASE_URL ?? BASE;

/** True when the console lives on a different origin than the tenant app. */
export const PLATFORM_HOST_IS_SEPARATE = PLATFORM_BASE !== BASE;

export const DEMO_EMAIL  = process.env.DEMO_EMAIL  ?? 'admin@demo.ethr.et';
export const DEMO_PASS   = process.env.DEMO_PASS   ?? 'password';
export const HR_EMAIL    = process.env.HR_EMAIL    ?? 'hr@demo.ethr.et';
export const HR_PASS     = process.env.HR_PASS     ?? 'password';
export const EMP_EMAIL   = process.env.EMP_EMAIL   ?? 'emp@demo.ethr.et';
export const EMP_PASS    = process.env.EMP_PASS    ?? 'password';

export const DEMO_TENANT = process.env.DEMO_TENANT ?? 'demo';

/**
 * Platform super admin. Has no tenant by design (CLAUDE.md's global model list),
 * so `LoginRequest::authenticate()` matches it *before* tenant resolution and it
 * can sign in from any host — but only the platform host will then serve it a
 * console.
 *
 * The default password is the dev seed's (`DatabaseSeeder`). An account made
 * with `ethr:create-admin` instead must satisfy its `min:12` rule, which the dev
 * default does not — set SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASS to match it. (The
 * Dockerised E2E harness did exactly that until it was removed on 2026-09-30.)
 */
export const SUPER_ADMIN_EMAIL = process.env.SUPER_ADMIN_EMAIL ?? 'superadmin@ethr.et';
export const SUPER_ADMIN_PASS  = process.env.SUPER_ADMIN_PASS  ?? 'password';

/**
 * Storage state written by global-setup for the super admin, if it could sign in.
 *
 * Absolute, unlike the tenant-role paths the other specs pass as bare relative
 * strings: the admin specs also have to *test* for its existence to decide
 * whether to skip, and a relative path would make the existence check and the
 * `storageState` load resolve against different roots if the suite is ever run
 * from anywhere but `src/`.
 */
export const SUPER_ADMIN_STATE = fileURLToPath(
  new URL('./.auth/superadmin.json', import.meta.url),
);

/**
 * Access tokens live 15 minutes (`AuthService::SESSION_MINUTES`), refreshed by
 * activity. A stored session is therefore only good for 15 idle minutes.
 *
 * That is fine for the tenant roles, which are in continuous use from the first
 * spec onward, but not for the super admin: global-setup mints it at t=0 and
 * the admin specs do not run until phase 8, ~36 minutes into a full suite. The
 * session is guaranteed dead by then — `/auth/me` and `/auth/refresh` both 401,
 * because the token authenticating the refresh has itself expired — so the
 * admin specs passed standalone and failed in every full run.
 *
 * Re-minted on demand instead, and only when actually stale: 12 of the 13
 * consumers find a fresh file and skip the login, which keeps this well inside
 * the 5/min/IP limit on `POST /auth/login` (CLAUDE.md's rate-limit policy).
 */
const SUPER_ADMIN_STALE_AFTER_MS = 10 * 60 * 1000;

/** Sign the super admin in on the platform host and persist the session. */
export async function mintSuperAdminSession(): Promise<boolean> {
  const browser = await chromium.launch({ channel: process.env.PW_CHANNEL || undefined });

  try {
    const page = await browser.newPage({ baseURL: PLATFORM_BASE });

    await page.goto('/login');
    await page.waitForLoadState('networkidle').catch(() => {});

    // The organisation field is deliberately left empty: on a non-tenant host
    // the form does not submit a typed subdomain, it *routes* to that tenant's
    // own login host (`tenantHostUrl()`, because the session cookie is
    // host-only), which would capture a tenant session instead.
    await page.fill('input[type="email"]', SUPER_ADMIN_EMAIL);
    await page.fill('input[type="password"]', SUPER_ADMIN_PASS);
    await page.click('button[type="submit"]');

    // `/admin` under the hostname model, `/dashboard` in single-host dev where
    // the middleware is inert. The form pushes `/dashboard` either way.
    await page.waitForURL(/\/(admin|dashboard)(\/|\?|$)/, {
      timeout: Number(process.env.E2E_LOGIN_TIMEOUT ?? 45000),
    });

    await page.evaluate(() => localStorage.setItem('locale', 'en'));
    await page.context().storageState({ path: SUPER_ADMIN_STATE });

    return true;
  } catch (error) {
    console.warn(
      `\n[e2e] Super admin sign-in FAILED at ${PLATFORM_BASE} as ${SUPER_ADMIN_EMAIL}.\n` +
        `[e2e] Admin console specs will be SKIPPED, not silently passed.\n` +
        `[e2e] Check the account is seeded and PLATFORM_BASE_URL is the console host.\n` +
        `[e2e] ${String(error)}\n`,
    );

    return false;
  } finally {
    await browser.close();
  }
}

/**
 * Guarantee a usable super-admin session, re-minting only a stale one.
 * Returns false when the account cannot sign in at all, so specs skip loudly.
 */
export async function ensureFreshSuperAdminSession(): Promise<boolean> {
  try {
    const age = Date.now() - fs.statSync(SUPER_ADMIN_STATE).mtimeMs;
    if (age < SUPER_ADMIN_STALE_AFTER_MS) return true;
  } catch {
    // Missing file — global-setup could not sign in, or this is a direct run.
    // Fall through and try, so a single-spec invocation still works.
  }

  return mintSuperAdminSession();
}

export async function login(page: Page, email: string, password: string) {
  await page.goto('/login');
  // The subdomain field only renders when the host itself doesn't already
  // resolve to a tenant (e.g. demo.localhost, used by this test suite).
  //
  // Wait for hydration before deciding whether that field is real, and probe with
  // isEditable() rather than isVisible() — the same fix global-setup.ts carries,
  // for the same reason. On an authoritative host the field is server-rendered and
  // then hidden by React, so a bare isVisible() races the hydration boundary.
  // Chromium happened to win that race and WebKit lost it: the mobile project
  // submitted with the org field empty, the app correctly answered "Organization
  // is required", and the failure surfaced here as an unexplained navigation
  // timeout. isEditable() is false for the hidden field, so this fills only under
  // the single-host (X-Tenant) model and cleanly skips it under subdomain routing.
  await page.waitForLoadState('networkidle').catch(() => {});
  const tenantField = page.locator('#tenant');
  if (await tenantField.isEditable().catch(() => false)) {
    await tenantField.fill(DEMO_TENANT);
  }
  await page.fill('[data-testid="email-input"], input[type="email"], input[name="email"]', email);
  await page.fill('[data-testid="password-input"], input[type="password"], input[name="password"]', password);
  await page.click('[data-testid="login-button"], button[type="submit"]');
  // Shares E2E_LOGIN_TIMEOUT with global-setup: the API cold-starts ~17-18s on a
  // first hit, which a fixed 10s budget cannot absorb.
  await page.waitForURL('**/dashboard', {
    timeout: Number(process.env.E2E_LOGIN_TIMEOUT ?? 45000),
  });
}

/**
 * Sign out through the app's own control. It is a menu item whose label is
 * translated ("Log Out", or "ውጣ" in the default Amharic) inside the account
 * menu, whose trigger shows the user's name — so it is found by its icon, not
 * its words. The old helper searched for English "Logout", never found it,
 * and fell back to clearing storage on `/`, whose locale redirect destroyed the
 * page mid-script (failed on the production-shaped rehearsal, 2026-10-09).
 */
export async function logout(page: Page) {
  await page.goto('/dashboard');
  await page.waitForLoadState('networkidle').catch(() => {});
  const item = page.locator('[role="menuitem"]:has(svg.lucide-log-out)').first();
  for (const trigger of await page.locator('header [aria-haspopup="menu"]').all()) {
    await trigger.click();
    if (await item.isVisible().catch(() => false)) {
      await item.click();
      await page.waitForURL(/\/login/, { timeout: 15000 });
      return;
    }
    await page.keyboard.press('Escape');
  }
  throw new Error('No header menu offers a Log Out item');
}

/**
 * Call the API from inside the app page, as its own client does: same origin,
 * the session cookie, the XSRF header Sanctum checks on a stateful request,
 * and X-Tenant when the session names one. Specs use it to set up data the
 * UI under test then acts on. Navigate to an app page first, so the cookies
 * and localStorage it reads exist and the session has been refreshed.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any -- specs read whatever shape the endpoint returns
export async function api<T = any>(
  page: Page,
  method: string,
  path: string,
  body?: unknown,
): Promise<{ status: number; data: T }> {
  return page.evaluate(
    async ({ method, path, body }) => {
      const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      };
      const xsrf = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.split('=')[1];
      if (xsrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);
      const tenant = localStorage.getItem('tenant');
      if (tenant) headers['X-Tenant'] = tenant;

      const res = await fetch(`/api/v1${path}`, {
        method,
        headers,
        credentials: 'include',
        body: body === undefined ? undefined : JSON.stringify(body),
      });
      const text = await res.text();
      return { status: res.status, data: text ? JSON.parse(text) : null };
    },
    { method, path, body },
  );
}

/**
 * The current RFC 6238 code for a base32 secret — SHA-1, six digits, 30-second
 * steps, which is what Google2FA issues. Lets a spec enrol and sign in with
 * MFA for real instead of stopping at the code screen.
 */
export function totp(secretBase32: string, at = Date.now()): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = secretBase32
    .replace(/=+$/, '')
    .toUpperCase()
    .split('')
    .map((ch) => alphabet.indexOf(ch).toString(2).padStart(5, '0'))
    .join('');
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((b) => parseInt(b, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(at / 1000 / 30)));
  const hmac = crypto.createHmac('sha1', key).update(counter).digest();
  const offset = hmac[hmac.length - 1] & 0xf;
  return ((hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000).toString().padStart(6, '0');
}
