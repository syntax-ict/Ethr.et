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
import { formatEthiopian, toEthiopian } from "@/lib/calendar/ethiopian";

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
/** A translator: the key, and the English to use when it has none. */
type Translate = (
  key: string,
  fallback: string,
  replacements?: Record<string, string | number>,
) => string;

/** The fallback, with `:name` placeholders filled, longest name first. */
const untranslated: Translate = (_key, fallback, replacements = {}) =>
  Object.entries(replacements)
    .sort(([a], [b]) => b.length - a.length)
    .reduce(
      (out, [name, value]) => out.split(`:${name}`).join(String(value)),
      fallback,
    );

/**
 * "5 min ago", "3 h ago", "2 wk ago", then the date itself after 30 days.
 *
 * The one implementation: five screens kept their own copies, three of them
 * translated and two English-only, while this one was English-only too
 * (redundancy audit, 2026-10-09). `useDateFormatters().timeAgo` passes the
 * app's translator, so every caller gets Amharic; called without one it
 * returns English, unchanged.
 */
export function timeAgo(
  dateStr: string,
  timeZone: string = DEFAULT_TIMEZONE,
  t: Translate = untranslated,
  /** Renders the date shown past thirty days. `useDateFormatters` passes its calendar-aware one. */
  formatOld: (dateStr: string) => string = (d) => formatDate(d, timeZone),
): string {
  const diffMs = Date.now() - new Date(dateStr).getTime();
  const diffMin = Math.floor(diffMs / 60000);
  if (diffMin < 1) return t("time.just_now", "just now");
  // One sentence each: Amharic says "ከ5 ደቂቃ በፊት", which a number, a unit
  // letter and a separate "ago" glued in English order could not.
  if (diffMin < 60)
    return t("time.minutes_ago", ":count min ago", { count: diffMin });
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24)
    return t("time.hours_ago", ":count h ago", { count: diffHr });
  const diffDay = Math.floor(diffHr / 24);
  if (diffDay < 7)
    return t("time.days_ago", ":count d ago", { count: diffDay });
  if (diffDay < 30)
    return t("time.weeks_ago", ":count wk ago", {
      count: Math.floor(diffDay / 7),
    });
  return formatOld(dateStr);
}

/** Which calendar a date is written in. Mirrors `CalendarSystem` without importing React context here. */
export type DisplayCalendar = "ethiopian" | "gregorian";

/**
 * The year, month and day a value falls on, read in the zone that applies.
 *
 * A bare date has no time of day and is read as written (UTC), exactly as
 * `formatDate` does; an instant is read in the tenant's zone. Returns null for
 * a value that is not a date, so callers can fall back to the old rendering
 * rather than throw.
 */
export function calendarDayParts(
  dateStr: string,
  timeZone: string = DEFAULT_TIMEZONE,
): { year: number; month: number; day: number } | null {
  const date = new Date(dateStr);
  if (Number.isNaN(date.getTime())) return null;
  const parts = new Intl.DateTimeFormat("en-US", {
    year: "numeric",
    month: "numeric",
    day: "numeric",
    timeZone: isDateOnly(dateStr) ? "UTC" : resolveTimeZone(timeZone),
  }).formatToParts(date);
  const pick = (type: string) =>
    Number(parts.find((p) => p.type === type)?.value);
  return { year: pick("year"), month: pick("month"), day: pick("day") };
}

/**
 * A date in the reader's calendar and language.
 *
 * Until 2026-10-20 every `useDateFormatters()` caller (42 files) printed an
 * English Gregorian date whatever the person had chosen, while the 21 files on
 * `useCalendar().formatDate` honoured it, so one screen could show both
 * calendars side by side (FRONTEND_REDESIGN_PLAN.md, problem 3). Gregorian
 * in English keeps the old `formatDate` output exactly; Gregorian in Amharic
 * uses Amharic month names; Ethiopian uses the app's own month names, as the
 * calendar screens already do.
 */
export function formatCalendarDate(
  dateStr: string,
  timeZone: string,
  calendar: DisplayCalendar,
  locale: string,
): string {
  const day = calendarDayParts(dateStr, timeZone);
  if (!day) return formatDate(dateStr, timeZone);
  if (calendar === "ethiopian") {
    return formatEthiopian(toEthiopian(day.year, day.month, day.day), locale);
  }
  if (locale === "am") {
    return new Date(
      Date.UTC(day.year, day.month - 1, day.day),
    ).toLocaleDateString("am-ET", {
      day: "2-digit",
      month: "short",
      year: "numeric",
      timeZone: "UTC",
    });
  }
  return formatDate(dateStr, timeZone);
}

/** `formatCalendarDate` with the time of day in the tenant's zone, 24-hour. */
export function formatCalendarDateTime(
  dateStr: string,
  timeZone: string,
  calendar: DisplayCalendar,
  locale: string,
): string {
  if (calendar === "gregorian" && locale !== "am") {
    return formatDateTime(dateStr, timeZone);
  }
  if (!calendarDayParts(dateStr, timeZone))
    return formatDateTime(dateStr, timeZone);
  return `${formatCalendarDate(dateStr, timeZone, calendar, locale)}, ${formatTime(dateStr, timeZone)}`;
}
