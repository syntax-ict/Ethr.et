import {
  ETHIOPIAN_MONTHS,
  ETHIOPIAN_MONTHS_AM,
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
 * A `YYYY-MM` bucket as its month name: `June 2026`.
 *
 * The buckets are Gregorian months, so the name is Gregorian in either
 * calendar — one Gregorian month spans two Ethiopian ones, and naming either
 * would misstate the bucket. Payroll periods read the same way ("July 2026").
 * Anything else, such as a payroll period label, passes through unchanged.
 */
export function monthKeyLabel(value: unknown, locale = "en"): string {
  const match = MONTH_KEY.exec(String(value));
  if (!match) return String(value);

  return new Intl.DateTimeFormat(locale, {
    month: "long",
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
    const months = locale === "am" ? ETHIOPIAN_MONTHS_AM : ETHIOPIAN_MONTHS;
    return `${months[eth.month - 1]} ${eth.day}`;
  }

  return new Intl.DateTimeFormat(locale, {
    month: "short",
    day: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, day)));
}
