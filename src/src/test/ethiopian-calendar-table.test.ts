import { describe, expect, it } from "vitest";
import {
  daysInEthiopianMonth,
  isEthiopianLeapYear,
  toEthiopian,
  toGregorian,
} from "@/lib/calendar/ethiopian";
import table from "./fixtures/ethiopian-calendar.json";

/*
 * The TypeScript calendar against the table shared with the PHP test
 * (api/tests/Accuracy/Calendar/EthiopianCalendarTableTest.php). The table is
 * counted day by day by an implementation that shares no code with either; see
 * docs/production testing/14-LEAVE-CALENDAR-AND-RESTATED-FIGURES.md.
 */

interface YearRow {
  months: string[];
  pagume_days: number;
}

const years = table.years as unknown as Record<string, YearRow>;

function parts(date: string): [number, number, number] {
  const [y, m, d] = date.split("-").map(Number);
  return [y, m, d];
}

/** The date `days` after `date`, by UTC arithmetic so no time zone can move it. */
function addDays(date: string, days: number): string {
  const [y, m, d] = parts(date);
  const moved = new Date(Date.UTC(2000, 0, 1));
  moved.setUTCFullYear(y, m - 1, d + days);
  return `${String(moved.getUTCFullYear()).padStart(4, "0")}-${String(moved.getUTCMonth() + 1).padStart(2, "0")}-${String(moved.getUTCDate()).padStart(2, "0")}`;
}

function local(date: Date): string {
  return `${String(date.getFullYear()).padStart(4, "0")}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

describe("the table", () => {
  it("covers Ethiopian years 1892 to 2093", () => {
    const keys = Object.keys(years).map(Number);
    expect(keys).toHaveLength(202);
    expect(Math.min(...keys)).toBe(1892);
    expect(Math.max(...keys)).toBe(2093);
  });
});

describe("month starts", () => {
  it("toGregorian, toEthiopian, the day before, and Pagume agree for every month of every year", () => {
    const bad: string[] = [];
    let compared = 0;

    for (const [key, row] of Object.entries(years)) {
      const year = Number(key);
      if (isEthiopianLeapYear(year) !== (row.pagume_days === 6)) {
        bad.push(`${year}: isEthiopianLeapYear`);
      }
      if (daysInEthiopianMonth(year, 13) !== row.pagume_days) {
        bad.push(`${year}: daysInEthiopianMonth(13)`);
      }

      row.months.forEach((start, i) => {
        const month = i + 1;
        compared++;
        if (
          daysInEthiopianMonth(year, month) !==
          (month < 13 ? 30 : row.pagume_days)
        ) {
          bad.push(`${year}-${month}: month length`);
        }
        const got = local(toGregorian(year, month, 1));
        if (got !== start)
          bad.push(`${year}-${month}-1: ts ${got}, table ${start}`);

        const [gy, gm, gd] = parts(start);
        const eth = toEthiopian(gy, gm, gd);
        if (eth.year !== year || eth.month !== month || eth.day !== 1) {
          bad.push(
            `${start}: ts reads ${eth.year}-${eth.month}-${eth.day}, table ${year}-${month}-1`,
          );
        }

        if (year === 1892 && month === 1) return;
        const before =
          month > 1
            ? [year, month - 1, 30]
            : [year - 1, 13, years[String(year - 1)].pagume_days];
        const [by, bm, bd] = parts(addDays(start, -1));
        const dayBefore = toEthiopian(by, bm, bd);
        if (
          dayBefore.year !== before[0] ||
          dayBefore.month !== before[1] ||
          dayBefore.day !== before[2]
        ) {
          bad.push(
            `${addDays(start, -1)}: ts reads ${dayBefore.year}-${dayBefore.month}-${dayBefore.day}, expected ${before.join("-")}`,
          );
        }
      });
    }

    expect(bad).toEqual([]);
    expect(compared).toBe(202 * 13);
  });
});

describe("every day", () => {
  it("converts both ways, and each next day is the next Ethiopian date", () => {
    const bad: string[] = [];
    let days = 0;
    let previous: string | null = null;

    outer: for (const [key, row] of Object.entries(years)) {
      const year = Number(key);
      for (let i = 0; i < row.months.length; i++) {
        const month = i + 1;
        const length = month < 13 ? 30 : row.pagume_days;
        let date = row.months[i];
        for (let day = 1; day <= length; day++) {
          days++;
          if (previous !== null && addDays(date, -1) !== previous) {
            bad.push(`${date}: does not follow ${previous}`);
          }
          const [gy, gm, gd] = parts(date);
          const eth = toEthiopian(gy, gm, gd);
          if (eth.year !== year || eth.month !== month || eth.day !== day) {
            bad.push(
              `${date}: ts reads ${eth.year}-${eth.month}-${eth.day}, table ${year}-${month}-${day}`,
            );
          }
          const back = local(toGregorian(year, month, day));
          if (back !== date)
            bad.push(
              `${year}-${month}-${day}: ts gives ${back}, table ${date}`,
            );
          previous = date;
          date = addDays(date, 1);
          if (bad.length > 20) break outer;
        }
      }
    }

    expect(bad).toEqual([]);
    expect(days).toBeGreaterThanOrEqual(73414); // 1900-01-01 to 2100-12-31, both included;
  });
});
