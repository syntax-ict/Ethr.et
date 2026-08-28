import { chromium, type FullConfig } from '@playwright/test';
import fs from 'fs/promises';
import path from 'path';
import { fileURLToPath } from 'url';
import {
  BASE,
  DEMO_EMAIL,
  DEMO_PASS,
  HR_EMAIL,
  HR_PASS,
  EMP_EMAIL,
  EMP_PASS,
  DEMO_TENANT,
  SUPER_ADMIN_STATE,
  mintSuperAdminSession,
} from './helpers';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const AUTH_DIR = path.join(__dirname, '.auth');

// Logs in once per role and persists storageState so individual spec files
// can reuse an authenticated session instead of hitting POST /auth/login
// (rate-limited to 5/min/IP per CLAUDE.md) once per test.
async function saveAuthState(baseURL: string, email: string, password: string, outFile: string) {
  const browser = await chromium.launch({ channel: process.env.PW_CHANNEL || undefined });
  const page = await browser.newPage({ baseURL });

  await page.goto('/login');
  // Let hydration settle before deciding whether the tenant field is real: on an
  // authoritative host (subdomain tenancy) the field is server-rendered then
  // hidden by React, so a bare isVisible() races and an optimistic fill() hangs.
  // isEditable() is false for the hidden field, so this fills only in the
  // single-host (X-Tenant) model and cleanly skips it under subdomain routing.
  await page.waitForLoadState('networkidle').catch(() => {});
  const tenantField = page.locator('#tenant');
  if (await tenantField.isEditable().catch(() => false)) {
    await tenantField.fill(DEMO_TENANT);
  }
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await page.click('button[type="submit"]');
  // The dev/container API cold-starts ~17-18s on first hit, which blew past a fixed 15s here
  // and left every authed spec silently redirected to /login. Tolerate cold starts (override
  // with E2E_LOGIN_TIMEOUT); warm the stack before running for the fastest first login.
  await page.waitForURL('**/dashboard', {
    timeout: Number(process.env.E2E_LOGIN_TIMEOUT ?? 45000),
  });

  // The app's default locale is Amharic (DEFAULT_LOCALE = 'am'). The critical-path
  // specs assert on English UI strings, so pin the reused session to English —
  // otherwise every authenticated assertion matches Amharic text and fails.
  await page.evaluate(() => localStorage.setItem('locale', 'en'));

  await page.context().storageState({ path: path.join(AUTH_DIR, outFile) });
  await browser.close();
}

export default async function globalSetup(config: FullConfig) {
  const baseURL = config.projects[0]?.use?.baseURL ?? BASE;

  await saveAuthState(baseURL, DEMO_EMAIL, DEMO_PASS, 'admin.json');
  await saveAuthState(baseURL, HR_EMAIL, HR_PASS, 'hr.json');
  await saveAuthState(baseURL, EMP_EMAIL, EMP_PASS, 'employee.json');

  // Stale state from a previous run would let the admin specs "pass" against an
  // expired cookie or the wrong host, so clear it before attempting a new one.
  await fs.rm(SUPER_ADMIN_STATE, { force: true });
  await mintSuperAdminSession();
}
