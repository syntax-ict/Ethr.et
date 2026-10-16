import { describe, expect, it } from "vitest";
import { localizedPeriodLabel } from "@/lib/calendar/period-label";

/**
 * The stored payroll `period_label` is written once in English Gregorian
 * ("September 2026"), so Amharic and Ethiopian-calendar screens showed a
 * foreign label for a period their users read as Meskerem. The display label
 * is derived from the period's midpoint: an Ethiopian-cut period (11 Sep to
 * 10 Oct) and a Gregorian-cut one (1 to 30 Sep) both land in the month the
 * period means, where the start date would name ጳጉሜ for the latter.
 */
describe("localizedPeriodLabel", () => {
  const ethiopianCut = ["2026-09-11", "2026-10-10"] as const;

  it("names the Ethiopian month for an Ethiopian-calendar viewer", () => {
    expect(
      localizedPeriodLabel(
        "September 2026",
        ...ethiopianCut,
        "ethiopian",
        "am",
      ),
    ).toBe("መስከረም 2019");
    expect(
      localizedPeriodLabel(
        "September 2026",
        ...ethiopianCut,
        "ethiopian",
        "en",
      ),
    ).toBe("Meskerem 2019");
  });

  it("keeps the stored label for English Gregorian, so display and filename agree", () => {
    expect(
      localizedPeriodLabel(
        "September 2026",
        ...ethiopianCut,
        "gregorian",
        "en",
      ),
    ).toBe("September 2026");
  });

  it("localizes the Gregorian month for Amharic", () => {
    expect(
      localizedPeriodLabel(
        "September 2026",
        ...ethiopianCut,
        "gregorian",
        "am",
      ),
    ).toBe("ሴፕቴምበር 2026");
  });

  it("uses the midpoint, so a Gregorian-cut September is still Meskerem", () => {
    // The start date, 1 September, falls in the closing days of the previous
    // Ethiopian year; naming the period by it would say ጳጉሜ 2018.
    expect(
      localizedPeriodLabel(
        "September 2026",
        "2026-09-01",
        "2026-09-30",
        "ethiopian",
        "en",
      ),
    ).toBe("Meskerem 2019");
  });

  it("falls back to the stored label when the period has no dates", () => {
    expect(
      localizedPeriodLabel("September 2026", null, null, "ethiopian", "am"),
    ).toBe("September 2026");
    expect(localizedPeriodLabel(null, null, null, "ethiopian", "am")).toBe("");
  });
});
