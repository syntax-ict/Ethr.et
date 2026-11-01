import {
  DEFAULT_TIMEZONE,
  formatCalendarDate,
  type DisplayCalendar,
} from "@/lib/utils/date";

/**
 * The calendar a signed-out page writes dates in.
 *
 * A public page (an organisation's portal, a vacancy, a news story, a legal
 * document) has no `CalendarProvider` and no reader preference to follow, so
 * it follows the page language: Amharic reads the Ethiopian calendar, any
 * other language the Gregorian one. One rule, so the portal, its vacancies and
 * its news cannot disagree on the same page.
 */
export function publicCalendar(locale: string): DisplayCalendar {
  return locale === "am" ? "ethiopian" : "gregorian";
}

/** The other calendar, for a date that is shown in both. */
export function otherCalendar(calendar: DisplayCalendar): DisplayCalendar {
  return calendar === "ethiopian" ? "gregorian" : "ethiopian";
}

/**
 * A date or instant on a signed-out page, in the page language's calendar.
 * The day is the one in Addis Ababa; a bare `Y-m-d` is read as written.
 */
export function publicDate(
  value: string,
  locale: string,
  calendar: DisplayCalendar = publicCalendar(locale),
): string {
  return formatCalendarDate(value, DEFAULT_TIMEZONE, calendar, locale);
}
