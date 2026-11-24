import { test, expect } from "@playwright/test";
import { api, DEMO_TENANT } from "./helpers";

/**
 * Announcements, end to end (2026-11-22). Each journey creates its own story
 * through the API, asserts on what the screens read back, and deletes it. A
 * running stack is needed, as for every spec here.
 *
 * One announcement, one presentation: the same story says the same priority
 * word in the app's list, on the dashboard card and on the public news page.
 * They drew priority three different ways until this, so a story that read
 * "High" in the app read "Important" on the organisation's own website.
 */
const TITLE = `QA presentation ${Date.now()}`;

test.describe("Announcements: one presentation", () => {
  test.use({ storageState: "e2e/.auth/hr.json" });

  let publicId = "";

  test.beforeEach(async ({ page }) => {
    await page.goto("/dashboard");
    const created = await api(page, "POST", "/announcements", {
      title: TITLE,
      body: "Plain words.\n\nA second paragraph.",
      priority: "high",
      category: "notice",
      show_on_landing: true,
      publish_now: true,
    });
    expect(created.status).toBe(201);
    publicId = (created.data.data ?? created.data).public_id;
  });

  test.afterEach(async ({ page }) => {
    if (publicId) await api(page, "DELETE", `/announcements/${publicId}`);
    publicId = "";
  });

  test("says Important for a high priority story in the list, on the card and on /news", async ({
    page,
    browser,
  }) => {
    // The announcements page.
    await page.goto("/announcements");
    const row = page.getByRole("listitem").filter({ hasText: TITLE });
    await expect(row).toBeVisible({ timeout: 15000 });
    await expect(row.getByText("Important", { exact: true })).toBeVisible();
    // The server's excerpt, not a clamp of the raw body.
    await expect(row).toContainText("Plain words.");

    // The dashboard card.
    await page.goto("/dashboard");
    const card = page.getByRole("link").filter({ hasText: TITLE }).first();
    await expect(card).toBeVisible({ timeout: 15000 });
    await expect(card.getByText("Important", { exact: true })).toBeVisible();

    // The organisation's public news page, read signed out. On a single-host
    // development stack the organisation travels in `?org=`, as it does for
    // every other public-page spec; on an authoritative host it is the host.
    const anonymous = await browser.newContext();
    const news = await anonymous.newPage();
    await news.goto(`/news?org=${DEMO_TENANT}`);
    const story = news.getByRole("link").filter({ hasText: TITLE }).first();
    await expect(story).toBeVisible({ timeout: 15000 });
    await expect(story.getByText("Important", { exact: true })).toBeVisible();
    await anonymous.close();
  });

  test("reads the same title, excerpt and body from the public article and the in-app read", async ({
    page,
  }) => {
    // One story contract: both endpoints build the story from the same
    // presenter, so what the public reads is what the app reads.
    const article = await api(
      page,
      "GET",
      `/auth/tenant-landing/announcements/${publicId}`,
    );
    const inApp = await api(page, "GET", `/announcements/${publicId}`);
    expect(article.status).toBe(200);
    expect(inApp.status).toBe(200);

    const read = inApp.data.data ?? inApp.data;
    expect(read.title).toBe(TITLE);
    expect(article.data.title).toBe(read.title);
    expect(article.data.excerpt).toBe(read.excerpt);
    expect(article.data.body).toBe(read.body);
    expect(read.body).toContain("A second paragraph.");
  });
});

/**
 * Reading one announcement. The reader opens it from the list, the address
 * carries the story so it can be sent to a colleague, and the back button
 * closes the panel. A story the reader may not see says only that it is not
 * available: the API answers a draft and a missing id identically, which is
 * what stops a public id being confirmed across that line.
 */
test.describe("Announcements: reading a story", () => {
  test.use({ storageState: "e2e/.auth/hr.json" });

  const TITLE_READ = `QA reading ${Date.now()}`;
  let publicId = "";

  test.beforeEach(async ({ page }) => {
    await page.goto("/dashboard");
    const created = await api(page, "POST", "/announcements", {
      title: TITLE_READ,
      // The long address is deliberate: an unbroken string is what actually
      // overflows a narrow panel, and the body renders https addresses as
      // links, so this exercises their wrapping rather than only prose.
      body:
        "The first paragraph.\n\nThe second paragraph, which the list never shows in full.\n\n" +
        "https://example.gov.et/circulars/2026/finance-directorate/annual-leave-carryover-and-settlement-procedure-revision-three.pdf",
      priority: "normal",
      publish_now: true,
    });
    expect(created.status).toBe(201);
    publicId = (created.data.data ?? created.data).public_id;
  });

  test.afterEach(async ({ page }) => {
    if (publicId) await api(page, "DELETE", `/announcements/${publicId}`);
    publicId = "";
  });

  test("opens a story in full, keeps it in the address, and closes on back", async ({
    page,
  }) => {
    await page.goto("/announcements");
    await page.getByRole("button", { name: TITLE_READ, exact: true }).click();

    const pane = page.getByRole("dialog");
    await expect(pane).toContainText("The second paragraph", {
      timeout: 15000,
    });
    await expect(page).toHaveURL(new RegExp(`read=${publicId}`));

    // Most people read a notice on a phone. The width is set here rather than
    // left to the `webkit-mobile` project, because `gates.sh e2e` runs
    // `--project=chromium-desktop` only, so that project proves nothing in CI.
    //
    // Measured on the panel, not the document: the panel is `position: fixed`
    // and locks the body's scroll, so it cannot widen the page however wide
    // its content gets. Anything too wide scrolls sideways inside it instead,
    // which is what a reader on a phone would be stuck with.
    await page.setViewportSize({ width: 375, height: 812 });
    const box = await pane.evaluate((el) => ({
      scrollWidth: el.scrollWidth,
      clientWidth: el.clientWidth,
      right: el.getBoundingClientRect().right,
    }));
    expect(box.scrollWidth).toBeLessThanOrEqual(box.clientWidth + 1);
    expect(box.right).toBeLessThanOrEqual(376);

    await page.goBack();
    await expect(pane).toBeHidden();
  });

  test("closing it by hand gives the back button the page before the list", async ({
    page,
  }) => {
    // Closing the panel has to undo the history entry that opening it added.
    // Replacing that entry instead would leave two for the same list, and the
    // next back press would visibly do nothing.
    await page.goto("/notifications");
    await page.goto("/announcements");
    await page.getByRole("button", { name: TITLE_READ, exact: true }).click();

    const pane = page.getByRole("dialog");
    await expect(pane).toContainText("The second paragraph", {
      timeout: 15000,
    });

    await page.keyboard.press("Escape");
    await expect(pane).toBeHidden();

    await page.goBack();
    await expect(page).toHaveURL(/\/notifications/);
  });

  test("opens straight from an address that names the story", async ({
    page,
  }) => {
    await page.goto(`/announcements?read=${publicId}`);

    await expect(page.getByRole("dialog")).toContainText(
      "The second paragraph",
      {
        timeout: 15000,
      },
    );
  });

  test("says only that a story is not available", async ({ page }) => {
    await page.goto("/announcements?read=01HQZX3NDJKMNP4RSTVWXY5Z6A");

    await expect(page.getByText(/not available/i)).toBeVisible({
      timeout: 15000,
    });
  });
});
