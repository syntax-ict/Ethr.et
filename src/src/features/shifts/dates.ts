/**
 * Calendar-date and wall-clock helpers for the shift screens.
 *
 * Shift data is calendar dates (`effective_from`, a roster day) and wall-clock
 * times (`start_time`), neither of which has a timezone. The screens built them
 * with `toISOString()`, which converts to UTC first: for a local-midnight Date
 * in Addis Ababa (UTC+3) that names the *previous* day. Every roster cell was
 * keyed one day early, so a Monday–Friday shift was drawn Tuesday–Saturday,
 * and "today" defaulted to yesterday until 03:00. Everything here reads and
 * writes the local date parts and never goes through UTC.
 */

/** `YYYY-MM-DD` for the local calendar date of `d`. */
export function localIsoDate(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

/**
 * Local midnight of a `YYYY-MM-DD` date. `new Date("2026-10-05")` would be UTC
 * midnight instead — the previous evening anywhere west of Greenwich.
 */
export function parseIsoDate(iso: string): Date {
  const [y, m, d] = iso.slice(0, 10).split("-").map(Number);
  return new Date(y, m - 1, d);
}

export function todayIso(): string {
  return localIsoDate(new Date());
}

export function addDaysIso(iso: string, days: number): string {
  const d = parseIsoDate(iso);
  d.setDate(d.getDate() + days);
  return localIsoDate(d);
}

/** ISO weekday, Monday = 1 … Sunday = 7 — the encoding of `working_days`. */
export function isoWeekday(d: Date): number {
  return d.getDay() === 0 ? 7 : d.getDay();
}

/** The Monday that starts the ISO week containing `d` (a Sunday ends one). */
export function mondayOf(d: Date): Date {
  const monday = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  monday.setDate(monday.getDate() - (isoWeekday(monday) - 1));
  return monday;
}

/**
 * `HH:MM` from a shift time. The `time` column comes back from MariaDB as
 * `HH:MM:SS` (and onboarding writes seconds on every database), while
 * Store/UpdateShiftRequest accept only `date_format:H:i` — so a value echoed
 * back unchanged is a 422.
 */
export function toHHMM(time: string | null | undefined): string {
  return (time ?? "").slice(0, 5);
}

/** Parses `working_days` (`"1,2,3,4,5"`) into ISO weekdays. */
export function parseWorkingDays(workingDays: string | null | undefined) {
  return (workingDays ?? "")
    .split(",")
    .filter((d) => d.trim() !== "")
    .map(Number);
}
