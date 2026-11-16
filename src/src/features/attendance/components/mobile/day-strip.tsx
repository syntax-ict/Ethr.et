"use client";

import { Skeleton } from "@/components/ui/skeleton";
import type { MyShift } from "@/features/attendance/api";
import { dayNoteText } from "@/features/attendance/components/day-note";
import { ShiftRail } from "@/features/attendance/components/mobile/shift-rail";
import { shiftMoment } from "@/lib/attendance/shift-moment";
import { shiftMomentText } from "@/lib/attendance/shift-moment-text";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/**
 * Today at the top of the phone punch screen: the date and the running clock
 * on one line, the status as the headline, then the shift. No card and no
 * hero clock: the headline is the answer to "am I in?", the clock is context.
 */
export function DayStrip({
  dateLine,
  clock,
  shift,
  shiftLoading,
  timeOf,
  formatTimeOfDay,
  now,
  pendingPunch = null,
}: {
  dateLine: string;
  clock: string;
  shift: MyShift | undefined;
  shiftLoading: boolean;
  timeOf: (iso: string | null | undefined) => string;
  formatTimeOfDay: (time: string) => string;
  now: Date;
  /** A punch just made, shown on the rail before the server's data refreshes. */
  pendingPunch?: { type: "check_in" | "check_out"; at: Date } | null;
}) {
  const { t, locale } = useT();
  const status = shift?.attendance.status;
  const showShift =
    !shiftLoading &&
    !dayNoteText(shift?.day, t, locale) &&
    Boolean(shift?.shift && shift.working_day);

  // A punch the server has not yet reported is drawn at once, from its own
  // time, so the dot lands on the rail the moment the person taps.
  let railStatus = status ?? "not_checked_in";
  let railIn = shift?.attendance.check_in ?? null;
  let railOut = shift?.attendance.check_out ?? null;
  if (pendingPunch?.type === "check_in") {
    railStatus = "checked_in";
    railIn = pendingPunch.at.toISOString();
    railOut = null;
  } else if (pendingPunch?.type === "check_out") {
    railStatus = "checked_out";
    railOut = pendingPunch.at.toISOString();
  }
  const moment =
    showShift && shift?.shift
      ? shiftMoment({
          startsAt: shift.shift.starts_at,
          endsAt: shift.shift.ends_at,
          status: railStatus,
          checkIn: railIn,
          checkOut: railOut,
          now,
        })
      : null;

  return (
    <section className="px-1">
      <div className="flex items-baseline justify-between gap-3">
        <p className="text-sm text-muted-foreground">{dateLine}</p>
        <p className="text-2xl font-semibold tabular-nums tracking-tight text-foreground">
          {clock}
        </p>
      </div>

      {shift && (
        <p className="mt-1 flex items-center gap-2 font-display text-xl font-semibold text-foreground">
          <span
            aria-hidden="true"
            className={cn(
              "h-2.5 w-2.5 shrink-0 rounded-full",
              status === "checked_in" && "bg-success",
              status === "checked_out" && "bg-muted-foreground",
              status === "not_checked_in" && "bg-warning",
            )}
          />
          <span>
            {status === "checked_in"
              ? t("attendance.punch.in_at_time", "Checked in at :time", {
                  time: timeOf(shift.attendance.check_in),
                })
              : status === "checked_out"
                ? t("attendance.punch.out_at_time", "Checked out at :time", {
                    time: timeOf(shift.attendance.check_out),
                  })
                : t("dashboard.not_checked_in", "Not checked in")}
          </span>
        </p>
      )}

      <div className="mt-1 min-h-6 text-sm">
        {shiftLoading ? (
          <Skeleton className="h-5 w-48" />
        ) : dayNoteText(shift?.day, t, locale) ? null : shift?.shift &&
          shift.working_day ? (
          <span className="text-muted-foreground">
            {shift.shift.name} ·{" "}
            <span className="font-medium tabular-nums text-foreground">
              {formatTimeOfDay(shift.shift.start_time)}–
              {formatTimeOfDay(shift.shift.end_time)}
            </span>
          </span>
        ) : shift ? (
          <span className="text-muted-foreground">
            {t("attendance.punch.no_shift", "No shift scheduled today")}
          </span>
        ) : null}
      </div>

      {showShift && shift?.shift && (
        <>
          <div className="mt-3">
            <ShiftRail
              startsAt={shift.shift.starts_at}
              endsAt={shift.shift.ends_at}
              checkIn={railIn}
              checkOut={railOut}
              now={now}
              landing={pendingPunch?.type ?? null}
              label={t(
                "attendance.punch.rail_label",
                "Your shift today, from :start to :end",
                {
                  start: formatTimeOfDay(shift.shift.start_time),
                  end: formatTimeOfDay(shift.shift.end_time),
                },
              )}
            />
          </div>
          {moment && (
            <p className="mt-1 text-sm tabular-nums text-muted-foreground">
              {shiftMomentText(moment, t)}
            </p>
          )}
        </>
      )}

      {shift?.attendance.auto_checked_out && (
        <p className="mt-2 text-xs text-muted-foreground">
          {t(
            "attendance.punch.auto_closed_note",
            "Closed automatically at the end of your shift. File a correction if you left at another time.",
          )}
        </p>
      )}
    </section>
  );
}
