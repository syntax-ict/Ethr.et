import type { ErrorEvent, EventHint } from "@sentry/nextjs";

/**
 * Strips credentials and encrypted-at-rest fields out of an event before it leaves
 * the browser or the Node server.
 *
 * Mirrors `App\Services\Observability\SentryScrubber` on the backend deliberately —
 * the same field never leaks from either side. ETHR encrypts bank account numbers,
 * TINs and national IDs at rest; an unscrubbed error report would ship them in
 * plaintext to a third-party system, outside the audit log.
 */

const REDACTED = "[redacted]";

/** Whole-key matches, compared lower-case. */
const REDACT_KEYS = new Set([
  "password",
  "password_confirmation",
  "current_password",
  "new_password",
  "account_number",
  "bank_account",
  "national_id",
  "national_id_hash",
  "tin",
  "tin_number",
  "totp_secret",
  "two_factor_secret",
  "recovery_codes",
  "secret",
  "token",
  "access_token",
  "refresh_token",
  "authorization",
  "cookie",
  "x-xsrf-token",
]);

/** Substrings that make a key sensitive wherever they appear. */
const REDACT_FRAGMENTS = [
  "password",
  "secret",
  "token",
  "national_id",
  "account_number",
];

function isSensitive(key: string): boolean {
  const normalized = key.toLowerCase();

  if (REDACT_KEYS.has(normalized)) return true;

  return REDACT_FRAGMENTS.some((fragment) => normalized.includes(fragment));
}

function redact<T>(value: T, seen = new WeakSet<object>()): T {
  if (Array.isArray(value)) {
    return value.map((item) => redact(item, seen)) as T;
  }

  if (value === null || typeof value !== "object") {
    return value;
  }

  // Sentry events can carry cyclic references; bail rather than recurse forever.
  if (seen.has(value as object)) return value;
  seen.add(value as object);

  const result: Record<string, unknown> = {};

  for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
    result[key] = isSensitive(key) ? REDACTED : redact(item, seen);
  }

  return result as T;
}

export function scrubEvent(
  event: ErrorEvent,
  _hint?: EventHint,
): ErrorEvent | null {
  if (event.request) {
    event.request = redact(event.request);
  }

  if (event.extra) {
    event.extra = redact(event.extra);
  }

  if (event.contexts) {
    event.contexts = redact(event.contexts);
  }

  return event;
}
