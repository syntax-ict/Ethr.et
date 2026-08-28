import * as Sentry from "@sentry/nextjs";

import { scrubEvent } from "@/lib/observability/scrub";

/**
 * Server and edge runtime error reporting.
 *
 * No-ops unless NEXT_PUBLIC_SENTRY_DSN is set, so local development and CI stay
 * silent and no events are emitted from a machine with no Sentry to send them to.
 */
export function register() {
  const dsn = process.env.NEXT_PUBLIC_SENTRY_DSN;

  if (!dsn) return;

  if (
    process.env.NEXT_RUNTIME === "nodejs" ||
    process.env.NEXT_RUNTIME === "edge"
  ) {
    Sentry.init({
      dsn,
      environment: process.env.NEXT_PUBLIC_SENTRY_ENVIRONMENT ?? "production",
      // Tracing every request is expensive; sample and raise it deliberately.
      tracesSampleRate: Number(
        process.env.NEXT_PUBLIC_SENTRY_TRACES_SAMPLE_RATE ?? 0.1,
      ),
      // ETHR handles payroll and national IDs — PII is opted into, never default.
      sendDefaultPii: false,
      beforeSend: scrubEvent,
    });
  }
}

export const onRequestError = Sentry.captureRequestError;
