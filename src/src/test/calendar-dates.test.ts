import { describe, it, expect } from "vitest";
import {
  calendarDayParts,
  formatCalendarDate,
  formatCalendarDateTime,
  formatDate,
  formatDateTime,
} from "@/lib/utils/date";

/**
 * `useDateFormatters()` printed English Gregorian dates in 42 files whatever
 * calendar the person had chosen (FRONTEND_REDESIGN_PLAN.md, problem 3). These
 * pin the pure formatter it now delegates to.
 */
const ADDIS = "Africa/Addis_Ababa";

describe("formatCalendarDate", () => {
  it("keeps the old English Gregorian output exactly", () => {
    for (const value of ["2026-10-20", "2026-10-20T09:15:00Z"]) {
      expect(formatCalendarDate(value, ADDIS, "gregorian", "en")).toBe(
        formatDate(value, ADDIS),
      );
    }
  });

  it("writes an Ethiopian date when that is the reader's calendar", () => {
    // 20 October 2026 is 10 Tikimt 2019 in the Ethiopian calendar.
    expect(formatCalendarDate("2026-10-20", ADDIS, "ethiopian", "en")).toBe(
      "Tikimt 10, 2019",
    );
  });

  it("uses Amharic month names in Amharic", () => {
    const am = formatCalendarDate("2026-10-20", ADDIS, "ethiopian", "am");
    expect(am).toMatch(/2019/);
    expect(am).not.toMatch(/Tikimt/);
  });

  it("reads a bare date as written, whatever the zone", () => {
    // A zone behind UTC used to move a bare date to the previous day.
    expect(calendarDayParts("2026-10-20", "America/New_York")).toEqual({
      year: 2026,
      month: 10,
      day: 20,
    });
  });

  it("reads an instant in the tenant's zone, so a late-evening UTC time is the next day in Addis", () => {
    // 22:30 UTC on 20 October is 01:30 on 21 October in Addis Ababa.
    expect(calendarDayParts("2026-10-20T22:30:00Z", ADDIS)).toEqual({
      year: 2026,
      month: 10,
      day: 21,
    });
    expect(
      formatCalendarDate("2026-10-20T22:30:00Z", ADDIS, "ethiopian", "en"),
    ).toBe("Tikimt 11, 2019");
  });

  it("falls back to the old rendering for a value that is not a date", () => {
    expect(formatCalendarDate("not a date", ADDIS, "ethiopian", "en")).toBe(
      formatDate("not a date", ADDIS),
    );
  });
});

describe("formatCalendarDateTime", () => {
  it("keeps the old English Gregorian output exactly", () => {
    const value = "2026-10-20T09:15:00Z";
    expect(formatCalendarDateTime(value, ADDIS, "gregorian", "en")).toBe(
      formatDateTime(value, ADDIS),
    );
  });

  it("puts the 24-hour time in the tenant's zone after an Ethiopian date", () => {
    expect(
      formatCalendarDateTime("2026-10-20T09:15:00Z", ADDIS, "ethiopian", "en"),
    ).toBe("Tikimt 10, 2019, 12:15");
  });
});
