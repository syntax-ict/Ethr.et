import type { AttendanceConflict } from "./api";

/**
 * Whether a conflict's two records look like one working day split by a break,
 * for wording only: the server decides (SplitDayJoiner). Same day, neither
 * void, the earlier one closed and the later one opened after that check-out.
 */
export function isSplitDayPair(conflict: AttendanceConflict): boolean {
  const a = conflict.record_a;
  const b = conflict.record_b;
  if (!a || !b || !a.check_in || !b.check_in) return false;
  if (a.date !== b.date) return false;

  const [first, second] = a.check_in <= b.check_in ? [a, b] : [b, a];
  if (!first.check_out) return false;

  return (
    new Date(second.check_in as string).getTime() >
    new Date(first.check_out).getTime()
  );
}
