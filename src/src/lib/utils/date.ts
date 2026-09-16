/**
 * Tenant-timezone-aware date rendering.
 *
 * Convention #2: timestamps are stored and transported in UTC, and displayed in
 * the tenant's configured timezone. `Africa/Addis_Ababa` is the default when a
 * tenant has not set one — it is the default on `tenants.timezone` in the
 * database too, so the two agree.
 *
 * Until 2026-09-16 none of these functions passed a `timeZone` at all, so every
 * timestamp in the product rendered in whatever zone the *browser* happened to
 * be in. A check-in stored as 05:30Z showed as 08:30 in Addis and 05:30 in UTC,
 * for the same employee on the same shift. It went unnoticed because users are
 * in Ethiopia and their browsers agreed with the intent. See
 * `docs/audit/BASELINE.md` §12g.
 *
 * ## Why a parameter with a default, rather than a context
 *
 * These stay pure functions. The default makes every existing call site correct
 * without being touched, and a caller that knows the tenant — via
 * `useTenantTimezone()` or `useDateFormatters()` — passes it explicitly. Nothing
 * needs a provider, and nothing silently falls back to the host clock.
 *
 * ## No fixed offset
 *
 * The zone is an IANA name, never `+03:00`. Addis does not observe DST, but the
 * tenant timezone is configurable and other zones do; an offset would be wrong
 * twice a year for them and would need changing if Ethiopia ever adopted one.
 */
export const DEFAULT_TIMEZONE = "Africa/Addis_Ababa";

/**
 * A tenant timezone that `Intl` will accept, or the default.
 *
 * `Intl.DateTimeFormat` throws `RangeError` on an unknown `timeZone`, so an
 * invalid or stale value stored against a tenant would otherwise break every
 * date on every screen. Falling back is the right failure: a slightly wrong
 * clock is recoverable, a crashed dashboard is not.
 */
export function resolveTimeZone(timeZone?: string | null): string {
  if (!timeZone) return DEFAULT_TIMEZONE;

  try {
    new Intl.DateTimeFormat("en-GB", { timeZone });
    return timeZone;
  } catch {
    return DEFAULT_TIMEZONE;
  }
}

/**
 * Whether a value denotes a calendar date rather than an instant.
 *
 * `2026-10-01` is a date. `2026-10-01T05:30:00Z` is a moment that happens to
 * fall on it. The difference decides whether a timezone may be applied at all:
 * converting a calendar date shifts it, and a hire date that reads 30 Sep for
 * one viewer and 01 Oct for another is simply wrong — neither rendering is
 * "in their timezone", because the value never had a time of day to convert.
 */
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

export function isDateOnly(value: string): boolean {
  return DATE_ONLY.test(value.trim());
}

/**
 * A calendar date, rendered exactly as written.
 *
 * Formatting in UTC is the mechanism, not the intent: `new Date("2026-10-01")`
 * and `new Date("2026-10-01T00:00:00.000000Z")` are both UTC midnight, so
 * reading them back in UTC returns the date on the wire whatever zone the
 * tenant or the host is in. Laravel's `date` cast serialises as the second
 * form — `Invoice::$casts` has `due_date => date` — so both shapes arrive.
 *
 * Use this for values that are dates: due dates, hire dates, a day on a chart
 * axis. Use `formatDate` for instants.
 */
export function formatDateOnly(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    timeZone: "UTC",
  });
}

/** The weekday of a calendar date, on the same terms as `formatDateOnly`. */
export function formatWeekday(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString("en-GB", {
    weekday: "short",
    timeZone: "UTC",
  });
}

export function formatDate(
  dateStr: string,
  timeZone: string = DEFAULT_TIMEZONE,
): string {
  return new Date(dateStr).toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    // A bare `YYYY-MM-DD` carries no time of day, so there is nothing to
    // convert — and converting it into a zone behind UTC moves it to the
    // previous day. Ethiopian tenants are at UTC+3 and would never have seen
    // that, which is exactly why it would have sat here unnoticed.
    timeZone: isDateOnly(dateStr) ? "UTC" : resolveTimeZone(timeZone),
  });
}

export function formatDateTime(
  dateStr: string,
  timeZone: string = DEFAULT_TIMEZONE,
): string {
  return new Date(dateStr).toLocaleString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: resolveTimeZone(timeZone),
  });
}

export function formatTime(
  dateStr: string,
  timeZone: string = DEFAULT_TIMEZONE,
): string {
  return new Date(dateStr).toLocaleTimeString("en-GB", {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
    timeZone: resolveTimeZone(timeZone),
  });
}

/**
 * Elapsed time, which is zone-independent until it gives up and shows a date.
 *
 * The thresholds work on a millisecond difference, so no zone is involved. Only
 * the fallback past thirty days renders an actual date, and that one needs the
 * tenant zone like everything else.
 */
export function timeAgo(
  dateStr: string,
  timeZone: string = DEFAULT_TIMEZONE,
): string {
  const diffMs = Date.now() - new Date(dateStr).getTime();
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return "just now";
  if (diffMin < 60) return `${diffMin}m ago`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h ago`;
  const diffDay = Math.floor(diffHr / 24);
  if (diffDay < 7) return `${diffDay}d ago`;
  if (diffDay < 30) return `${Math.floor(diffDay / 7)}w ago`;
  return formatDate(dateStr, timeZone);
}
