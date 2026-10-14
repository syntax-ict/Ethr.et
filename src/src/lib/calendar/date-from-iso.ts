/**
 * A calendar date (`YYYY-MM-DD`, as the API sends holidays and leave ranges)
 * as a local Date at midnight.
 *
 * `new Date("2026-12-25")` parses as UTC midnight, which is the previous day
 * anywhere west of Greenwich; building it from its parts keeps the day the
 * API meant, whatever the browser's time zone.
 */
export function dateFromIso(value: string): Date {
  const [year, month, day] = value.slice(0, 10).split("-").map(Number);
  return new Date(year, (month ?? 1) - 1, day ?? 1);
}
