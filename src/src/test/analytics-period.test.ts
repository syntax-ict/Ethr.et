import { describe, expect, it } from "vitest";
import {
  monthLabel,
  periodFor,
  todayIn,
} from "@/app/(dashboard)/analytics/period";

describe("analytics periods", () => {
  it("runs whole Gregorian months and compares with the months before", () => {
    expect(periodFor("this_month", "gregorian", "2026-10-30")).toMatchObject({
      current: { from: "2026-10-01", to: "2026-10-31" },
      previous: { from: "2026-09-01", to: "2026-09-30" },
      complete: false,
    });
    expect(periodFor("last_month", "gregorian", "2026-03-31")).toMatchObject({
      current: { from: "2026-02-01", to: "2026-02-28" },
      previous: { from: "2026-01-01", to: "2026-01-31" },
      complete: true,
    });
    expect(periodFor("last_3_months", "gregorian", "2026-01-15")).toMatchObject(
      {
        current: { from: "2025-11-01", to: "2026-01-31" },
        previous: { from: "2025-08-01", to: "2025-10-31" },
      },
    );
    expect(
      periodFor("last_12_months", "gregorian", "2026-10-30"),
    ).toMatchObject({
      current: { from: "2025-11-01", to: "2026-10-31" },
      previous: { from: "2024-11-01", to: "2025-10-31" },
    });
  });

  it("runs Ethiopian months, Pagume included", () => {
    // 30 October 2026 is Tikimt 20, 2019.
    expect(periodFor("this_month", "ethiopian", "2026-10-30")).toMatchObject({
      current: { from: "2026-10-11", to: "2026-11-09" },
      previous: { from: "2026-09-11", to: "2026-10-10" },
      first: { year: 2019, month: 2 },
    });
    // The month before Meskerem is Pagume, five or six days long.
    expect(periodFor("last_month", "ethiopian", "2026-09-20")).toMatchObject({
      first: { year: 2018, month: 13 },
      current: { from: "2026-09-06", to: "2026-09-10" },
    });
  });

  it("names months in the viewer's calendar", () => {
    expect(monthLabel({ year: 2019, month: 2 }, "ethiopian", "en")).toBe(
      "Tikimt 2019",
    );
    expect(monthLabel({ year: 2026, month: 10 }, "gregorian", "en")).toBe(
      "October 2026",
    );
  });

  it("takes today from the organisation's zone, not the browser's", () => {
    const lateEvening = new Date("2026-10-30T22:30:00Z");
    expect(todayIn("Africa/Addis_Ababa", lateEvening)).toBe("2026-10-31");
    expect(todayIn("UTC", lateEvening)).toBe("2026-10-30");
  });
});
