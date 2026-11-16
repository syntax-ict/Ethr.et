import type { Fix } from "@/lib/attendance/punch-signals";

/**
 * Whether a position fix is old enough to be taken again.
 *
 * The server scores a fix older than 120 seconds as stale, so the punch screen
 * refreshes at 60: a person who left the page open and tapped a minute later
 * still sends a fix the server trusts. A fix with no timestamp cannot be aged,
 * so it counts as old; a timestamp in the future (a skewed clock) does not.
 */
export function fixIsOld(
  fix: Pick<Fix, "timestamp"> | null | undefined,
  now: number,
  maxAgeMs = 60_000,
): boolean {
  if (!fix) return true;
  if (typeof fix.timestamp !== "number") return true;
  return now - fix.timestamp > maxAgeMs;
}
