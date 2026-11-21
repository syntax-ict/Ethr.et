/**
 * What a terminal's clock reading means, for the device page. The reading is
 * how far the terminal's clock is from ETHR's, in seconds, positive when the
 * terminal runs ahead (DeviceClock). It comes from the terminal's heartbeat
 * where the terminal puts a time in it, and from a punch dated after it
 * arrived otherwise; a terminal that sends heartbeats with no time and has
 * shown no punch ahead cannot be measured, and is never shown as zero.
 */

/** Within this a clock is in step: delivery delay and rounding. */
export const CLOCK_TOLERANCE_SECONDS = 120;

const SIX_HOURS = 6 * 3600;
/** How close to six hours counts as Ethiopian time of day. */
const SIX_HOUR_WINDOW = 30 * 60;
/** A year or more out is the date, not the time. */
const A_YEAR = 365 * 86400;

export type ClockFinding =
  | { kind: "unknown" }
  | { kind: "unmeasurable" }
  | { kind: "in_step" }
  | { kind: "ahead"; seconds: number }
  | { kind: "behind"; seconds: number }
  | { kind: "ethiopian_time"; seconds: number }
  | { kind: "timezone"; seconds: number }
  | { kind: "ethiopian_calendar"; seconds: number };

export function clockFinding(
  offset: number | null | undefined,
  measurable: boolean | null | undefined,
): ClockFinding {
  if (offset === null || offset === undefined) {
    return measurable === false
      ? { kind: "unmeasurable" }
      : { kind: "unknown" };
  }

  const size = Math.abs(offset);

  if (size <= CLOCK_TOLERANCE_SECONDS) return { kind: "in_step" };
  if (size >= A_YEAR) return { kind: "ethiopian_calendar", seconds: offset };
  if (Math.abs(size - SIX_HOURS) <= SIX_HOUR_WINDOW) {
    return { kind: "ethiopian_time", seconds: offset };
  }

  // A whole number of hours out: the terminal is on another time zone (one
  // shipped from China is on UTC+8, which reads five hours ahead).
  const hours = size / 3600;
  if (
    hours >= 1 &&
    hours <= 14 &&
    Math.abs(size - Math.round(hours) * 3600) <= CLOCK_TOLERANCE_SECONDS
  ) {
    return { kind: "timezone", seconds: offset };
  }

  return offset > 0
    ? { kind: "ahead", seconds: offset }
    : { kind: "behind", seconds: offset };
}

/** Whether the finding is something to act on, not only to read. */
export function clockNeedsAttention(finding: ClockFinding): boolean {
  return (
    finding.kind === "ahead" ||
    finding.kind === "behind" ||
    finding.kind === "ethiopian_time" ||
    finding.kind === "timezone" ||
    finding.kind === "ethiopian_calendar"
  );
}

export type DriftUnit = "minutes" | "hours" | "days" | "years";

/** The size of a drift in the unit a person would say it in. */
export function driftSize(seconds: number): { unit: DriftUnit; count: number } {
  const size = Math.abs(seconds);

  if (size < 2 * 3600) {
    return { unit: "minutes", count: Math.max(1, Math.round(size / 60)) };
  }
  if (size < 2 * 86400) {
    return { unit: "hours", count: Math.round(size / 3600) };
  }
  if (size < 2 * A_YEAR) {
    return { unit: "days", count: Math.round(size / 86400) };
  }

  return { unit: "years", count: Math.round(size / A_YEAR) };
}
