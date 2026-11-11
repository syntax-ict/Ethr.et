/**
 * The signed-out half of the release smoke checklist
 * (`docs/production testing/10-TEMPLATES-AND-REFERENCE.md`, section 3).
 *
 * Each test is named for its checklist row, so `--grep S-02` runs one row and
 * the JSON report maps straight onto the execution log. All of it is Mode R.
 */
import { test, expect } from "./guard";
import {
  EXPECTED_COMMIT,
  PROBE_SECRETS,
  PROD_BASE,
  PROD_PLATFORM,
  QA_SLUG,
} from "./env";

test.use({ storageState: { cookies: [], origins: [] } });

test.describe("release smoke, signed out", () => {
  test(
    "S-01 the right release is live",
    { tag: ["@smoke", "@P1", "@R"] },
    async ({ request }, testInfo) => {
      const response = await request.get(`/release.json?t=${Date.now()}`, {
        headers: { "Cache-Control": "no-cache" },
      });
      expect(response.status()).toBe(200);
      const release = (await response.json()) as {
        commit?: string;
        built_at?: string;
      };
      expect(release.commit, "release.json names a full commit").toMatch(
        /^[0-9a-f]{40}$/,
      );
      testInfo.annotations.push({
        type: "live-commit",
        description: `${release.commit} built ${release.built_at}`,
      });
      if (EXPECTED_COMMIT) {
        expect(
          release.commit?.startsWith(EXPECTED_COMMIT),
          `live ${release.commit} is not ${EXPECTED_COMMIT}`,
        ).toBe(true);
      } else {
        testInfo.annotations.push({
          type: "note",
          description:
            "EXPECTED_COMMIT not set: the commit was read, not compared. Compare it with the release under test.",
        });
      }
    },
  );

  test(
    "S-02 healthy, schema current, scheduler running",
    { tag: ["@smoke", "@P1", "@R"] },
    async ({ request }) => {
      const response = await request.get("/api/v1/health", {
        headers: { Accept: "application/json" },
      });
      const health = (await response.json()) as {
        status: string;
        services: Record<string, string>;
        schema_detail?: { pending_migrations: number | null };
        queue_detail?: {
          scheduler?: {
            age_seconds: number | null;
            stale_after_seconds: number;
          };
        };
      };
      expect(
        health.status,
        `services: ${JSON.stringify(health.services)}`,
      ).toBe("healthy");
      expect(response.status()).toBe(200);
      // Above 0 the code is ahead of its schema: stop and tell the owner (S-02).
      expect(
        health.schema_detail?.pending_migrations,
        "pending migrations",
      ).toBe(0);
      const scheduler = health.queue_detail?.scheduler;
      expect(scheduler?.age_seconds, "scheduler has run").not.toBeNull();
      expect(scheduler?.age_seconds ?? Infinity).toBeLessThan(
        scheduler?.stale_after_seconds ?? 900,
      );
    },
  );

  test(
    "S-04 redirects",
    { tag: ["@smoke", "@P2", "@R"] },
    async ({ request }) => {
      const apex = new URL(PROD_BASE).hostname.replace(/^www\./, "");
      const www = new URL(PROD_BASE).origin;

      for (const [from, to] of [
        [`http://${apex}/`, `${www}/`],
        [`https://${apex}/${QA_SLUG}`, `${www}/${QA_SLUG}`],
      ]) {
        const hop = await request.get(from, { maxRedirects: 0 });
        expect(hop.status(), `${from} redirects permanently`).toBe(301);
        expect(hop.headers()["location"], `${from} goes to ${to}`).toBe(to);
      }

      const console_ = await request.get(PROD_PLATFORM);
      expect(
        new URL(console_.url()).pathname,
        "the console host lands on /admin",
      ).toMatch(/^\/admin\b/);
    },
  );

  test(
    "S-05 secrets are closed",
    { tag: ["@smoke", "@P1", "@R"] },
    async ({ request }) => {
      test.skip(
        !PROBE_SECRETS,
        "Opt-in (PROD_PROBE_SECRETS=1): the host's firewall bans an address after a few refused requests. At most three in ten minutes (01, rule 10).",
      );
      for (const secret of ["/.env", "/api/.env", "/artisan"]) {
        const response = await request.get(secret, { maxRedirects: 0 });
        expect([403, 404], `${secret} answered ${response.status()}`).toContain(
          response.status(),
        );
      }
    },
  );

  for (const [path, lang] of [
    ["/en", "en"],
    ["/am", "am"],
    ["/en/pricing", "en"],
  ] as const) {
    test(
      `S-06 marketing ${path}`,
      { tag: ["@smoke", "@P2", "@R"] },
      async ({ page, pageErrors }) => {
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        await expect(page.locator("html")).toHaveAttribute("lang", lang);
        await expect(page.locator("main")).toHaveCount(1);
        expect(pageErrors, "no uncaught page error").toEqual([]);
      },
    );
  }

  test(
    "S-07 the organisation's sign-in page",
    { tag: ["@smoke", "@P1", "@R"] },
    async ({ page, pageErrors }) => {
      // Follows the organisation to its canonical address: the path, its
      // subdomain or a verified domain (OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS).
      const response = await page.goto(`/${QA_SLUG}`);
      expect(response?.status()).toBe(200);
      await expect(page.locator("#identifier")).toBeVisible();
      await expect(page.locator('input[type="password"]')).toBeVisible();
      expect(pageErrors, "no uncaught page error").toEqual([]);
    },
  );
});
