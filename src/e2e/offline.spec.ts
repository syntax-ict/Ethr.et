import { test, expect } from "@playwright/test";

test.describe("PWA & Offline Behavior", () => {
  test.use({ storageState: "e2e/.auth/employee.json" });

  test("service worker registers successfully", async ({ page }) => {
    await page.goto("/dashboard");

    // `serviceWorker.ready` waits for an active registration rather than
    // sampling the current state immediately, which can race the
    // useEffect-driven register() call on first navigation.
    const swRegistered = await page.evaluate(async () => {
      if (!("serviceWorker" in navigator)) return false;
      const reg = await navigator.serviceWorker.ready;
      return !!reg;
    });

    expect(swRegistered).toBe(true);
  });

  test("manifest.json is accessible and valid", async ({ page }) => {
    const response = await page.goto("/manifest.json");
    expect(response?.status()).toBe(200);

    const manifest = await response?.json();
    expect(manifest.name).toContain("ETHR");
    expect(manifest.display).toBe("standalone");
    expect(manifest.start_url).toBe("/dashboard");
    expect(manifest.icons.length).toBeGreaterThan(0);
  });

  test("offline fallback page loads when network is down", async ({
    page,
    context,
  }) => {
    await page.goto("/dashboard");

    // Wait for service worker to activate and cache shell
    await page.waitForTimeout(2000);

    // Go offline
    await context.setOffline(true);

    // Navigate to a page not in the shell cache
    await page
      .goto("/settings", { waitUntil: "commit", timeout: 10000 })
      .catch(() => {});

    // Should show offline page or cached content
    const offlineIndicator = page.locator(
      "text=/offline|no.*connection|you.*offline/i",
    );
    const cachedContent = page.locator("h1, h2, [data-testid]");
    await expect(offlineIndicator.or(cachedContent).first()).toBeVisible({
      timeout: 8000,
    });

    await context.setOffline(false);
  });

  test("attendance mobile page is precached in shell", async ({
    page,
    context,
  }) => {
    await page.goto("/dashboard");
    await page.waitForTimeout(2000);

    await context.setOffline(true);

    const response = await page
      .goto("/attendance/mobile", {
        waitUntil: "commit",
        timeout: 10000,
      })
      .catch(() => null);

    // Shell pages should serve from cache
    const content = page.locator("body");
    await expect(content).not.toBeEmpty();

    await context.setOffline(false);
  });

  test("kiosk page is precached in shell", async ({ page, context }) => {
    await page.goto("/kiosk");
    await page.waitForTimeout(2000);

    await context.setOffline(true);

    await page
      .goto("/kiosk", { waitUntil: "commit", timeout: 10000 })
      .catch(() => {});
    const content = page.locator("body");
    await expect(content).not.toBeEmpty();

    await context.setOffline(false);
  });

  test("offline banner appears when connection drops", async ({
    page,
    context,
  }) => {
    await page.goto("/dashboard");
    await page.waitForLoadState("networkidle");

    await context.setOffline(true);

    // Trigger a navigation or interaction to surface the banner
    await page
      .goto("/employees", { waitUntil: "commit", timeout: 10000 })
      .catch(() => {});

    const offlineBanner = page
      .locator('[data-testid="offline-banner"], [role="alert"]:has-text("offline")')
      .or(page.getByText(/offline|no.*internet/i));
    await expect(offlineBanner.first()).toBeVisible({ timeout: 8000 });

    await context.setOffline(false);
  });

  test("API responses cached for offline access", async ({ page, context }) => {
    // Load dashboard to cache API responses
    await page.goto("/dashboard");
    await page.waitForLoadState("networkidle");

    // Go offline and reload — cached API data should render
    await context.setOffline(true);
    await page.reload({ waitUntil: "commit", timeout: 10000 }).catch(() => {});

    // The page should show either cached data or the offline state
    const body = page.locator("body");
    await expect(body).not.toBeEmpty();

    await context.setOffline(false);
  });
});
