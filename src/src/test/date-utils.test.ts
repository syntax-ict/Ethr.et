// Pin the timezone BEFORE importing the module under test.
//
// This is not tidiness. The machine these were written on is Africa/Nairobi —
// UTC+3, the same offset as EAT — so `formatTime` renders Ethiopian attendance
// times correctly here by luck of the host clock. A CI runner is UTC and renders
// the same punch three hours earlier. Tests that asserted clock output without
// pinning TZ would pass here and fail there, which is the precise failure mode
// audit/BASELINE.md §12d and §12c are about.
process.env.TZ = "UTC";

import { describe, it, expect, vi, afterEach } from "vitest";
import {
  formatDate,
  formatDateTime,
  formatTime,
  timeAgo,
} from "@/lib/utils/date";

/**
 * Date rendering for attendance and dashboards.
 *
 * `formatTime` is what the attendance page shows for a punch, so a mistake here
 * is a wrong clock time against an employee's shift, not a cosmetic one.
 *
 * These pin current behaviour, and current behaviour renders in the **host**
 * timezone — none of these functions passes a `timeZone`, and no `timeZone`
 * option exists anywhere in the frontend. Convention #2 says store UTC, display
 * EAT. See §12g: that divergence is recorded rather than silently "fixed" here,
 * because the product also has a configurable per-tenant timezone and choosing
 * between "always EAT" and "the tenant's zone" is a product decision.
 */
afterEach(() => {
  vi.useRealTimers();
});

describe("formatDate", () => {
  // The month abbreviation is matched loosely on purpose. en-GB renders
  // September as "Sep" under older ICU and "Sept" under newer — Node 24 here
  // produces "Sept", and CI pins Node 20. Asserting either literal would make
  // the test pass on one runtime and fail on the other, which is the same
  // environment-dependence these tests exist to avoid. The day, year and time
  // are stable and are asserted exactly.
  it("renders a UTC instant as a day-month-year date", () => {
    expect(formatDate("2026-09-16T05:30:00.000Z")).toMatch(/^16 Sept? 2026$/);
  });

  it("accepts a bare date string", () => {
    expect(formatDate("2026-01-02")).toBe("02 Jan 2026");
  });
});

describe("formatTime", () => {
  it("renders a UTC instant in 24-hour time, in the host timezone", () => {
    // TZ is pinned to UTC above, so this is the raw instant. On a machine set to
    // Africa/Addis_Ababa the same input renders "08:30" — that difference is the
    // point of §12g.
    expect(formatTime("2026-09-16T05:30:00.000Z")).toBe("05:30");
  });

  it("does not use a 12-hour clock", () => {
    expect(formatTime("2026-09-16T18:05:00.000Z")).toBe("18:05");
  });

  it("keeps midnight as 00:00 rather than 24:00", () => {
    // en-GB with hour12:false has historically produced "24:00" for midnight in
    // some engines. Pinned so a runtime change is caught rather than shipped.
    expect(formatTime("2026-09-16T00:00:00.000Z")).toBe("00:00");
  });
});

describe("formatDateTime", () => {
  it("renders date and time together", () => {
    expect(formatDateTime("2026-09-16T05:30:00.000Z")).toMatch(
      /^16 Sept? 2026, 05:30$/,
    );
  });
});

describe("timeAgo", () => {
  // The only branching logic in the module, and entirely timezone-independent:
  // it works on a millisecond difference from now.
  function at(iso: string, now: string) {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(now));
    return timeAgo(iso);
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

  it("falls back to an absolute date beyond thirty days", () => {
    // Past a month "5w ago" stops being useful, so the ladder ends and the real
    // date is shown instead.
    expect(at("2026-09-16T12:00:00.000Z", "2026-10-20T12:00:00.000Z")).toMatch(
      /^16 Sept? 2026$/,
    );
  });

  it("does not render a future timestamp as an elapsed time", () => {
    // A clock-skewed device can produce a captured_at slightly in the future.
    // The difference is negative, every threshold fails, and it reads
    // "just now" — which is the sane outcome, but it is worth pinning so a
    // change to the ladder does not start emitting "-3m ago".
    expect(at("2026-09-16T12:05:00.000Z", "2026-09-16T12:00:00.000Z")).toBe(
      "just now",
    );
  });
});
