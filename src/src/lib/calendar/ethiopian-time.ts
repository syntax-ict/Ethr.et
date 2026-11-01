/**
 * Ethiopian time of day.
 *
 * The Ethiopian clock counts twelve hours from dawn and twelve from dusk:
 * 06:00 is 12:00 ጠዋት, 07:30 is 1:30 ጠዋት, 12:00 is 6:00 ቀን, 18:00 is
 * 12:00 ምሽት and 00:00 is 6:00 ሌሊት. The hour is `(h24 - 6) mod 12`, with 0
 * written as 12; the period word says which quarter of the day it is.
 *
 * This is a way of showing and typing a time, never of storing one. Every time
 * on the wire and in the database stays UTC and 24-hour; converting at the edge
 * is what keeps attendance and payroll arithmetic out of it.
 *
 * The server settles `auto` with the same rule (`App\Support\ClockPreference`).
 */
import type { CalendarSystem } from "./calendar-context";

/** What a person or organisation chose. `auto` is settled by `resolveClock`. */
export type ClockPreference = "auto" | "ethiopian" | "24h";

/** The clock a time is actually written in. */
export type ClockSystem = "ethiopian" | "24h";

export type EthiopianPeriod = "morning" | "day" | "evening" | "night";

export interface EthiopianClockTime {
  /** 1-12. */
  hour: number;
  minute: number;
  period: EthiopianPeriod;
}

const PERIOD_LABELS: Record<string, Record<EthiopianPeriod, string>> = {
  am: { morning: "ጠዋት", day: "ቀን", evening: "ምሽት", night: "ሌሊት" },
  en: {
    morning: "morning",
    day: "afternoon",
    evening: "evening",
    night: "night",
  },
};

export const ETHIOPIAN_PERIODS: readonly EthiopianPeriod[] = [
  "morning",
  "day",
  "evening",
  "night",
];

/** Anything stored that is not a known choice reads as `auto`. */
export function toClockPreference(value: unknown): ClockPreference {
  return value === "ethiopian" || value === "24h" ? value : "auto";
}

/**
 * The clock to write in. `auto` is Ethiopian time for an Amharic reader on the
 * Ethiopian calendar, and 24-hour for anyone else: someone reading English, or
 * who chose the Gregorian calendar, has said they read the international way.
 */
export function resolveClock(
  preference: ClockPreference,
  calendar: CalendarSystem,
  locale: string,
): ClockSystem {
  if (preference === "ethiopian" || preference === "24h") return preference;
  return calendar === "ethiopian" && locale === "am" ? "ethiopian" : "24h";
}

function periodOf(hour24: number): EthiopianPeriod {
  if (hour24 >= 6 && hour24 < 12) return "morning";
  if (hour24 >= 12 && hour24 < 18) return "day";
  if (hour24 >= 18) return "evening";
  return "night";
}

/** A 24-hour time of day on the Ethiopian clock. */
export function toEthiopianClock(
  hour24: number,
  minute: number,
): EthiopianClockTime {
  const hour = (((hour24 - 6) % 12) + 12) % 12;
  return { hour: hour === 0 ? 12 : hour, minute, period: periodOf(hour24) };
}

/** The 24-hour time an Ethiopian-clock reading names. */
export function fromEthiopianClock(time: EthiopianClockTime): {
  hour: number;
  minute: number;
} {
  // Each quarter is six readings: ጠዋት and ምሽት run 12, 1 … 5; ቀን and ሌሊት
  // run 6 … 11. The offset into the quarter is the distance from its first.
  const start = { morning: 6, day: 12, evening: 18, night: 0 }[time.period];
  const first = time.period === "morning" || time.period === "evening" ? 0 : 6;
  const offset = (time.hour % 12) - first;
  return { hour: start + offset, minute: time.minute };
}

export function ethiopianPeriodLabel(
  period: EthiopianPeriod,
  locale: string,
): string {
  return (PERIOD_LABELS[locale] ?? PERIOD_LABELS.en)[period];
}

/** `1:30 ጠዋት`; in English, `1:30 morning`. With `second`, `1:30:07 ጠዋት`. */
export function formatEthiopianClock(
  hour24: number,
  minute: number,
  locale: string,
  second?: number,
): string {
  const time = toEthiopianClock(hour24, minute);
  const mm = String(time.minute).padStart(2, "0");
  return `${time.hour}:${mm}${seconds(second)} ${ethiopianPeriodLabel(time.period, locale)}`;
}

function seconds(second: number | undefined): string {
  return second === undefined ? "" : `:${String(second).padStart(2, "0")}`;
}

/**
 * `06:00`, or `12:00 ጠዋት`, for a 24-hour hour and minute. With `second`, a
 * running clock: `06:00:07`, or `12:00:07 ጠዋት`.
 */
export function formatClock(
  hour24: number,
  minute: number,
  clock: ClockSystem,
  locale: string,
  second?: number,
): string {
  if (clock === "ethiopian")
    return formatEthiopianClock(hour24, minute, locale, second);
  return `${String(hour24).padStart(2, "0")}:${String(minute).padStart(2, "0")}${seconds(second)}`;
}

/**
 * A stored time of day (`06:00`, `06:00:00`) in a clock, or the value as it
 * came when it is not one. Shift starts and ends arrive in this shape.
 */
export function formatTimeOfDay(
  value: string,
  clock: ClockSystem,
  locale: string,
): string {
  const match = /^(\d{1,2}):(\d{2})/.exec(value.trim());
  if (!match) return value;
  const hour = Number(match[1]);
  const minute = Number(match[2]);
  if (hour > 23 || minute > 59) return value;
  return formatClock(hour, minute, clock, locale);
}
