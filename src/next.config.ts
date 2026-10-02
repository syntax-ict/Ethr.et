import type { NextConfig } from "next";
import bundleAnalyzer from "@next/bundle-analyzer";
import { withSentryConfig } from "@sentry/nextjs";

import { IS_STATIC_EXPORT } from "./src/lib/build-target";

/**
 * The origin of the self-hosted Sentry instance, derived from the DSN.
 *
 * `connect-src` below is a strict allow-list, so without this the browser blocks
 * every outbound event and error reporting fails silently — the worst failure mode
 * for observability. Empty string when no DSN is configured, which changes nothing.
 */
const sentryOrigin = (() => {
  const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN;
  if (!dsn) return "";

  try {
    return ` ${new URL(dsn).origin}`;
  } catch {
    return "";
  }
})();

const nextConfig: NextConfig = {
  /**
   * `export` for Bronze shared hosting, `standalone` everywhere else.
   *
   * See `src/lib/build-target.ts` for why this is a switch rather than a flip.
   *
   * That file and this comment disagreed for a day and this is the half that was
   * wrong: it read "the Docker/VPS stack is still the documented rollback path and
   * it runs the `server.js` that `export` does not emit". The VPS was
   * decommissioned in `3db9904` (2026-09-27), before a verified cutover, so there
   * is nothing to roll back *to* — `build-target.ts` was updated in that same
   * commit and this was not.
   *
   * The switch stays, for the reason that survived: `standalone` is what a
   * plain `next build` produces here, locally and in CI's frontend job, and
   * changing the default would change what every developer and every gate
   * builds. (It also argued from `docker/frontend/Dockerfile` running the
   * `server.js` only `standalone` emits; that file went with the Docker
   * development stack on 2026-09-30.)
   *
   * Two things stop working under `export`, both by design rather than
   * oversight, and both already have a replacement in
   * `docs/deployment/shared-hosting/.htaccess`:
   *
   * - `headers()` below is not applied — there is no server to apply it. The
   *   `.htaccess` `mod_headers` block is the authority on that target, and its
   *   CSP has to allow the inline scripts this build emits or nothing hydrates.
   * - `rewrites()` is not applied, which is why it is skipped outright below
   *   rather than left to warn on every build.
   *
   * `middleware.ts` is *not* deleted for this target. Measured 2026-09-26: Next
   * 16.3.5 builds an export with it present and simply never runs it. What each
   * of its responsibilities falls back to is written up in that file.
   */
  output: IS_STATIC_EXPORT ? "export" : "standalone",
  reactStrictMode: true,
  poweredByHeader: false,
  experimental: {
    // Propagates the trace headers into the SSR'd HTML so a browser pageload joins
    // the server trace instead of starting a detached one. Sentry asks for this on
    // Next 15+ App Router; it cannot set it itself because it misdetects Next 16.
    clientTraceMetadata: ["sentry-trace", "baggage"],
  },
  async headers() {
    return [
      {
        source: "/(.*)",
        headers: [
          { key: "X-Frame-Options", value: "DENY" },
          { key: "X-Content-Type-Options", value: "nosniff" },
          {
            key: "Strict-Transport-Security",
            value: "max-age=31536000; includeSubDomains; preload",
          },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          {
            key: "Permissions-Policy",
            value:
              "accelerometer=(), camera=(self), geolocation=(self), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()",
          },
          {
            key: "Content-Security-Policy",
            value: [
              "default-src 'self'",
              "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
              "style-src 'self' 'unsafe-inline'",
              // `https:` is required for tenant branding to work at all: a
              // tenant's logo is stored as a URL (`PUT /settings/branding`
              // takes `logo_url`, and every consumer — sidebar, header, mobile
              // nav, kiosk — renders `tenant.logo_path` straight into an
              // <img src>). Without it the browser blocks the image and the
              // whole feature is inert while appearing to save correctly.
              // Widening img-src is the low-risk direction: images execute
              // nothing, and the alternative (uploading logos to object storage)
              // needs every one of those consumers to resolve a storage key to
              // a URL first.
              "img-src 'self' data: blob: https:",
              "font-src 'self' data:",
              `connect-src 'self' ws: wss:${sentryOrigin}`,
              "frame-ancestors 'none'",
              "base-uri 'self'",
              "form-action 'self'",
            ].join("; "),
          },
        ],
      },
    ];
  },
  async rewrites() {
    const backend = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000";
    return [
      {
        source: "/api/:path*",
        destination: `${backend}/api/:path*`,
      },
      {
        source: "/sanctum/:path*",
        destination: `${backend}/sanctum/:path*`,
      },
    ];
  },
};

/**
 * `headers` and `rewrites` belong to the Node targets only.
 *
 * Both are ignored under `output: "export"` — correctly; there is no server to
 * apply them — and Next warns about each on every build while the key is merely
 * *present*, whatever it returns. Removing them is what silences that, and a
 * build which always prints two warnings is a build whose warnings stop being
 * read. `gates.sh`'s `security` gate is this repository's standing example of
 * what that costs.
 *
 * They are deleted rather than declared conditionally so the object above stays
 * one literal: the CSP is the most consequential thing in this file, and moving
 * it to make a conditional read nicely is not a trade worth taking.
 *
 * What owns them on shared hosting is
 * `docs/deployment/shared-hosting/.htaccess`:
 *
 * - **Headers** — its `mod_headers` block is the authority there, and it is
 *   deliberately *not* a copy of the CSP above: no `wss:`, because Reverb is not
 *   deployed on that target. What it does have to carry is `'unsafe-inline'` in
 *   `script-src`, because this build emits inline bootstrap scripts with no
 *   nonce — measured 2026-09-26, three of them, ~13 KB. Without it the browser
 *   blocks them and the application never hydrates, while the HTML still renders.
 * - **Rewrites** — Apache routes `/api` and `/sanctum` to `index.php` in the same
 *   document root, so the SPA's relative `/api/v1/...` calls reach Laravel with
 *   no proxy directive at all. Measured working in Phase 1.
 */
if (IS_STATIC_EXPORT) {
  delete nextConfig.headers;
  delete nextConfig.rewrites;
}

const withBundleAnalyzer = bundleAnalyzer({
  enabled: process.env.ANALYZE === "true",
});

const withSentry = withSentryConfig(withBundleAnalyzer(nextConfig), {
  // Source map upload is opt-in: it needs an auth token and a reachable Sentry
  // instance, neither of which exist in local development or CI.
  silent: true,
  // Only upload when explicitly configured, so builds never fail on a missing token.
  sourcemaps: { disable: !process.env.SENTRY_AUTH_TOKEN },
  org: process.env.SENTRY_ORG,
  project: process.env.SENTRY_PROJECT,
  authToken: process.env.SENTRY_AUTH_TOKEN,

  /**
   * Strip debug statements from the production client bundle.
   *
   * Measured effect on this app: negligible. Sentry accounts for ~465 KB
   * uncompressed on every route, but that is the core SDK plus tracing
   * (`tracesSampleRate` is set deliberately), not removable extras — Session
   * Replay is already disabled in `instrumentation-client.ts` and verified
   * absent from the built chunks, so the `excludeReplay*` flags would have
   * nothing to remove and are not set.
   *
   * Kept because it is correct and costs nothing, not because it moved the
   * number. Reducing Sentry further means giving up tracing, which is a
   * product decision rather than a build-config one.
   */
  bundleSizeOptimizations: {
    excludeDebugStatements: true,
  },
});

/**
 * `withSentryConfig` cannot detect Next 16 and injects two keys that Next 14 needed
 * but Next 16 rejects, which makes every dev start and build print an
 * "Invalid next.config.ts options detected" warning. `instrumentation.ts` is loaded
 * by default now, so the keys are not just invalid — they are unnecessary.
 *
 * Revisit when @sentry/nextjs adds Next 16 detection; until then, drop them.
 */
if (withSentry.experimental) {
  delete (withSentry.experimental as Record<string, unknown>)
    .instrumentationHook;
  delete (withSentry.experimental as Record<string, unknown>)
    .serverComponentsExternalPackages;
}

export default withSentry;
