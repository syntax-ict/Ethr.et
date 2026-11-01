import { localizedPeriodLabel } from "./period-label";
import type { CalendarSystem } from "./calendar-context";

const ENGLISH_MONTHS = [
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December",
];

/**
 * The Gregorian month a stored payroll label names, as its first and last day.
 *
 * The label is written once, in English, as the month the period starts in
 * (`PayrollEngine`: `format('F Y')`). Taking that whole month is enough for
 * `localizedPeriodLabel`: its midpoint, the 15th or 16th, falls in the same
 * Ethiopian month as the real period's midpoint whether the organisation cuts
 * on Gregorian months or on Ethiopian ones (which start between the 6th and
 * the 11th). Anything that is not `Month YYYY` returns null.
 */
export function storedLabelMonth(
  label: string,
): { start: string; end: string } | null {
  const match = /^([A-Za-z]+) (\d{4})$/.exec(label.trim());
  if (!match) return null;
  const month = ENGLISH_MONTHS.indexOf(match[1]) + 1;
  if (month === 0) return null;
  const year = Number(match[2]);
  const last = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const mm = String(month).padStart(2, "0");
  return { start: `${year}-${mm}-01`, end: `${year}-${mm}-${last}` };
}

/**
 * A payroll run's stored label in the reader's calendar and language, for a
 * response that sends the label without the run's dates (a payslip, an
 * analytics trend point). An unrecognised label is shown as stored.
 */
export function storedPeriodLabel(
  stored: string,
  calendar: CalendarSystem,
  locale: string,
): string {
  const month = storedLabelMonth(stored);
  return localizedPeriodLabel(
    stored,
    month?.start,
    month?.end,
    calendar,
    locale,
  );
}
