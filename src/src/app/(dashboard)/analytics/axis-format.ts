import {
  ethiopianMonths,
  formatEthiopianMonthYear,
  toEthiopian,
} from "@/lib/calendar/ethiopian";
import type { CalendarSystem } from "@/lib/calendar/calendar-context";
import { formatETB } from "@/lib/utils/currency";

/**
 * Axis and tooltip labels for the analytics charts (audit N35).
 *
 * Recharts prints whatever the data holds, and every chart here handed it raw
 * values: money as `2800000` with no grouping or currency, months as the API's
 * `2026-06` bucket keys, days as `2026-09-14` whatever calendar was chosen.
 */

const MONTH_KEY = /^(\d{4})-(\d{2})$/;
const ETHIOPIAN_MONTH_KEY = /^(\d{4})-(\d{2})-EC$/;
const DAY_KEY = /^(\d{4})-(\d{2})-(\d{2})$/;

const compact = new Intl.NumberFormat("en", {
  notation: "compact",
  maximumFractionDigits: 1,
});

/** A birr amount on an axis: `2.8M`, `700K`. The tooltip carries the exact figure. */
export function birrTick(value: number): string {
  return compact.format(value);
}

/** A birr amount in a tooltip, as everywhere else in the app: `335,912.34 ETB`. */
export function birrTooltip(value: unknown): string {
  return typeof value === "number"
    ? formatETB(Math.round(value * 100))
    : String(value);
}

/**
 * A month bucket as its month name: `2026-06` is `June 2026`, and `2019-02-EC`
 * is `Tikimt 2019`.
 *
 * Each bucket is named in its own calendar, whichever the reader chose: one
 * Gregorian month spans two Ethiopian ones and the other way round, so naming
 * a bucket in the other calendar would misstate it. The hires buckets follow
 * the organisation's calendar (`OrganisationMonth`, since 2026-11-03), the
 * headcount buckets are Gregorian, and payroll periods are already names
 * ("July 2026"). Anything else passes through unchanged.
 *
 * `short` is for axis ticks: six "September 2026"s do not fit under a half-
 * width chart, so Recharts silently dropped some, and a month with no hires
 * looked like a month missing from the data (QA 2026-10-13). Tooltips keep
 * the long form.
 */
export function monthKeyLabel(
  value: unknown,
  locale = "en",
  width: "long" | "short" = "long",
): string {
  const ethiopian = ETHIOPIAN_MONTH_KEY.exec(String(value));
  if (ethiopian) {
    return formatEthiopianMonthYear(
      Number(ethiopian[1]),
      Number(ethiopian[2]),
      locale,
    );
  }

  const match = MONTH_KEY.exec(String(value));
  if (!match) return String(value);

  return new Intl.DateTimeFormat(locale, {
    month: width,
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1)));
}

/** A `YYYY-MM-DD` day in the calendar in force, without the year: `Meskerem 4` or `Sep 14`. */
export function dayKeyLabel(
  value: unknown,
  calendar: CalendarSystem,
  locale = "en",
): string {
  const match = DAY_KEY.exec(String(value));
  if (!match) return String(value);

  const [year, month, day] = [
    Number(match[1]),
    Number(match[2]),
    Number(match[3]),
  ];

  if (calendar === "ethiopian") {
    const eth = toEthiopian(year, month, day);
    const months = ethiopianMonths(locale);
    return `${months[eth.month - 1]} ${eth.day}`;
  }

  return new Intl.DateTimeFormat(locale, {
    month: "short",
    day: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
}
