import type { ShiftAssignment } from "./api";
import {
  addDaysIso,
  isoWeekday,
  parseIsoDate,
  parseWorkingDays,
  toHHMM,
} from "./dates";

export interface RosterEntry {
  name: string;
  start: string;
  end: string;
  type: string;
}

/**
 * Calendar date → the fixed shifts that apply on it, for `[from, to]`.
 *
 * Pure date-string arithmetic: every key is a calendar date and every weekday
 * is read from that same date. The roster page used to key its cells with
 * `toISOString()` — the UTC date, one day early for a local-midnight Date in
 * Addis — so every shift was drawn on the day after its real one.
 *
 * Rotation assignments carry no shift (`is_rotation`, `shift: null`) and are
 * skipped: the schedule endpoint does not load the rotation, so there is
 * nothing here to draw them from.
 */
export function buildRoster(
  assignments: ShiftAssignment[],
  from: string,
  to: string,
): Record<string, RosterEntry[]> {
  const map: Record<string, RosterEntry[]> = {};

  for (const a of assignments) {
    if (!a.shift || !a.effective_from) continue;
    const workingDays = parseWorkingDays(a.shift.working_days);
    const start = a.effective_from > from ? a.effective_from : from;
    const end = a.effective_to && a.effective_to < to ? a.effective_to : to;

    for (let day = start; day <= end; day = addDaysIso(day, 1)) {
      if (!workingDays.includes(isoWeekday(parseIsoDate(day)))) continue;
      (map[day] ??= []).push({
        name: a.shift.name,
        start: toHHMM(a.shift.start_time),
        end: toHHMM(a.shift.end_time),
        type: a.assignable_type,
      });
    }
  }

  return map;
}
