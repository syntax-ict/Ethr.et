import { describe, it, expect, vi, afterEach } from "vitest";
import {
  DEFAULT_TIMEZONE,
  resolveTimeZone,
  formatDate,
  formatDateTime,
  formatTime,
  timeAgo,
} from "@/lib/utils/date";

/**
 * Tenant-timezone-aware date rendering (convention #2, BASELINE §12g).
 *
 * Timestamps are stored and transported in UTC and displayed in the tenant's
 * configured zone, defaulting to `Africa/Addis_Ababa`. Until 2026-09-16 these
 * functions passed no `timeZone` at all and rendered in the *browser's* zone, so
 * a check-in stored as 05:30Z showed as 08:30 in Addis and 05:30 in UTC.
 *
 * `formatTime` is what the attendance page shows for a punch, so this is a wrong
 * clock time against an employee's shift, not a cosmetic difference.
 *
 * **Nothing here sets `process.env.TZ`.** These assertions have to hold on any
 * host — that is the requirement, not a test convenience. The machine this was
 * written on is `Africa/Nairobi` (UTC+3, the same offset as EAT) and CI runs
 * UTC; if a single assertion below depended on the host clock, one of the two
 * would fail.
 */
afterEach(() => {
  vi.useRealTimers();
});

describe("the tenant timezone default", () => {
  it("is Africa/Addis_Ababa, matching the tenants.timezone column default", () => {
    expect(DEFAULT_TIMEZONE).toBe("Africa/Addis_Ababa");
  });

  it("falls back to the default rather than throwing on an invalid zone", () => {
    // Intl.DateTimeFormat throws RangeError on an unknown timeZone. A stale or
    // mistyped value stored against a tenant would otherwise break every date on
    // every screen; a slightly wrong clock is recoverable, a crashed dashboard
    // is not.
    expect(resolveTimeZone("Mars/Olympus_Mons")).toBe(DEFAULT_TIMEZONE);
    expect(resolveTimeZone("")).toBe(DEFAULT_TIMEZONE);
    expect(resolveTimeZone(null)).toBe(DEFAULT_TIMEZONE);
    expect(resolveTimeZone(undefined)).toBe(DEFAULT_TIMEZONE);
  });

  it("keeps a valid IANA zone", () => {
    expect(resolveTimeZone("Europe/London")).toBe("Europe/London");
    expect(resolveTimeZone("Africa/Addis_Ababa")).toBe("Africa/Addis_Ababa");
  });
});

describe("formatTime", () => {
  it("renders a UTC punch in Addis time by default, not the host's", () => {
    // 05:30Z is 08:30 in Addis. This is the assertion the old implementation
    // could not make: it returned whatever the browser's zone produced.
    expect(formatTime("2026-09-16T05:30:00.000Z")).toBe("08:30");
  });

  it("respects a tenant configured in another zone", () => {
    expect(formatTime("2026-09-16T05:30:00.000Z", "UTC")).toBe("05:30");
    expect(formatTime("2026-09-16T05:30:00.000Z", "Europe/London")).toBe(
      "06:30",
    );
  });

  it("uses a 24-hour clock", () => {
    // 15:05Z is 18:05 in Addis — past noon, so a 12-hour clock would show 06:05.
    expect(formatTime("2026-09-16T15:05:00.000Z")).toBe("18:05");
  });

  it("handles a UTC instant that is the previous day in Addis", () => {
    // 22:00Z on the 15th is 01:00 on the 16th in Addis. The date rolls forward
    // with the zone, which is the whole point of converting rather than
    // truncating.
    expect(formatTime("2026-09-15T22:00:00.000Z")).toBe("01:00");
    expect(formatDate("2026-09-15T22:00:00.000Z")).toMatch(/^16 Sept? 2026$/);
  });
});

describe("daylight saving", () => {
  // Addis does not observe DST, but the tenant timezone is configurable and
  // other zones do. A hardcoded +03:00 offset would be wrong twice a year for
  // them; an IANA name is not.
  it("follows a DST-observing tenant zone across the transition", () => {
    // Europe/London: UTC+0 in January, UTC+1 in July. Same wall-clock input.
    expect(formatTime("2026-01-15T12:00:00.000Z", "Europe/London")).toBe(
      "12:00",
    );
    expect(formatTime("2026-07-15T12:00:00.000Z", "Europe/London")).toBe(
      "13:00",
    );
  });

  it("leaves a non-DST tenant zone unchanged across the same dates", () => {
    expect(formatTime("2026-01-15T12:00:00.000Z")).toBe("15:00");
    expect(formatTime("2026-07-15T12:00:00.000Z")).toBe("15:00");
  });
});

describe("formatDate", () => {
  // The month abbreviation is matched loosely on purpose. en-GB renders
  // September as "Sep" under older ICU and "Sept" under newer — Node 24 here
  // produces "Sept", CI pins Node 20. Asserting either literal would pass on one
  // runtime and fail on the other. Day, year and time are stable and exact.
  it("renders a UTC instant as a day-month-year date in the tenant zone", () => {
    expect(formatDate("2026-09-16T05:30:00.000Z")).toMatch(/^16 Sept? 2026$/);
  });

  it("accepts a bare date string", () => {
    expect(formatDate("2026-01-02")).toBe("02 Jan 2026");
  });

  it("respects a configured zone", () => {
    // 21:00Z on the 16th is still the 16th in UTC and already the 17th in Addis.
    expect(formatDate("2026-09-16T21:00:00.000Z", "UTC")).toMatch(
      /^16 Sept? 2026$/,
    );
    expect(formatDate("2026-09-16T21:00:00.000Z")).toMatch(/^17 Sept? 2026$/);
  });
});

describe("formatDateTime", () => {
  it("renders date and time together in the tenant zone", () => {
    expect(formatDateTime("2026-09-16T05:30:00.000Z")).toMatch(
      /^16 Sept? 2026, 08:30$/,
    );
  });
});

describe("timeAgo", () => {
  // The threshold ladder is the only branching logic and is zone-independent:
  // it works on a millisecond difference from now.
  function at(iso: string, now: string, timeZone?: string) {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(now));
    return timeZone ? timeAgo(iso, timeZone) : timeAgo(iso);
  }

  it("calls anything under a minute 'just now'", () => {
    expect(at("2026-09-16T12:00:00.000Z", "2026-09-16T12:00:59.000Z")).toBe(
      "just now",
    );
  });

  it("counts whole minutes up to an hour", () => {
    expect(at("2026-09-16T12:00:00.000Z", "2026-09-16T12:01:00.000Z")).toBe(
      "1m ago",
    );
    expect(at("2026-09-16T12:00:00.000Z", "2026-09-16T12:59:00.000Z")).toBe(
      "59m ago",
    );
  });

  it("switches to hours at exactly sixty minutes", () => {
    expect(at("2026-09-16T12:00:00.000Z", "2026-09-16T13:00:00.000Z")).toBe(
      "1h ago",
    );
    expect(at("2026-09-16T12:00:00.000Z", "2026-09-17T11:00:00.000Z")).toBe(
      "23h ago",
    );
  });

  it("switches to days at exactly twenty-four hours", () => {
    expect(at("2026-09-16T12:00:00.000Z", "2026-09-17T12:00:00.000Z")).toBe(
      "1d ago",
    );
    expect(at("2026-09-16T12:00:00.000Z", "2026-09-22T12:00:00.000Z")).toBe(
      "6d ago",
    );
  });

  it("switches to weeks at seven days", () => {
    expect(at("2026-09-16T12:00:00.000Z", "2026-09-23T12:00:00.000Z")).toBe(
      "1w ago",
    );
    expect(at("2026-09-16T12:00:00.000Z", "2026-10-13T12:00:00.000Z")).toBe(
      "3w ago",
    );
  });

  it("falls back to an absolute date beyond thirty days, in the tenant zone", () => {
    expect(at("2026-09-16T12:00:00.000Z", "2026-10-20T12:00:00.000Z")).toMatch(
      /^16 Sept? 2026$/,
    );
    // The fallback is the one part of timeAgo that renders a date, so it has to
    // carry the zone through like everything else.
    expect(
      at("2026-09-16T21:00:00.000Z", "2026-10-20T12:00:00.000Z", "UTC"),
    ).toMatch(/^16 Sept? 2026$/);
    expect(at("2026-09-16T21:00:00.000Z", "2026-10-20T12:00:00.000Z")).toMatch(
      /^17 Sept? 2026$/,
    );
  });

  it("does not render a future timestamp as an elapsed time", () => {
    // A clock-skewed device can produce a captured_at slightly in the future.
    // Every threshold fails on a negative difference and it reads "just now",
    // which is sane — pinned so a change to the ladder does not start emitting
    // "-3m ago".
    expect(at("2026-09-16T12:05:00.000Z", "2026-09-16T12:00:00.000Z")).toBe(
      "just now",
    );
  });
});
