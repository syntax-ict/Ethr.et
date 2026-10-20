/**
 * Lighthouse CI, authenticated pages.
 *
 * `.lighthouserc.cjs` measures only what an anonymous visitor can reach; it
 * says so in its header, and it is right not to pretend otherwise. This file
 * measures the pages people actually work in, with a session borrowed from
 * Playwright's storage state so no credential lives in config:
 *
 *   BASE_URL=http://demo.localhost:8081 npx playwright test e2e/auth.spec.ts
 *   LHCI_BASE_URL=http://demo.localhost:8081 \
 *     node node_modules/@lhci/cli/src/cli.js collect --config=.lighthouserc.auth.cjs
 *
 * `scripts/lighthouse-authenticated.sh` at the repository root does those
 * steps in order against the local-production rehearsal, desktop then mobile.
 * There are no assertions here on purpose: this is the baseline
 * docs/FRONTEND_REDESIGN_PLAN.md asks for before Phase 2, and a baseline that
 * fails itself is not one. Budgets come later, once a figure exists to budget
 * against.
 *
 * `scripts/lhci-prime.cjs` restores the saved cookies and localStorage before
 * each URL (the app's language lives in localStorage, so a cookie alone would
 * measure the Amharic default). Lighthouse's storage reset is left ON: its
 * default `clearStorageTypes` clears the HTTP cache, service workers and Cache
 * Storage but not cookies or localStorage, so every run is a cold load of a
 * signed-in page. Setting `disableStorageReset` would keep the session too, but
 * it also keeps the cache, and the median of three then reports a warm page
 * (measured 2026-10-20: dashboard weight 17 KB and LCP 721 ms warm against
 * 1.5 MB and 1.76 s cold). The tenant is resolved by the host
 * (`demo.localhost`), as in production, so no `X-Tenant` header is needed.
 */
const BASE = process.env.LHCI_BASE_URL || "http://demo.localhost:8081";

module.exports = {
  ci: {
    collect: {
      url: [
        `${BASE}/dashboard`,
        `${BASE}/employees`,
        `${BASE}/attendance`,
        `${BASE}/leave`,
        `${BASE}/payroll`,
        `${BASE}/settings`,
      ],
      numberOfRuns: 3,
      puppeteerScript: "scripts/lhci-prime.cjs",
      puppeteerLaunchOptions: { args: ["--no-sandbox", "--disable-gpu"] },
      settings: {
        ...(process.env.LHCI_PRESET === "mobile" ? {} : { preset: "desktop" }),
      },
    },
    upload: {
      target: "filesystem",
      outputDir: process.env.LHCI_OUTPUT_DIR || ".lighthouseci/authenticated",
    },
  },
};
