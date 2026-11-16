import { test, expect } from "@playwright/test";

/**
 * A sign-in button pressed before the page hydrates (a slow connection) used to
 * submit natively with GET and write every field, password included, into the
 * address. The forms now say method="post", their buttons are disabled until
 * hydration, and a leaked address is cleaned on load.
 *
 * Signs nobody in and needs no API. Selectors are ids and types only, because
 * the signed-out page defaults to Amharic and, in the first test, nothing has
 * hydrated.
 */
const TYPED_PASSWORD = "Typed-Before-Hydration-9";

test.describe("Sign-in before hydration", () => {
  test("a submit before hydration sends nothing to the address", async ({
    page,
  }) => {
    // No script runs, so nothing hydrates: the page stays as prerendered.
    await page.route("**/*.js", (route) => route.abort());
    await page.goto("/login", { waitUntil: "domcontentloaded" });
    const entry = new URL(page.url());

    const tenant = page.locator("#tenant");
    if (await tenant.isVisible()) await tenant.fill("acme");
    await page.fill("#identifier", "someone@example.com");
    await page.fill("#password", TYPED_PASSWORD);

    const form = page.locator("form").first();
    await expect(form).toHaveAttribute("method", "post");
    await expect(form.locator('button[type="submit"]')).toBeDisabled();

    // First line of defence: a disabled default button blocks Enter too.
    await page.press("#password", "Enter");
    await page.waitForTimeout(500);
    expect(new URL(page.url()).search).toBe("");
    expect(page.url()).toBe(entry.toString());

    // Second line: a submit that gets through anyway carries its fields in
    // the body, not the address.
    await Promise.all([
      page.waitForLoadState("domcontentloaded").catch(() => {}),
      page
        .evaluate(() => document.querySelector("form")!.submit())
        .catch(() => {}),
    ]);
    await page.waitForTimeout(500);

    expect(new URL(page.url()).search).not.toContain("password");
    expect(page.url()).not.toContain(TYPED_PASSWORD);
  });

  test("a leaked address is cleaned on load and stays clean", async ({
    page,
  }) => {
    await page.goto(
      "/login?identifier=someone%40example.com&password=leaked-secret",
    );

    await expect
      .poll(() => new URL(page.url()).searchParams.has("password"))
      .toBe(false);
    await expect
      .poll(() => new URL(page.url()).searchParams.has("identifier"))
      .toBe(false);
    await expect(page.locator("#password")).toHaveValue("");

    // The router keeps its own copy of the address and writes it back on its
    // next change. A refresh is such a change, so it shows a router that was
    // never told about the cleaned address.
    await page.evaluate(() =>
      (
        window as unknown as { next?: { router?: { refresh(): void } } }
      ).next?.router?.refresh(),
    );
    await page.waitForTimeout(1500);
    expect(new URL(page.url()).searchParams.has("password")).toBe(false);
    expect(page.url()).not.toContain("leaked-secret");

    // The address stays clean across a client-side navigation and Back.
    await page.locator('a[href^="/login/forgot"]').first().click();
    await page.waitForURL("**/login/forgot**");
    await page.goBack();
    await page.waitForURL((url) => url.pathname === "/login");
    await page.waitForTimeout(1000);
    expect(new URL(page.url()).searchParams.has("password")).toBe(false);
    expect(new URL(page.url()).searchParams.has("identifier")).toBe(false);
    expect(page.url()).not.toContain("leaked-secret");
  });
});
