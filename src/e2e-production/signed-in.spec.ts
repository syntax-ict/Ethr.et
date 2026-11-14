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
