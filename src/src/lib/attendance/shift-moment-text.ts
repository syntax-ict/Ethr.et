import type { ShiftMoment } from "@/lib/attendance/shift-moment";

type Translate = (
  key: string,
  fallback?: string,
  replacements?: Record<string, string | number>,
) => string;

/** "42 min", or "4 h 05 min" from an hour up. */
export function durationText(totalMinutes: number, t: Translate): string {
  const minutes = Math.max(0, Math.floor(totalMinutes));
  const hours = Math.floor(minutes / 60);
  if (hours === 0)
    return t("attendance.punch.duration_min", ":minutes min", { minutes });
  return t("attendance.punch.duration_h_min", ":hours h :minutes min", {
    hours,
    minutes: String(minutes % 60).padStart(2, "0"),
  });
}

/** The sentence for a shift moment. */
export function shiftMomentText(moment: ShiftMoment, t: Translate): string {
  switch (moment.kind) {
    case "starts_in":
      return t("attendance.punch.starts_in", "Starts in :duration", {
        duration: durationText(moment.minutes, t),
      });
    case "started_ago":
      return t("attendance.punch.started_ago", "Started :duration ago", {
        duration: durationText(moment.minutes, t),
      });
    case "worked": {
      const so_far = t("attendance.punch.worked_so_far", ":duration so far", {
        duration: durationText(moment.minutes, t),
      });
      if (moment.endsInMinutes === null) return so_far;
      const ends = t("attendance.punch.ends_in", "ends in :duration", {
        duration: durationText(moment.endsInMinutes, t),
      });
      return `${so_far} · ${ends}`;
    }
    case "worked_total":
      return t("attendance.punch.worked_total", "Worked :duration", {
        duration: durationText(moment.minutes, t),
      });
  }
}
