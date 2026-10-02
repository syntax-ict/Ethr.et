import { describe, it, expect } from "vitest";
import {
  birrTick,
  birrTooltip,
  dayKeyLabel,
  monthKeyLabel,
} from "@/app/(dashboard)/analytics/axis-format";

/**
 * Every analytics chart printed raw values (audit N35): money as `2800000`,
 * months as `2026-06`, days as `2026-09-14` whichever calendar was chosen.
 */
describe("analytics axis labels", () => {
  it("abbreviates birr on an axis", () => {
    expect(birrTick(2_800_000)).toBe("2.8M");
    expect(birrTick(700_000)).toBe("700K");
    expect(birrTick(0)).toBe("0");
  });

  it("shows the exact birr amount in a tooltip, as the rest of the app does", () => {
    expect(birrTooltip(2_524_580.63)).toBe("2,524,580.63 ETB");
    // cents / 100 is not always exact in binary; the tooltip must not show it.
    expect(birrTooltip(0.1 + 0.2)).toBe("0.30 ETB");
    expect(birrTooltip("n/a")).toBe("n/a");
  });

  it("names a month bucket", () => {
    expect(monthKeyLabel("2026-06")).toBe("June 2026");
    expect(monthKeyLabel("2027-01")).toBe("January 2027");
  });

  it("passes through a label that is not a month key", () => {
    // Payroll period labels are already names; forecasts of them too.
    expect(monthKeyLabel("July 2026")).toBe("July 2026");
    expect(monthKeyLabel("<1yr")).toBe("<1yr");
  });

  it("shows a day in the Ethiopian calendar when that is in force", () => {
    // 2026-09-11 is Meskerem 1, 2019 (Enkutatash).
    expect(dayKeyLabel("2026-09-11", "ethiopian")).toBe("Meskerem 1");
    expect(dayKeyLabel("2026-09-14", "ethiopian")).toBe("Meskerem 4");
  });

  it("shows a day in the Gregorian calendar when that is in force", () => {
    expect(dayKeyLabel("2026-09-14", "gregorian")).toBe("Sep 14");
  });

  it("is not shifted a day by the local time zone", () => {
    // Parsed as UTC parts, never via new Date("2026-01-01"), which a negative
    // offset would render as December 31.
    expect(dayKeyLabel("2026-01-01", "gregorian")).toBe("Jan 1");
    expect(monthKeyLabel("2026-01")).toBe("January 2026");
  });
});
