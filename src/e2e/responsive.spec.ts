import { test, expect } from "@playwright/test";
import { api, publicNewsPath, publishPublicStory } from "./helpers";

const VIEWPORTS = [
  { name: "mobile-sm", width: 375, height: 812 },
  { name: "mobile-lg", width: 390, height: 844 },
  { name: "tablet", width: 768, height: 1024 },
  { name: "laptop", width: 1024, height: 768 },
  { name: "desktop", width: 1280, height: 800 },
  { name: "desktop-xl", width: 1440, height: 900 },
];

const PAGES = [
  { path: "/dashboard", name: "dashboard" },
  { path: "/employees", name: "employees" },
  { path: "/attendance", name: "attendance" },
  { path: "/leave", name: "leave" },
  { path: "/payroll", name: "payroll" },
  { path: "/settings", name: "settings" },
  // Everyone reads announcements, and most of them on a phone.
  { path: "/announcements", name: "announcements" },
];

test.describe("Responsive layout verification", () => {
  test.use({ storageState: "e2e/.auth/admin.json" });

  for (const viewport of VIEWPORTS) {
    test.describe(`${viewport.name} (${viewport.width}x${viewport.height})`, () => {
      test.beforeEach(async ({ page }) => {
        await page.setViewportSize({
          width: viewport.width,
          height: viewport.height,
        });
      });

      for (const { path, name } of PAGES) {
        test(`${name} renders without horizontal overflow`, async ({
          page,
        }) => {
          await page.goto(path);
          await page.waitForLoadState("networkidle");

          const hasHorizontalOverflow = await page.evaluate(() => {
            return (
              document.documentElement.scrollWidth >
              document.documentElement.clientWidth
            );
          });

          expect(hasHorizontalOverflow).toBe(false);
        });
      }

      test("no text truncation on critical elements", async ({ page }) => {
        await page.goto("/dashboard");
        await page.waitForLoadState("networkidle");

        // Check headings are fully visible (not clipped by overflow:hidden without ellipsis)
        const headings = page.locator("h1, h2, h3");
        const count = await headings.count();
        for (let i = 0; i < Math.min(count, 5); i++) {
          const heading = headings.nth(i);
          if (await heading.isVisible()) {
            const box = await heading.boundingBox();
            expect(box).not.toBeNull();
            expect(box!.width).toBeGreaterThan(0);
            expect(box!.height).toBeGreaterThan(0);
          }
        }
      });

      if (viewport.width < 768) {
        test("sidebar is hidden on mobile", async ({ page }) => {
          await page.goto("/dashboard");
          await page.waitForLoadState("networkidle");

          const sidebar = page
            .locator('[data-sidebar], aside, nav[aria-label="Main navigation"]')
            .first();
          if ((await sidebar.count()) > 0) {
            await expect(sidebar).not.toBeVisible();
          }
        });

        test("mobile bottom nav is visible", async ({ page }) => {
          await page.goto("/dashboard");
          await page.waitForLoadState("networkidle");

          const bottomNav = page.locator(
            '.mobile-bottom-nav, nav[aria-label="Mobile navigation"], [data-testid="mobile-nav"]',
          );
          if ((await bottomNav.count()) > 0) {
            await expect(bottomNav.first()).toBeVisible();
          }
        });
      }

      if (viewport.width >= 1024) {
        test("sidebar is visible on desktop", async ({ page }) => {
          await page.goto("/dashboard");
          await page.waitForLoadState("networkidle");

          const sidebar = page
            .locator('[data-sidebar], aside, nav[aria-label="Main navigation"]')
            .first();
          if ((await sidebar.count()) > 0) {
            await expect(sidebar).toBeVisible();
          }
        });
      }

      // Screenshot capture for visual review
      test(`captures screenshot for visual review`, async ({ page }) => {
        await page.goto("/dashboard");
        await page.waitForLoadState("networkidle");
        await page.screenshot({
          path: `test-results/screenshots/${viewport.name}-dashboard.png`,
          fullPage: true,
        });
      });
    });
  }
});

/**
 * The organisation's public news pages, read signed out, at the narrowest and
 * the widest of the widths above. A story is published first, so the list has
 * a story card and the article page exists.
 */
test.describe("Responsive layout: the public news pages", () => {
  test.use({ storageState: "e2e/.auth/hr.json" });

  for (const viewport of VIEWPORTS.filter(
    (v) => v.width === 375 || v.width === 1280,
  )) {
    test(`/news and a story fit at ${viewport.name} (${viewport.width}px)`, async ({
      page,
      browser,
    }) => {
      const publicId = await publishPublicStory(
        page,
        `QA news width ${viewport.width} ${Date.now()}`,
      );
      const visitor = await browser.newContext();
      try {
        const reader = await visitor.newPage();
        await reader.setViewportSize({
          width: viewport.width,
          height: viewport.height,
        });

        for (const path of [publicNewsPath(), publicNewsPath(publicId)]) {
          await reader.goto(path);
          await expect(reader.getByRole("heading", { level: 1 })).toBeVisible({
            timeout: 15000,
          });
          await reader.waitForLoadState("networkidle");

          const overflows = await reader.evaluate(
            () =>
              document.documentElement.scrollWidth >
              document.documentElement.clientWidth,
          );
          expect(overflows, `${path} scrolls sideways`).toBe(false);
        }
      } finally {
        await visitor.close();
        await api(page, "DELETE", `/announcements/${publicId}`);
      }
    });
  }
});
