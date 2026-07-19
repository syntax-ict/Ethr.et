import { chromium, type FullConfig } from '@playwright/test';
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
} from './helpers';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const AUTH_DIR = path.join(__dirname, '.auth');

// Logs in once per role and persists storageState so individual spec files
// can reuse an authenticated session instead of hitting POST /auth/login
// (rate-limited to 5/min/IP per CLAUDE.md) once per test.
async function saveAuthState(baseURL: string, email: string, password: string, outFile: string) {
  const browser = await chromium.launch();
  const page = await browser.newPage({ baseURL });

  await page.goto('/login');
  const tenantField = page.locator('#tenant');
  if (await tenantField.isVisible().catch(() => false)) {
    await tenantField.fill(DEMO_TENANT);
  }
  await page.fill('input[type="email"]', email);
  await page.fill('input[type="password"]', password);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/dashboard', { timeout: 15000 });

  await page.context().storageState({ path: path.join(AUTH_DIR, outFile) });
  await browser.close();
}

export default async function globalSetup(config: FullConfig) {
  const baseURL = config.projects[0]?.use?.baseURL ?? BASE;

  await saveAuthState(baseURL, DEMO_EMAIL, DEMO_PASS, 'admin.json');
  await saveAuthState(baseURL, HR_EMAIL, HR_PASS, 'hr.json');
  await saveAuthState(baseURL, EMP_EMAIL, EMP_PASS, 'employee.json');
}
