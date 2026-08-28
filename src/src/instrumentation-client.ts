import * as Sentry from "@sentry/nextjs";

import { scrubEvent } from "@/lib/observability/scrub";

/**
 * Browser error reporting.
 *
 * No-ops unless NEXT_PUBLIC_SENTRY_DSN is set. Note that the DSN's host must also be
 * allowed by `connect-src` in the CSP (see next.config.ts) or the browser silently
 * blocks every event.
 *
 * The import is deliberately static, not a `await import()` guarded on the DSN.
 * Deferring it would only shrink builds that have no DSN — local dev and CI, where
 * bundle size is irrelevant. In production the DSN is set, so the module loads either
 * way; the only difference is that a lazy load arrives *after* hydration and misses
 * exactly the pageload-time errors Sentry is here to catch. If bundle weight on
 * Ethiopian networks becomes the binding constraint, cut it with `tracesSampleRate`
 * and the already-disabled Replay integration before making load-time errors invisible.
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

export const onRouterTransitionStart = Sentry.captureRouterTransitionStart;
