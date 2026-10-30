import {
  daysInEthiopianMonth,
  formatEthiopianMonthYear,
  toEthiopian,
  toGregorian,
} from "@/lib/calendar/ethiopian";
import type { CalendarSystem } from "@/lib/calendar/calendar-context";

/**
 * The periods the Analytics page offers, in whole months of the viewer's
 * calendar. An Ethiopian-calendar organisation thinks in Meskerem and Tikimt,
 * so its "this month" runs Tikimt 1 to 30, not 1 to 31 October.
 *
 * Each period carries the one before it, of the same number of months, which
 * the page asks for as well and compares against. Year to date and quarters
 * are left out on purpose: where an Ethiopian organisation's year starts
 * (Meskerem, or Hamle for the fiscal year) is a choice this page cannot make.
 */
export const PERIOD_PRESETS = [
  "this_month",
  "last_month",
  "last_3_months",
  "last_12_months",
] as const;

export type PeriodPreset = (typeof PERIOD_PRESETS)[number];

/** A month of either calendar: Gregorian 1 to 12, Ethiopian 1 to 13. */
export interface CalendarMonth {
  year: number;
  month: number;
}

/** Gregorian `Y-m-d` dates, as the API takes them. */
export interface DateRange {
  from: string;
  to: string;
}

export interface Period {
  preset: PeriodPreset;
  current: DateRange;
  previous: DateRange;
  first: CalendarMonth;
  last: CalendarMonth;
  /** Over before today. Counts (exits, payroll) compare only when it is. */
  complete: boolean;
}

const SPAN: Record<PeriodPreset, { months: number; back: number }> = {
  this_month: { months: 1, back: 0 },
  last_month: { months: 1, back: 1 },
  last_3_months: { months: 3, back: 0 },
  last_12_months: { months: 12, back: 0 },
};

function monthsPerYear(calendar: CalendarSystem): number {
  return calendar === "ethiopian" ? 13 : 12;
}

function shift(
  m: CalendarMonth,
  by: number,
  calendar: CalendarSystem,
): CalendarMonth {
  const n = monthsPerYear(calendar);
  const index = m.year * n + (m.month - 1) + by;
  return { year: Math.floor(index / n), month: (index % n) + 1 };
}

function iso(date: Date): string {
  const pad = (v: number) => String(v).padStart(2, "0");
  return `${String(date.getFullYear()).padStart(4, "0")}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function gregorianDate(year: number, month: number, day: number): Date {
  const date = new Date(2000, 0, 1);
  date.setFullYear(year, month - 1, day);
  return date;
}

function monthStart(m: CalendarMonth, calendar: CalendarSystem): string {
  return iso(
    calendar === "ethiopian"
      ? toGregorian(m.year, m.month, 1)
      : gregorianDate(m.year, m.month, 1),
  );
}

function monthEnd(m: CalendarMonth, calendar: CalendarSystem): string {
  return iso(
    calendar === "ethiopian"
      ? toGregorian(m.year, m.month, daysInEthiopianMonth(m.year, m.month))
      : gregorianDate(m.year, m.month + 1, 0),
  );
}

/** The month holding a Gregorian `Y-m-d` date, in the given calendar. */
export function monthOf(day: string, calendar: CalendarSystem): CalendarMonth {
  const [y, m, d] = day.split("-").map(Number);
  if (calendar === "ethiopian") {
    const e = toEthiopian(y, m, d);
    return { year: e.year, month: e.month };
  }
  return { year: y, month: m };
}

/**
 * Today's date where the organisation is, as `Y-m-d`. The browser's own date
 * is a day out for anyone viewing from another zone near midnight.
 */
export function todayIn(timeZone: string, now: Date = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(now);
}

export function periodFor(
  preset: PeriodPreset,
  calendar: CalendarSystem,
  today: string,
): Period {
  const { months, back } = SPAN[preset];
  const last = shift(monthOf(today, calendar), -back, calendar);
  const first = shift(last, -(months - 1), calendar);
  const previousLast = shift(first, -1, calendar);
  const previousFirst = shift(previousLast, -(months - 1), calendar);
  const current = {
    from: monthStart(first, calendar),
    to: monthEnd(last, calendar),
  };

  return {
    preset,
    current,
    previous: {
      from: monthStart(previousFirst, calendar),
      to: monthEnd(previousLast, calendar),
    },
    first,
    last,
    complete: current.to < today,
  };
}

/** A month's name and year in the viewer's calendar and language. */
export function monthLabel(
  m: CalendarMonth,
  calendar: CalendarSystem,
  locale: string,
): string {
  if (calendar === "ethiopian") {
    return formatEthiopianMonthYear(m.year, m.month, locale);
  }
  return new Intl.DateTimeFormat(locale === "am" ? "am" : "en", {
    month: "long",
    year: "numeric",
  }).format(gregorianDate(m.year, m.month, 1));
}
