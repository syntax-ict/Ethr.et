import { describe, it, expect } from "vitest";
import {
  toEthiopian,
  toGregorian,
  formatEthiopian,
  formatEthiopianMonthYear,
  todayEthiopian,
  isEthiopianLeapYear,
  daysInEthiopianMonth,
  nextEthiopianMonth,
  prevEthiopianMonth,
  ETHIOPIAN_MONTHS,
  ETHIOPIAN_MONTHS_AM,
} from "@/lib/calendar/ethiopian";

// Reference pairs verified via Julian Day Number, matching backend EthiopianCalendarTest
const REFERENCE_PAIRS: Array<{
  label: string;
  greg: [number, number, number];
  eth: [number, number, number];
}> = [
  { label: "New Year 2017 EC", greg: [2024, 9, 11], eth: [2017, 1, 1] },
  {
    label: "New Year 2016 EC (post-leap, Sept 12)",
    greg: [2023, 9, 12],
    eth: [2016, 1, 1],
  },
  { label: "New Year 2015 EC", greg: [2022, 9, 11], eth: [2015, 1, 1] },
  { label: "Meskel 2017 EC", greg: [2024, 9, 27], eth: [2017, 1, 17] },
  {
    label: "Genna (Tahsas 29) 2017 EC",
    greg: [2025, 1, 7],
    eth: [2017, 4, 29],
  },
  { label: "Pagume 1, 2015 EC (leap)", greg: [2023, 9, 6], eth: [2015, 13, 1] },
  {
    label: "Pagume 6, 2015 EC (leap, last day)",
    greg: [2023, 9, 11],
    eth: [2015, 13, 6],
  },
  {
    label: "Pagume 5, 2016 EC (common, last day)",
    greg: [2024, 9, 10],
    eth: [2016, 13, 5],
  },
  { label: "mid-Sene 2016 EC", greg: [2024, 6, 15], eth: [2016, 10, 8] },
  {
    label: "Jan 1 2024 = Tahsas 22, 2016",
    greg: [2024, 1, 1],
    eth: [2016, 4, 22],
  },
  {
    label: "Feb 29 2020 (Gregorian leap day)",
    greg: [2020, 2, 29],
    eth: [2012, 6, 21],
  },
];

describe("toEthiopian — reference pairs", () => {
  for (const { label, greg, eth } of REFERENCE_PAIRS) {
    it(label, () => {
      const result = toEthiopian(greg[0], greg[1], greg[2]);
      expect(result).toEqual({ year: eth[0], month: eth[1], day: eth[2] });
    });
  }
});

describe("toGregorian — reference pairs", () => {
  for (const { label, greg, eth } of REFERENCE_PAIRS) {
    it(label, () => {
      const date = toGregorian(eth[0], eth[1], eth[2]);
      expect(date.getFullYear()).toBe(greg[0]);
      expect(date.getMonth() + 1).toBe(greg[1]);
      expect(date.getDate()).toBe(greg[2]);
    });
  }
});

describe("round-trip identity", () => {
  it("Gregorian -> Ethiopian -> Gregorian for many dates", () => {
    const testDates: Array<[number, number, number]> = [
      [2024, 3, 15],
      [2023, 12, 25],
      [2024, 9, 11],
      [2024, 1, 1],
      [2020, 2, 29],
      [2025, 7, 23],
      [2023, 9, 6],
      [2023, 9, 11],
      [2023, 9, 12],
      [2022, 9, 11],
      [2000, 6, 15],
    ];

    for (const [y, m, d] of testDates) {
      const eth = toEthiopian(y, m, d);
      const back = toGregorian(eth.year, eth.month, eth.day);
      expect(back.getFullYear()).toBe(y);
      expect(back.getMonth() + 1).toBe(m);
      expect(back.getDate()).toBe(d);
    }
  });

  it("Ethiopian -> Gregorian -> Ethiopian including Pagume", () => {
    for (const year of [2014, 2015, 2016, 2017]) {
      for (let month = 1; month <= 13; month++) {
        const maxDay = daysInEthiopianMonth(year, month);
        for (const day of [1, maxDay]) {
          const greg = toGregorian(year, month, day);
          const back = toEthiopian(
            greg.getFullYear(),
            greg.getMonth() + 1,
            greg.getDate(),
          );
          expect(back).toEqual({ year, month, day });
        }
      }
    }
  });
});

describe("formatEthiopian", () => {
  it("formats in English by default", () => {
    expect(formatEthiopian({ year: 2017, month: 1, day: 15 })).toBe(
      "Meskerem 15, 2017",
    );
  });

  it("formats in Amharic", () => {
    expect(formatEthiopian({ year: 2017, month: 1, day: 15 }, "am")).toBe(
      "መስከረም 15, 2017",
    );
  });

  it("formats Pagume correctly", () => {
    expect(formatEthiopian({ year: 2016, month: 13, day: 5 })).toBe(
      "Pagume 5, 2016",
    );
    expect(formatEthiopian({ year: 2016, month: 13, day: 5 }, "am")).toBe(
      "ጳጉሜ 5, 2016",
    );
  });

  it("renders all 13 month names in both locales", () => {
    for (let m = 1; m <= 13; m++) {
      const en = formatEthiopian({ year: 2017, month: m, day: 1 });
      expect(en).toContain(ETHIOPIAN_MONTHS[m - 1]);
      const am = formatEthiopian({ year: 2017, month: m, day: 1 }, "am");
      expect(am).toContain(ETHIOPIAN_MONTHS_AM[m - 1]);
    }
  });
});

describe("formatEthiopianMonthYear", () => {
  it("formats month and year without day", () => {
    expect(formatEthiopianMonthYear(2017, 1)).toBe("Meskerem 2017");
    expect(formatEthiopianMonthYear(2017, 13, "am")).toBe("ጳጉሜ 2017");
  });
});

describe("isEthiopianLeapYear", () => {
  it("year % 4 === 3 is a leap year", () => {
    expect(isEthiopianLeapYear(2015)).toBe(true);
    expect(isEthiopianLeapYear(2019)).toBe(true);
    expect(isEthiopianLeapYear(2011)).toBe(true);
  });

  it("non-leap years", () => {
    expect(isEthiopianLeapYear(2016)).toBe(false);
    expect(isEthiopianLeapYear(2017)).toBe(false);
    expect(isEthiopianLeapYear(2018)).toBe(false);
  });
});

describe("daysInEthiopianMonth", () => {
  it("returns 30 for months 1-12", () => {
    for (let m = 1; m <= 12; m++) {
      expect(daysInEthiopianMonth(2017, m)).toBe(30);
    }
  });

  it("returns 5 for Pagume in a common year", () => {
    expect(daysInEthiopianMonth(2016, 13)).toBe(5);
  });

  it("returns 6 for Pagume in a leap year", () => {
    expect(daysInEthiopianMonth(2015, 13)).toBe(6);
  });
});

describe("nextEthiopianMonth / prevEthiopianMonth", () => {
  it("advances within a year", () => {
    expect(nextEthiopianMonth(2017, 1)).toEqual({ year: 2017, month: 2 });
    expect(nextEthiopianMonth(2017, 12)).toEqual({ year: 2017, month: 13 });
  });

  it("wraps to next year from month 13", () => {
    expect(nextEthiopianMonth(2017, 13)).toEqual({ year: 2018, month: 1 });
  });

  it("goes back within a year", () => {
    expect(prevEthiopianMonth(2017, 5)).toEqual({ year: 2017, month: 4 });
    expect(prevEthiopianMonth(2017, 13)).toEqual({ year: 2017, month: 12 });
  });

  it("wraps to previous year from month 1", () => {
    expect(prevEthiopianMonth(2017, 1)).toEqual({ year: 2016, month: 13 });
  });
});

describe("todayEthiopian", () => {
  it("returns a valid Ethiopian date", () => {
    const result = todayEthiopian();
    expect(result.year).toBeGreaterThan(2010);
    expect(result.month).toBeGreaterThanOrEqual(1);
    expect(result.month).toBeLessThanOrEqual(13);
    expect(result.day).toBeGreaterThanOrEqual(1);
    expect(result.day).toBeLessThanOrEqual(30);
  });
});
