import type { MyShift } from "@/features/attendance/api";

/**
 * Which punch a self-service screen offers, from the server's record of the
 * day: the same answer whichever screen asks. Before, only the phone screen
 * asked. After a phone check-in, the web page and the QR scanner still
 * offered Check in first, and a check-in pressed hours later opened a second
 * record flagged as a conflict instead of ending the day.
 *
 * `done` is a day already checked out: a further punch is a second session,
 * offered as "Check in again". `null` is no answer (no employee record, still
 * loading, offline): the screen lets the person choose.
 */
export type NextPunch = "check_in" | "check_out" | "done";

export function nextPunch(shift: MyShift | undefined): NextPunch | null {
  const status = shift?.attendance.status;
  if (!status) return null;
  if (status === "checked_in") return "check_out";
  if (status === "checked_out") return "done";
  return "check_in";
}
