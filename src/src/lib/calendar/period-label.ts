import { dateFromIso } from "./date-from-iso";
import { formatEthiopianMonthYear, toEthiopian } from "./ethiopian";
import type { CalendarSystem } from "./calendar-context";

/**
 * A payroll run's month, in the viewer's calendar and language.
 *
 * The stored `period_label` is written once at processing time as the English
 * Gregorian month of the period's start ("September 2026"), so an Amharic
 * screen showed an English label, and an Ethiopian-calendar viewer saw a
 * Gregorian month for a period that runs Meskerem 1 to 30. The label is
 * therefore derived at display time from the period's dates.
 *
 * The month is taken from the period's MIDPOINT, not its start: organisations
 * cut periods on Ethiopian month boundaries (11 September to 10 October) or on
 * Gregorian ones (1 to 30 September), and the midpoint lands inside the month
 * the period means in both shapes, in both calendars. The start date would
 * name ጳጉሜ for a Gregorian September run.
 *
 * Filenames keep the stored label on purpose: it is ASCII and stable, and a
 * download name is not display text.
 */
export function localizedPeriodLabel(
  stored: string | null | undefined,
  periodStart: string | null | undefined,
  periodEnd: string | null | undefined,
  calendar: CalendarSystem,
  locale: string,
): string {
  if (!periodStart) return stored ?? "";

  const start = dateFromIso(periodStart);
  const end = periodEnd ? dateFromIso(periodEnd) : start;
  const midpoint = new Date((start.getTime() + end.getTime()) / 2);

  if (calendar === "ethiopian") {
    const ethiopian = toEthiopian(
      midpoint.getFullYear(),
      midpoint.getMonth() + 1,
      midpoint.getDate(),
    );
    return formatEthiopianMonthYear(ethiopian.year, ethiopian.month, locale);
  }

  if (locale === "am") {
    return new Intl.DateTimeFormat("am", {
      month: "long",
      year: "numeric",
    }).format(midpoint);
  }

  // English Gregorian is what the stored label already says; prefer it so the
  // display can never disagree with the filename built from it.
  return (
    stored ??
    new Intl.DateTimeFormat("en", { month: "long", year: "numeric" }).format(
      midpoint,
    )
  );
}
