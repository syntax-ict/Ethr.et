import type { NextConfig } from "next";
import bundleAnalyzer from "@next/bundle-analyzer";
import { withSentryConfig } from "@sentry/nextjs";

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
  output: "standalone",
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
              // nothing, and the alternative (uploading logos to MinIO) needs
              // every one of those consumers to resolve a storage key to a URL
              // first. 127.0.0.1:9000 stays for local MinIO over plain http.
              "img-src 'self' data: blob: https: http://127.0.0.1:9000",
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
  images: {
    remotePatterns: [
      {
        protocol: "http",
        hostname: "127.0.0.1",
        port: "9000",
        pathname: "/ethr/**",
      },
    ],
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
