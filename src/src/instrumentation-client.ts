import * as Sentry from "@sentry/nextjs";

import { scrubEvent } from "@/lib/observability/scrub";

/**
 * Browser error reporting.
 *
 * No-ops unless NEXT_PUBLIC_SENTRY_DSN is set. Note that the DSN's host must also be
 * allowed by `connect-src` in the CSP (see next.config.ts) or the browser silently
 * blocks every event.
 *
 * The import is deliberately static, not a `await import()` guarded on the DSN: a
 * lazy load arrives *after* hydration and misses exactly the pageload-time errors
 * Sentry is here to catch.
 *
 * Every call is guarded on the DSN instead, and that is what keeps the SDK out of a
 * build without one. `NEXT_PUBLIC_*` is written into the bundle when it is built, so
 * an unset DSN makes every guard constant-false, nothing calls Sentry, and the
 * bundler drops the whole SDK (`sideEffects: false` throughout `@sentry/*`). This
 * docblock used to say "in production the DSN is set". It is not: the release build
 * has never had one, so the SDK shipped on every page to every visitor and reported
 * nothing (2026-10-30). A build with a DSN is unchanged, static import included.
 * Any new Sentry call in browser code needs the same guard, which
 * `sentry-weight.test.ts` checks.
 */
const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN;

if (dsn) {
  Sentry.init({
    dsn,
    environment: process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT ?? "production",
    tracesSampleRate: Number(
      process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? 0.1,
    ),
    // Session Replay is off: it would record payroll figures and employee records.
    replaysSessionSampleRate: 0,
    replaysOnErrorSampleRate: 0,
    sendDefaultPii: false,
    beforeSend: scrubEvent,
  });
}

export function onRouterTransitionStart(
  ...args: Parameters<typeof Sentry.captureRouterTransitionStart>
): void {
  if (process.env.NEXT_PUBLIC_SENTRY_DSN) {
    Sentry.captureRouterTransitionStart(...args);
  }
}
