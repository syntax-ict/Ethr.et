/**
 * The signed-in half of the release smoke checklist (10, section 3: S-08 to
 * S-11 and S-13), read-only, in the QA organisation only.
 *
 * It never signs in. Each persona's session is saved by a person beforehand
 * (11, section 3), because every admin role needs an authenticator code and no
 * password belongs in an environment variable, a log or an agent's context.
 * A persona with no fresh session is skipped and says why.
 */
import type { Page } from "@playwright/test";
import { test as guarded, expect, errorToasts } from "./guard";
import {
  PROD_BASE,
  PROD_PLATFORM,
  QA_SLUG,
  sessionFile,
  sessionHostProblem,
  sessionProblem,
  type Role,
} from "./env";

/**
 * The organisation's canonical address: the path itself, its subdomain or a
 * verified domain. The session cookie is host-only, so a saved session works
 * only there, and the path redirects to it.
 */
const test = guarded.extend<object, { orgURL: string }>({
  orgURL: [
    async ({ playwright }, provide) => {
      const api = await playwright.request.newContext({ baseURL: PROD_BASE });
      const entry = await api.get(`/${QA_SLUG}`);
      await provide(new URL(entry.url()).origin);
      await api.dispose();
    },
    { scope: "worker" },
  ],
});

/** Open a page as a signed-in persona and check it the way S-10 reads. */
async function opens(page: Page, url: string, pageErrors: string[]) {
  const response = await page.goto(url);
  await page.waitForLoadState("networkidle").catch(() => {});
  expect(
    new URL(page.url()).pathname,
    `${url}: the saved session was refused (expired?)`,
  ).not.toMatch(/\/login(\/|$)/);
  expect(response?.status() ?? 0, `${url} status`).toBeLessThan(400);
  await expect(page.locator("main").first()).toBeVisible();
  await expect(errorToasts(page), `${url}: error toast`).toHaveCount(0);
  expect(pageErrors, `${url}: uncaught page error`).toEqual([]);
}

function asPersona(role: Role) {
  const problem = sessionProblem(role);
  test.skip(problem !== null, problem ?? "");
  test.use({ storageState: sessionFile(role) });
  if (role === "pa") {
    const consoleHost = sessionHostProblem(
      role,
      new URL(PROD_PLATFORM).hostname,
    );
    test.skip(problem === null && consoleHost !== null, consoleHost ?? "");
    return;
  }
  // Skipped rather than failed: a session saved on the wrong host is a
  // tester's mistake, and run anyway it reads as a sign-in defect.
  test.beforeEach(({ orgURL }) => {
    const hostProblem = sessionHostProblem(role, new URL(orgURL).hostname);
    test.skip(hostProblem !== null, hostProblem ?? "");
  });
}

const TENANT_ROLES: Role[] = ["ta", "hr", "fin", "sup", "emp"];

for (const role of TENANT_ROLES) {
  test.describe(`S-08 ${role.toUpperCase()}`, () => {
    asPersona(role);
    test(
      `S-08 ${role.toUpperCase()} reaches the dashboard`,
      { tag: ["@smoke", "@P1", "@R"] },
      async ({ page, orgURL, pageErrors }) => {
        await opens(page, `${orgURL}/dashboard`, pageErrors);
      },
    );
  });
}

test.describe("S-10 one read per module, as TA", () => {
  asPersona("ta");
  for (const path of [
    "/employees",
    "/attendance",
    "/leave",
    "/payroll",
    "/reports",
    "/announcements",
    "/settings",
    "/billing",
    "/devices",
  ]) {
    test(
      `S-10 TA ${path}`,
      { tag: ["@smoke", "@P1", "@R"] },
      async ({ page, orgURL, pageErrors }) => {
        await opens(page, `${orgURL}${path}`, pageErrors);
      },
    );
  }
});

/**
 * One read per settings page, as TA (settings upgrade, PR 16). Each page's h1
 * is the label its route states (`lib/route-meta.ts`), so a page that renders
 * without its title bar, or under another title, fails here. A sub-page's
 * breadcrumb names "Settings" as its parent (PR 12, F15).
 */
const SETTINGS_PAGES: { path: string; h1: string; parent?: string }[] = [
  { path: "/settings", h1: "General settings" },
  { path: "/settings/attendance", h1: "Attendance rules" },
  { path: "/settings/leave-types", h1: "Leave types" },
  { path: "/settings/opening-balances", h1: "Opening balances" },
  { path: "/settings/approval-chains", h1: "Approval chains" },
  { path: "/settings/exit-clearance", h1: "Exit checklist" },
  { path: "/settings/holidays", h1: "Holidays" },
  { path: "/settings/payroll", h1: "Payroll rules" },
  { path: "/settings/roles", h1: "Roles & permissions" },
  { path: "/settings/users", h1: "Users & access" },
  { path: "/settings/api-keys", h1: "API keys" },
  {
    path: "/settings/api-keys/reference",
    h1: "API reference",
    parent: "API keys",
  },
  { path: "/settings/webhooks", h1: "Webhooks" },
  { path: "/settings/accounting", h1: "Accounting" },
  { path: "/settings/notification-templates", h1: "Notification templates" },
  { path: "/settings/audit-logs", h1: "Audit log" },
  { path: "/settings/data-export", h1: "Data export" },
  { path: "/settings/scim", h1: "SCIM provisioning" },
];

/** The General page's tabs, reached with `?tab=`. Each card title is an h2. */
const SETTINGS_TABS: { tab: string; h2: string[] }[] = [
  { tab: "general", h2: [] },
  { tab: "branding", h2: [] },
  {
    tab: "security",
    h2: [
      "Sign-in security",
      "Password rules",
      "Activation codes",
      "Sign-in methods",
    ],
  },
  { tab: "sso", h2: ["SAML single sign-on"] },
];

/** The page's own title is the one h1, and the page did not fail to load. */
async function settingsPageShows(page: Page, h1: string) {
  await expect(
    page.getByRole("heading", { level: 1, name: h1, exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText(/Couldn.t load this/),
    "an error state is on screen",
  ).toHaveCount(0);
}

test.describe("S-14 settings, one read per page, as TA", () => {
  asPersona("ta");

  for (const { path, h1, parent } of SETTINGS_PAGES) {
    test(
      `S-14 TA ${path} shows "${h1}"`,
      { tag: ["@smoke", "@P1", "@R"] },
      async ({ page, orgURL, pageErrors }) => {
        await opens(page, `${orgURL}${path}`, pageErrors);
        await settingsPageShows(page, h1);
        if (path !== "/settings") {
          const crumbs = page.getByRole("navigation", { name: "Breadcrumb" });
          await expect(
            crumbs.getByRole("link", {
              name: parent ?? "Settings",
              exact: true,
            }),
            "the breadcrumb's parent",
          ).toBeVisible();
        }
      },
    );
  }

  for (const { tab, h2 } of SETTINGS_TABS) {
    test(
      `S-14 TA /settings?tab=${tab}`,
      { tag: ["@smoke", "@P1", "@R"] },
      async ({ page, orgURL, pageErrors }) => {
        await opens(page, `${orgURL}/settings?tab=${tab}`, pageErrors);
        await settingsPageShows(page, "General settings");
        await expect(
          page
            .getByRole("tablist")
            .first()
            .getByRole("tab", { selected: true }),
          "the tab that opened",
        ).toHaveAttribute("id", new RegExp(`trigger-${tab}$`));
        for (const title of h2) {
          await expect(
            page.getByRole("heading", { level: 2, name: title, exact: true }),
            `the "${title}" card`,
          ).toBeVisible();
        }
      },
    );
  }

  test(
    "S-14 TA SSO says Not ready, never Enabled, while something is missing",
    { tag: ["@smoke", "@P1", "@R"] },
    async ({ page, orgURL, pageErrors }, testInfo) => {
      await opens(page, `${orgURL}/settings?tab=sso`, pageErrors);
      await expect(
        page.getByRole("heading", { level: 2, name: "SAML single sign-on" }),
      ).toBeVisible();
      // Only the SSO tab is mounted, so these three words are on screen once.
      const badge = page
        .locator("main")
        .getByText(/^(Not ready|Enabled|Disabled)$/)
        .first();
      await expect(badge, "SSO's status badge").toBeVisible();
      const status = (await badge.textContent())?.trim() ?? "";
      testInfo.annotations.push({ type: "sso-status", description: status });
      const missing = await page.getByText(/Still missing:/).count();
      if (missing > 0) {
        expect(status, "SSO is switched on but cannot work").toBe("Not ready");
      } else {
        expect(status, "nothing is missing, so it is not 'Not ready'").not.toBe(
          "Not ready",
        );
      }
    },
  );

  test(
    "S-14 TA the command palette finds SSO",
    { tag: ["@smoke", "@P2", "@R"] },
    async ({ page, orgURL, pageErrors }) => {
      await opens(page, `${orgURL}/settings`, pageErrors);
      await page.keyboard.press("Control+k");
      const box = page.getByRole("combobox").first();
      await expect(box, "the palette's search box").toBeVisible();
      await box.fill("SSO");
      await expect(
        page.getByRole("option", { name: /SSO/ }).first(),
        "a palette row for SSO",
      ).toBeVisible();
      await page.keyboard.press("Escape");
    },
  );

  test(
    "S-14 TA the Users search box has an accessible name",
    { tag: ["@smoke", "@P2", "@R"] },
    async ({ page, orgURL, pageErrors }) => {
      await opens(page, `${orgURL}/settings/users`, pageErrors);
      await expect(
        page.getByRole("textbox", { name: "Search by name or email" }),
      ).toBeVisible();
    },
  );
});

test.describe("S-11 self-service, as EMP", () => {
  asPersona("emp");
  for (const path of [
    "/attendance",
    "/leave",
    "/payroll/payslips",
    "/profile",
  ]) {
    test(
      `S-11 EMP ${path}`,
      { tag: ["@smoke", "@P1", "@R"] },
      async ({ page, orgURL, pageErrors }) => {
        await opens(page, `${orgURL}${path}`, pageErrors);
      },
    );
  }
});

test.describe("S-13 phone, as EMP", () => {
  asPersona("emp");
  test.use({
    viewport: { width: 375, height: 812 },
    isMobile: true,
    hasTouch: true,
  });
  test(
    "S-13 dashboard at 375 px has no horizontal scroll",
    { tag: ["@smoke", "@P2", "@R"] },
    async ({ page, orgURL, pageErrors }) => {
      await opens(page, `${orgURL}/dashboard`, pageErrors);
      const overflow = await page.evaluate(
        () =>
          document.documentElement.scrollWidth -
          document.documentElement.clientWidth,
      );
      expect(overflow, "pixels wider than the screen").toBeLessThanOrEqual(0);
    },
  );
});

test.describe("S-09 console, as PA", () => {
  asPersona("pa");
  test(
    "S-09 PA lands on the console",
    { tag: ["@smoke", "@P1", "@R"] },
    async ({ page, pageErrors }) => {
      await opens(page, `${PROD_PLATFORM}/admin`, pageErrors);
      expect(
        new URL(page.url()).pathname,
        "the console, not an organisation dashboard",
      ).toMatch(/^\/admin\b/);
    },
  );
});
