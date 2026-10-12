import type { MyShift } from "@/features/attendance/api";

/**
 * The punching assistant's decisions, kept free of React and the browser so
 * they can be tested on their own: when to remind someone, and whether the
 * phone may check them in by itself.
 *
 * Everything runs from `GET /attendance/my-shift`, whose shift times are UTC
 * instants - so a phone set to any zone reminds at the right moment.
 */

export interface Position {
  latitude: number;
  longitude: number;
  /** Metres, as the Geolocation API reports it. */
  accuracy: number;
}

/** Great-circle distance in metres (the server's ConfidenceScorer uses the same). */
export function distanceMeters(
  a: { latitude: number; longitude: number },
  b: { latitude: number; longitude: number },
): number {
  const R = 6_371_000;
  const toRad = (deg: number) => (deg * Math.PI) / 180;
  const dLat = toRad(b.latitude - a.latitude);
  const dLng = toRad(b.longitude - a.longitude);
  const h =
    Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(a.latitude)) *
      Math.cos(toRad(b.latitude)) *
      Math.sin(dLng / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(h));
}

export type GeofenceState =
  | { kind: "none" }
  | { kind: "inside"; distance: number }
  | { kind: "outside"; distance: number };

export function geofenceState(
  shift: MyShift,
  position: Position | null,
): GeofenceState {
  const fence = shift.policy.geofence;
  if (!fence || !position) return { kind: "none" };
  const distance = distanceMeters(position, fence);
  return distance <= fence.radius_meters
    ? { kind: "inside", distance }
    : { kind: "outside", distance };
}

export type ReminderKind = "before_start" | "not_checked_in" | "shift_end";

export interface Reminder {
  kind: ReminderKind;
  at: Date;
}

/**
 * The reminders still ahead today, in order.
 *
 * - `before_start`: the lead time before the shift, if not yet checked in.
 * - `not_checked_in`: when the grace period ends, if still not checked in.
 * - `shift_end`: when the shift ends, if checked in and not out.
 *
 * None on a day off, a holiday or approved leave, and none when either the
 * organisation or the employee turned reminders off.
 */
export function reminderPlan(shift: MyShift, now: Date): Reminder[] {
  if (!shift.assistant.reminders || !shift.working_day || !shift.shift) {
    return [];
  }

  const start = new Date(shift.shift.starts_at);
  const end = new Date(shift.shift.ends_at);
  const status = shift.attendance.status;
  const plan: Reminder[] = [];

  if (status === "not_checked_in") {
    plan.push({
      kind: "before_start",
      at: new Date(
        start.getTime() - shift.assistant.reminder_lead_minutes * 60_000,
      ),
    });
    plan.push({
      kind: "not_checked_in",
      at: new Date(start.getTime() + shift.shift.grace_minutes * 60_000),
    });
  }

  if (status !== "checked_out") {
    plan.push({ kind: "shift_end", at: end });
  }

  return plan
    .filter((r) => r.at.getTime() > now.getTime())
    .sort((a, b) => a.at.getTime() - b.at.getTime());
}

/** Whether a reminder still applies when its moment comes. */
export function reminderStillDue(kind: ReminderKind, shift: MyShift): boolean {
  if (!shift.assistant.reminders || !shift.working_day) return false;
  const status = shift.attendance.status;
  return kind === "shift_end"
    ? status === "checked_in"
    : status === "not_checked_in";
}

/** How early before the shift an automatic check-in may happen. */
export const AUTO_CHECK_IN_EARLY_MINUTES = 60;

export type AutoCheckInDecision =
  | { ok: true }
  | {
      ok: false;
      reason:
        | "not_enabled"
        | "not_working_day"
        | "already_in"
        | "outside_window"
        | "photo_required"
        | "no_position"
        | "imprecise"
        | "outside_area";
    };

/**
 * Whether the phone may check the employee in by itself, now.
 *
 * Only an employee who opted in, at an organisation that allows it; on a
 * working day; not already checked in; from an hour before the shift to the
 * end of its grace period; inside the branch area, with a fix precise enough
 * to say so; and never where a selfie is required, which only a person can
 * take. The server checks the opt-ins and the area again.
 */
export function autoCheckInDecision(
  shift: MyShift,
  now: Date,
  position: Position | null,
): AutoCheckInDecision {
  if (!shift.assistant.auto_check_in || !shift.policy.mobile_enabled) {
    return { ok: false, reason: "not_enabled" };
  }
  if (!shift.working_day || !shift.shift) {
    return { ok: false, reason: "not_working_day" };
  }
  if (shift.attendance.status !== "not_checked_in") {
    return { ok: false, reason: "already_in" };
  }

  const start = new Date(shift.shift.starts_at).getTime();
  const opens = start - AUTO_CHECK_IN_EARLY_MINUTES * 60_000;
  const closes = start + shift.shift.grace_minutes * 60_000;
  if (now.getTime() < opens || now.getTime() > closes) {
    return { ok: false, reason: "outside_window" };
  }

  if (shift.policy.photo_required) {
    return { ok: false, reason: "photo_required" };
  }
  if (!position) return { ok: false, reason: "no_position" };

  const fence = shift.policy.geofence;
  const maxAccuracy = Math.max(shift.policy.accuracy_threshold_meters, 50);
  if (position.accuracy > maxAccuracy) {
    return { ok: false, reason: "imprecise" };
  }
  if (!fence || distanceMeters(position, fence) > fence.radius_meters) {
    return { ok: false, reason: "outside_area" };
  }

  return { ok: true };
}
