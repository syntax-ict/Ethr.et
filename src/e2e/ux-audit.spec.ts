/**
 * Parameterised UX/UI audit harness.
 *
 * One spec drives every phase of the frontend production-readiness pass. For
 * each route in the phase under audit it:
 *
 *   1. renders at 375 / 768 / 1280 in light, dark and high-contrast,
 *   2. screenshots each combination into `test-results/ux-<phase>/`,
 *   3. runs axe-core at WCAG 2.1 AA **with `color-contrast` enabled**,
 *   4. asserts the page body does not scroll horizontally,
 *   5. asserts the browser console is free of errors.
 *
 * Select a phase with `UX_PHASE` (defaults to `all`):
 *
 *     UX_PHASE=0 npx playwright test ux-audit.spec.ts --project=chromium-desktop
 *
 * Screenshots are evidence for `docs/audits/UX_PHASE_XX.md`, not golden-image
 * assertions — there is no baseline to drift, so this never fails on a
 * legitimate design change. The assertions above are what gate the phase.
 */
import fs from 'fs';
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import {
  ensureFreshSuperAdminSession,
  PLATFORM_BASE,
  SUPER_ADMIN_STATE,
} from './helpers';

/**
 * `superadmin` is a host as much as a role. The `/admin*` routes are served
 * only from the platform origin (see admin.spec.ts), so auditing them as the
 * tenant `admin` against the tenant origin — which is what this harness did —
 * audited a redirect and an Access Denied screen, not the console.
 */
type Role = 'admin' | 'hr' | 'employee' | 'superadmin';

/** Written by global-setup only if the super admin could actually sign in. */
const superAdminAvailable = fs.existsSync(SUPER_ADMIN_STATE);

interface Route {
  path: string;
  name: string;
  role: Role;
  /**
   * Resolve a real record URL at run time for `[id]` routes.
   *
   * Detail pages are usually the largest and least-reviewed screens in a
   * module — `/employees/[id]` alone is 1,928 lines — so omitting them because
   * the URL needs an id would skip exactly the pages most likely to be
   * carrying defects. Instead: open `listPath`, take the first link matching
   * `linkPattern`, and audit that.
   */
  dynamic?: { listPath: string; linkPattern: RegExp };
}

/** Navigate to the list page and return the first matching detail href. */
async function resolveDynamicPath(
  page: Page,
  dynamic: NonNullable<Route['dynamic']>,
): Promise<string | null> {
  await page.goto(dynamic.listPath);
  await page.waitForLoadState('networkidle');

  const hrefs = await page.$$eval('a[href]', (as) =>
    as.map((a) => a.getAttribute('href') ?? ''),
  );
  return hrefs.find((h) => dynamic.linkPattern.test(h)) ?? null;
}

/** Route map mirrors the phase table in the production-readiness plan. */
const PHASES: Record<string, Route[]> = {
  '0': [
    { path: '/login', name: 'login', role: 'admin' },
    { path: '/dashboard', name: 'dashboard-shell', role: 'admin' },
    { path: '/profile', name: 'profile', role: 'employee' },
  ],
  '1': [
    // Locale-prefixed, because the unprefixed URLs are now redirectors that
    // negotiate a language and render nothing of their own — screenshotting one
    // captures a blank page. This suite pins `en` before first paint (see
    // `pinPreferences` below), so `/en/*` is the page it was always auditing.
    { path: '/en', name: 'landing', role: 'admin' },
    { path: '/en/pricing', name: 'pricing', role: 'admin' },
    { path: '/en/features', name: 'features', role: 'admin' },
    { path: '/en/faq', name: 'faq', role: 'admin' },
    { path: '/en/contact', name: 'contact', role: 'admin' },
    { path: '/register', name: 'register', role: 'admin' },
    { path: '/setup', name: 'setup', role: 'admin' },
    { path: '/setup/guided', name: 'setup-guided', role: 'admin' },
  ],
  '2': [
    { path: '/employees', name: 'employees-list', role: 'admin' },
    { path: '/employees/new', name: 'employees-new', role: 'admin' },
    { path: '/employees/import', name: 'employees-import', role: 'admin' },
    {
      path: '/employees',
      name: 'employee-detail',
      role: 'admin',
      dynamic: {
        listPath: '/employees',
        linkPattern: /^\/employees\/[A-Za-z0-9]{10,}$/,
      },
    },
    { path: '/organization', name: 'organization', role: 'admin' },
    { path: '/directory', name: 'directory', role: 'employee' },
  ],
  '3': [
    { path: '/attendance', name: 'attendance', role: 'admin' },
    { path: '/attendance/team', name: 'attendance-team', role: 'hr' },
    { path: '/attendance/corrections', name: 'attendance-corrections', role: 'admin' },
    { path: '/attendance/intelligence', name: 'attendance-intelligence', role: 'admin' },
    { path: '/attendance/overtime', name: 'attendance-overtime', role: 'admin' },
    { path: '/attendance/settings', name: 'attendance-settings', role: 'admin' },
    { path: '/attendance/import', name: 'attendance-import', role: 'admin' },
    { path: '/attendance/kiosks', name: 'attendance-kiosks', role: 'admin' },
    { path: '/attendance/mobile', name: 'attendance-mobile', role: 'employee' },
    { path: '/attendance/qr', name: 'attendance-qr', role: 'admin' },
    { path: '/attendance/scan', name: 'attendance-scan', role: 'employee' },
    { path: '/shifts', name: 'shifts', role: 'admin' },
    { path: '/shifts/roster', name: 'shifts-roster', role: 'admin' },
    { path: '/shifts/rotations', name: 'shifts-rotations', role: 'admin' },
    { path: '/shifts/assignments', name: 'shifts-assignments', role: 'admin' },
    { path: '/devices', name: 'devices', role: 'admin' },
    { path: '/devices/dashboard', name: 'devices-dashboard', role: 'admin' },
    {
      path: '/devices',
      name: 'device-detail',
      role: 'admin',
      dynamic: {
        listPath: '/devices',
        linkPattern: /^\/devices\/[A-Za-z0-9]{10,}$/,
      },
    },
  ],
  '4': [
    { path: '/leave', name: 'leave', role: 'employee' },
    { path: '/payroll', name: 'payroll', role: 'admin' },
    { path: '/payroll/payslips', name: 'payslips', role: 'employee' },
    { path: '/payroll/loans', name: 'loans', role: 'admin' },
    {
      path: '/payroll',
      name: 'payroll-run-detail',
      role: 'admin',
      dynamic: {
        listPath: '/payroll',
        linkPattern: /^\/payroll\/[A-Za-z0-9]{10,}$/,
      },
    },
  ],
  '5': [
    { path: '/approvals', name: 'approvals', role: 'hr' },
    { path: '/notifications', name: 'notifications', role: 'employee' },
    { path: '/notifications/preferences', name: 'notification-prefs', role: 'employee' },
    { path: '/announcements', name: 'announcements', role: 'admin' },
    { path: '/profile/security', name: 'profile-security', role: 'employee' },
    { path: '/profile/personal', name: 'profile-personal', role: 'employee' },
    { path: '/profile/preferences', name: 'profile-preferences', role: 'employee' },
    { path: '/profile/requests', name: 'profile-requests', role: 'employee' },
  ],
  '6': [
    { path: '/dashboard', name: 'dashboard', role: 'admin' },
    { path: '/analytics', name: 'analytics', role: 'admin' },
    { path: '/reports', name: 'reports', role: 'admin' },
  ],
  '7': [
    { path: '/settings/api-keys', name: 'api-keys', role: 'admin' },
    { path: '/settings/webhooks', name: 'webhooks', role: 'admin' },
    { path: '/settings/scim', name: 'scim', role: 'admin' },
    { path: '/settings/accounting', name: 'accounting', role: 'admin' },
    {
      path: '/settings/notification-templates',
      name: 'notification-templates',
      role: 'admin',
    },
    { path: '/settings', name: 'settings-index', role: 'admin' },
  ],
  '8': [
    { path: '/admin', name: 'admin-console', role: 'superadmin' },
    { path: '/admin/tenants', name: 'admin-tenants', role: 'superadmin' },
    { path: '/admin/audit', name: 'admin-audit', role: 'superadmin' },
    {
      path: '/admin/platform-settings',
      name: 'admin-platform-settings',
      role: 'superadmin',
    },
    { path: '/billing', name: 'billing', role: 'admin' },
    { path: '/settings/users', name: 'settings-users', role: 'admin' },
    { path: '/settings/roles', name: 'settings-roles', role: 'admin' },
    { path: '/settings/shifts', name: 'settings-shifts', role: 'admin' },
    { path: '/settings/holidays', name: 'settings-holidays', role: 'admin' },
    { path: '/settings/leave-types', name: 'settings-leave-types', role: 'admin' },
    { path: '/settings/payroll', name: 'settings-payroll', role: 'admin' },
    { path: '/settings/audit-logs', name: 'settings-audit-logs', role: 'admin' },
  ],
};

const VIEWPORTS = [
  { name: 'mobile', width: 375, height: 812 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1280, height: 900 },
] as const;

const THEMES = ['light', 'dark', 'high-contrast'] as const;

const selected = process.env.UX_PHASE ?? 'all';
const phases =
  selected === 'all' ? Object.keys(PHASES) : selected.split(',').map((s) => s.trim());

/** Pin theme and locale before first paint so no frame renders in the wrong one. */
async function applyPreferences(page: Page, theme: string) {
  await page.addInitScript(
    ([t]) => {
      localStorage.setItem('theme', t);
      localStorage.setItem('locale', 'en');
    },
    [theme],
  );
}

async function runAxe(page: Page) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    // `color-contrast` is deliberately NOT disabled here. The older
    // accessibility.spec.ts turned it off with a "verified manually" comment,
    // which made the suite silent on the single most common AA failure. Real
    // rendering is exactly what Playwright provides, so the rule can run.
    .disableRules(['scrollable-region-focusable'])
    .analyze();

  return results.violations.filter(
    (v) => v.impact === 'critical' || v.impact === 'serious',
  );
}

/**
 * A cancelled Next.js RSC prefetch, as WebKit words it.
 *
 * Next.js prefetches the RSC payload for every link in the viewport; navigating
 * away aborts whatever is still in flight. Chromium reports that as
 * `net::ERR_ABORTED` on the `requestfailed` event, which this suite has always
 * forgiven. WebKit instead raises a rejected fetch that surfaces on *two* other
 * sinks — `console` and `pageerror` — worded "Fetch API cannot load … due to
 * access control checks", which reads like a CORS defect and is not one.
 *
 * Deliberately requires `_rsc=` in the message: an unscoped match on the CORS
 * wording would swallow real cross-origin breakage on `/api/v1`, which is
 * precisely what this gate exists to catch — a malformed `allowed_origins_patterns`
 * once 500'd every browser login and only a console assertion noticed.
 */
function isCancelledPrefetchMessage(text: string): boolean {
  return text.includes('due to access control checks') && text.includes('_rsc=');
}

/**
 * Render violations with the offending element and axe's own diagnosis.
 *
 * A bare "color-contrast: Elements must meet minimum contrast" tells you a
 * page failed but not which element, which colours, or what ratio — so the
 * report is unactionable and gets ignored. `failureSummary` carries the
 * measured foreground/background and the required ratio.
 */
function formatViolations(
  violations: Awaited<ReturnType<typeof runAxe>>,
): string {
  return violations
    .map((v) => {
      const nodes = v.nodes
        .slice(0, 5)
        .map(
          (n) =>
            `    • ${n.target.join(' ')}\n` +
            `      ${n.html.slice(0, 160)}\n` +
            `      ${(n.failureSummary ?? '').replace(/\n/g, '\n      ')}`,
        )
        .join('\n');
      const more =
        v.nodes.length > 5 ? `\n    …and ${v.nodes.length - 5} more` : '';
      return `[${v.impact}] ${v.id}: ${v.help}\n${nodes}${more}`;
    })
    .join('\n\n');
}

for (const phase of phases) {
  const routes = PHASES[phase];
  if (!routes) continue;

  test.describe(`UX audit — phase ${phase}`, () => {
    for (const route of routes) {
      for (const theme of THEMES) {
        test.describe(`${route.name} [${theme}]`, () => {
          // Platform routes need both the super-admin session and the console's
          // own origin; a tenant-role route keeps the project baseURL.
          test.use(
            route.role === 'superadmin'
              ? { storageState: SUPER_ADMIN_STATE, baseURL: PLATFORM_BASE }
              : { storageState: `e2e/.auth/${route.role}.json` },
          );

          // Skip rather than fail when there is no super-admin session — but
          // never quietly pass, for the same reason as admin.spec.ts.
          test.skip(
            route.role === 'superadmin' && !superAdminAvailable,
            'no super-admin session — see the global-setup warning',
          );

          // Access tokens live 15 minutes and phase 8 runs ~36 minutes into a
          // full suite, so the session global-setup minted is dead by the time
          // these routes are reached — the whole /admin* block failed a full
          // run on 401s while passing standalone. Re-minted only when actually
          // stale, so 11 of these 12 describes do no login at all and the
          // 5/min/IP login limit is never approached.
          if (route.role === 'superadmin') {
            test.beforeAll(async () => {
              await ensureFreshSuperAdminSession();
            });
          }

          test(`renders, is accessible, and fits every breakpoint`, async ({
            page,
          }, testInfo) => {
            // The 30s project default is too tight for *this* spec, and only
            // this one: each test loads the route three times (375/768/1280),
            // takes a full-page screenshot at each, then runs axe. Measured on
            // an idle machine that is 15-29s — `device-detail` came in at 29.1s,
            // i.e. inside the default by 0.9s. Under any concurrent load it
            // tips over, and three runs here produced three timeout "failures"
            // (settings-users, leave, device-detail) on pages that were fine.
            //
            // A gate that cries wolf gets ignored, which is the same way the
            // Pest gate stopped meaning anything (§9.4). Raised here rather
            // than in playwright.config so every other spec keeps the tight
            // timeout that catches a genuine hang.
            test.setTimeout(90_000);

            const consoleErrors: string[] = [];

            // "Failed to load resource: 404" on its own names nothing. Capture
            // the request that actually failed so the report says which asset
            // or endpoint is missing.
            page.on('response', (res) => {
              if (res.status() < 400) return;

              // The audit is a far more aggressive client than any human: it
              // loads each route nine times (3 themes × 3 viewports) inside a
              // couple of minutes. CLAUDE.md rate-limits `GET /dashboard/*` to
              // 30/min per user because those queries are expensive, so the
              // harness trips its own limit on the dashboard-backed pages.
              // That is the limiter working as designed, not a page defect.
              //
              // Deliberately narrow: only 429, only on the documented
              // dashboard endpoints. A 429 anywhere else, or any other 4xx/5xx
              // here, still fails the phase.
              const isSelfInflictedThrottle =
                res.status() === 429 && /\/api\/v1\/dashboard\//.test(res.url());
              if (isSelfInflictedThrottle) return;

              consoleErrors.push(`HTTP ${res.status()} — ${res.url()}`);
            });
            page.on('requestfailed', (req) => {
              const errorText = req.failure()?.errorText ?? '';
              // Each engine spells "the client cancelled this" differently, and
              // matching only Chromium's string is why this filter did nothing on
              // WebKit: 13 mobile pages failed on cancelled RSC prefetches that
              // the two rules below were already written to forgive.
              const isCancellation =
                errorText === 'net::ERR_ABORTED' || // Chromium
                errorText === 'Load request cancelled'; // WebKit

              // Next.js cancels in-flight RSC prefetches when the router
              // navigates away; an aborted `?_rsc=` request is the framework
              // working correctly, not a defect. Everything else is reported.
              if (isCancellation && req.url().includes('_rsc=')) return;

              // TanStack Query cancels an in-flight fetch when the same query
              // is refetched or the component unmounts during the viewport
              // loop. An aborted API call is that cancellation, not a failure.
              if (isCancellation && req.url().includes('/api/v1/')) return;

              consoleErrors.push(`REQUEST FAILED — ${req.url()} (${errorText})`);
            });
            page.on('console', (msg) => {
              const text = msg.text();
              // Skip the generic companion line to the response events above,
              // which carries no URL and would only duplicate them.
              if (
                msg.type() === 'error' &&
                !text.startsWith('Failed to load resource')
              ) {
                if (isCancelledPrefetchMessage(text)) return;

                consoleErrors.push(text);
              }
            });
            // WebKit reports a cancelled RSC prefetch here too, as a rejected
            // fetch — so the same filter has to sit on both sinks. Filtering only
            // the console left 7 pages red with messages that read identically to
            // the ones already being forgiven a few lines up.
            page.on('pageerror', (err) => {
              const text = String(err);
              if (isCancelledPrefetchMessage(text)) return;

              consoleErrors.push(text);
            });

            await applyPreferences(page, theme);

            // `[id]` routes need a real record. Resolve one from the list page
            // before the viewport loop so all three widths audit the same
            // record, and fail loudly rather than silently auditing the list
            // page instead of the detail page we meant to check.
            let targetPath = route.path;
            if (route.dynamic) {
              const resolved = await resolveDynamicPath(page, route.dynamic);
              expect(
                resolved,
                `could not resolve a detail URL for ${route.name} from ${route.dynamic.listPath} — seed data missing?`,
              ).not.toBeNull();
              targetPath = resolved!;
            }

            for (const vp of VIEWPORTS) {
              await page.setViewportSize({ width: vp.width, height: vp.height });
              await page.goto(targetPath);
              await page.waitForLoadState('networkidle');

              await page.screenshot({
                path: testInfo.outputPath(
                  `ux-phase-${phase}/${route.name}-${theme}-${vp.name}.png`,
                ),
                fullPage: true,
              });

              // A page whose body scrolls sideways is broken at that width —
              // the most common way a "responsive" enterprise table fails on a
              // 375px phone.
              const overflows = await page.evaluate(
                () =>
                  document.documentElement.scrollWidth >
                  document.documentElement.clientWidth + 1,
              );
              expect(
                overflows,
                `${targetPath} scrolls horizontally at ${vp.width}px (${theme})`,
              ).toBe(false);
            }

            // A RoleGate denial is a fully accessible, perfectly contrasted
            // page: axe passes it, no request 4xxs, and the route reports green
            // while auditing nothing. That is precisely how the four /admin*
            // routes stayed green for months while being visited as a tenant
            // admin, who `RoleGate minRole="super_admin"` refuses — the real
            // console had never been audited at all, and the moment it was it
            // produced three genuine WCAG failures.
            //
            // Checked before axe so the report names the actual problem (wrong
            // role for this route) rather than the absence of violations on a
            // screen nobody meant to test.
            await expect(
              page.getByTestId('role-gate-denied'),
              `${targetPath} rendered RoleGate's "Access Denied" for role "${route.role}" — this audit would be measuring the denial screen, not the page. Fix the route's role in PHASES.`,
            ).toHaveCount(0);

            // Axe once per theme, at desktop — contrast is theme-dependent, and
            // the structural rules do not change with viewport width.
            const violations = await runAxe(page);
            expect(
              formatViolations(violations),
              `${targetPath} has WCAG AA violations (${theme})`,
            ).toBe('');

            // De-duplicate: the same asset is requested once per viewport pass,
            // so a single missing file would otherwise be reported three times.
            expect(
              [...new Set(consoleErrors)].join('\n'),
              `${targetPath} logged console/network errors (${theme})`,
            ).toBe('');
          });
        });
      }
    }
  });
}
