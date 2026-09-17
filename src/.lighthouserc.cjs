/**
 * Lighthouse CI configuration.
 *
 * The URL list is deliberately limited to routes that render correctly for an
 * anonymous visitor. It previously included /dashboard, /employees,
 * /attendance and /payroll — but Lighthouse CI has no session, so all four
 * redirected to /login and scored the login page four times over while
 * appearing to cover the application. The assertions passed comfortably and
 * would have gone on passing while the real authenticated score was 54.
 *
 * Authenticated performance is measured separately, because doing it here
 * means either shipping a credential into CI config or driving Lighthouse
 * through Playwright's storage state. Until that exists, this file measures
 * what it can honestly reach and does not imply more. See
 * `docs/audits/UX_PHASE_09.md` for the authenticated numbers and how they were
 * produced.
 */
// Base origin under test. Defaults to the single-host dev URL so nothing
// changes for existing local/CI use; the reproducible Docker E2E harness sets
// LHCI_BASE_URL=http://demo.ethr.test so Lighthouse hits the same production-like
// subdomain path (through nginx) that the Playwright suite does.
const BASE = process.env.LHCI_BASE_URL || "http://demo.localhost:3000";

module.exports = {
  ci: {
    collect: {
      // Locale-prefixed, because the unprefixed URLs stopped holding content.
      // `/pricing` and friends are now redirectors that render an empty div and
      // negotiate a language — Lighthouse has no stored preference, so it would
      // have scored three blank pages and reported them as passing. That is the
      // same failure this file's own header describes for /dashboard: measuring
      // something real-looking that is not the thing under test.
      //
      // `/am` as well as `/en`: Amharic is the default language, and it is the
      // one that pulls the 198 KB Ethiopic font, so scoring only English would
      // miss the heavier of the two renders.
      url: [
        `${BASE}/en`,
        `${BASE}/am`,
        `${BASE}/en/pricing`,
        `${BASE}/en/features`,
        `${BASE}/en/contact`,
        `${BASE}/login`,
        `${BASE}/register`,
      ],
      numberOfRuns: 3,
      settings: {
        preset: "desktop",
        chromeFlags: "--no-sandbox --disable-gpu",
      },
    },
    // Split, because one of these assertions is meaningless on two of the URLs.
    //
    // `categories:seo` was an **error** on every page, and /login and /register
    // scored 0.63 on a single failing audit: `is-crawlable`, "Page is blocked
    // from indexing". They are blocked on purpose — `app/robots.ts` disallows
    // both — so the config was asserting a target the site's own robots.txt
    // guarantees can never be met. Found by running this gate rather than by
    // reading it; the threshold and those two URLs both predate the split.
    //
    // They stay in the URL list: performance and accessibility are worth
    // measuring on the two screens every signup passes through. Only the SEO
    // assertion is lifted, and the patterns are mutually exclusive so nothing
    // matches twice.
    assert: {
      assertMatrix: [
        {
          matchingUrlPattern: "^(?!.*/(login|register)$).*$",
          assertions: {
            // Thresholds from CLAUDE.md "Performance Targets".
            "categories:performance": ["error", { minScore: 0.8 }],
            "categories:accessibility": ["error", { minScore: 0.9 }],
            "categories:best-practices": ["warn", { minScore: 0.8 }],
            // These are public, indexable marketing pages, so SEO is an error
            // rather than a warning — a regression here costs real signups.
            "categories:seo": ["error", { minScore: 0.9 }],
            "first-contentful-paint": ["warn", { maxNumericValue: 1500 }],
            "largest-contentful-paint": ["warn", { maxNumericValue: 2500 }],
            "cumulative-layout-shift": ["error", { maxNumericValue: 0.1 }],
            "total-blocking-time": ["warn", { maxNumericValue: 300 }],
          },
        },
        {
          // The auth screens: same budgets, minus the SEO category.
          matchingUrlPattern: ".*/(login|register)$",
          assertions: {
            "categories:performance": ["error", { minScore: 0.8 }],
            "categories:accessibility": ["error", { minScore: 0.9 }],
            "categories:best-practices": ["warn", { minScore: 0.8 }],
            "first-contentful-paint": ["warn", { maxNumericValue: 1500 }],
            "largest-contentful-paint": ["warn", { maxNumericValue: 2500 }],
            "cumulative-layout-shift": ["error", { maxNumericValue: 0.1 }],
            "total-blocking-time": ["warn", { maxNumericValue: 300 }],
          },
        },
      ],
    },
    upload: {
      target: "filesystem",
      outputDir: "./lighthouse-reports",
    },
  },
};
