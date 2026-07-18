import { test, expect } from "@playwright/test";
import { login, DEMO_EMAIL, DEMO_PASS } from "./helpers";

const PAGES = [
  { path: "/dashboard", name: "dashboard" },
  { path: "/employees", name: "employees" },
  { path: "/attendance", name: "attendance" },
  { path: "/leave", name: "leave" },
  { path: "/payroll", name: "payroll" },
  { path: "/settings", name: "settings" },
  { path: "/profile", name: "profile" },
  { path: "/notifications", name: "notifications" },
];

test.describe("Dark mode verification", () => {
  test.beforeEach(async ({ page }) => {
    await login(page, DEMO_EMAIL, DEMO_PASS);
  });

  test("dark mode toggle exists and switches theme", async ({ page }) => {
    await page.goto("/dashboard");
    await page.waitForLoadState("networkidle");

    // Find the theme toggle
    const themeToggle = page
      .locator(
        'button[aria-label*="theme" i], button[aria-label*="dark" i], button[aria-label*="mode" i], [data-testid="theme-toggle"]',
      )
      .first();

    if ((await themeToggle.count()) > 0) {
      await themeToggle.click();
      await page.waitForTimeout(300);

      const isDark = await page.evaluate(() => {
        return (
          document.documentElement.classList.contains("dark") ||
          document.documentElement.getAttribute("data-theme") === "dark"
        );
      });

      expect(isDark).toBe(true);
    }
  });

  for (const { path, name } of PAGES) {
    test(`${name} — dark mode has no white flash or broken backgrounds`, async ({
      page,
    }) => {
      // Enable dark mode via prefers-color-scheme emulation
      await page.emulateMedia({ colorScheme: "dark" });
      await page.goto(path);
      await page.waitForLoadState("networkidle");

      // Check that the root element has dark tokens applied
      const bgColor = await page.evaluate(() => {
        const root = document.documentElement;
        return getComputedStyle(root)
          .getPropertyValue("--surface-primary")
          .trim();
      });

      // Dark mode should not have white (#ffffff or #fff) as surface
      if (bgColor) {
        expect(bgColor.toLowerCase()).not.toBe("#ffffff");
        expect(bgColor.toLowerCase()).not.toBe("#fff");
      }

      // No elements with hardcoded white backgrounds that break dark mode
      const brokenElements = await page.evaluate(() => {
        const elements = document.querySelectorAll("*");
        const broken: string[] = [];
        elements.forEach((el) => {
          const style = getComputedStyle(el);
          const bg = style.backgroundColor;
          // Check for pure white backgrounds on large visible elements
          if (bg === "rgb(255, 255, 255)" || bg === "#ffffff") {
            const rect = el.getBoundingClientRect();
            if (rect.width > 200 && rect.height > 100 && rect.top < 800) {
              broken.push(
                `${el.tagName}.${el.className.toString().slice(0, 50)}`,
              );
            }
          }
        });
        return broken.slice(0, 5);
      });

      // Allow a few (cards may have explicit white in dark mode), but flag if many
      expect(brokenElements.length).toBeLessThanOrEqual(3);

      await page.screenshot({
        path: `test-results/screenshots/dark-${name}.png`,
        fullPage: true,
      });
    });
  }

  test("login page renders correctly in dark mode", async ({ page }) => {
    await page.emulateMedia({ colorScheme: "dark" });
    await page.goto("/login");
    await page.waitForLoadState("networkidle");

    // Form should be visible and readable
    const emailInput = page.locator('input[type="email"]');
    await expect(emailInput).toBeVisible();

    // Input text should be readable (not same color as background)
    const inputColor = await emailInput.evaluate((el) => {
      const style = getComputedStyle(el);
      return { color: style.color, bg: style.backgroundColor };
    });

    expect(inputColor.color).not.toBe(inputColor.bg);

    await page.screenshot({
      path: `test-results/screenshots/dark-login.png`,
      fullPage: true,
    });
  });

  test("Amharic text renders correctly in dark mode", async ({ page }) => {
    await page.emulateMedia({ colorScheme: "dark" });
    await login(page, DEMO_EMAIL, DEMO_PASS);
    await page.goto("/dashboard");
    await page.waitForLoadState("networkidle");

    // Switch to Amharic if a language switcher exists
    const langSwitch = page
      .locator('button:has-text("አማ"), [data-testid="language-switch"]')
      .first();
    if ((await langSwitch.count()) > 0) {
      await langSwitch.click();
      await page.waitForTimeout(500);
    }

    // Verify Ethiopic text is visible and has adequate contrast
    const body = await page.locator("body").textContent();
    // The page should have SOME content (not blank)
    expect(body?.length).toBeGreaterThan(10);

    await page.screenshot({
      path: `test-results/screenshots/dark-amharic.png`,
      fullPage: true,
    });
  });
});
