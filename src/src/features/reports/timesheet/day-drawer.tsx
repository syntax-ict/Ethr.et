"use client";

import Link from "next/link";
import { Lock } from "lucide-react";
import { Sheet } from "@/components/ui/sheet";
import { Skeleton } from "@/components/ui/skeleton";
import { ErrorState } from "@/components/shared/error-state";
import { useAttendanceList } from "@/features/attendance/api";
import { clock, codeLabel, codeTone } from "@/features/reports/timesheet/codes";
import type {
  TimesheetCell,
  TimesheetRow,
} from "@/features/reports/timesheet/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { localizedName } from "@/lib/i18n/localizedName";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/** What the engine logs for each punch of a record (`metadata.punches[]`). */
interface Punch {
  kind?: string;
  source?: string;
  at?: string;
  outcome?: string;
}

function asPunch(value: unknown): Punch {
  return typeof value === "object" && value !== null ? (value as Punch) : {};
}

/**
 * One person's one day: what the cell says and what was punched behind it.
 * The records come from the attendance list, which already carries each
 * record's punches, so there is no second endpoint to keep in step.
 */
export function DayDrawer({
  row,
  cell,
  onClose,
}: {
  row: TimesheetRow | null;
  cell: TimesheetCell | null;
  onClose: () => void;
}) {
  const { t, locale } = useT();
  const { formatDate, formatTime, formatWeekday } = useDateFormatters();
  const { hasPermission } = usePermissions();
  const canRead = hasPermission("attendance.viewAll");
  const open = row !== null && cell !== null;

  const records = useAttendanceList(
    {
      "filter[employee_public_id]": row?.public_id,
      "filter[date_from]": cell?.date,
      "filter[date_to]": cell?.date,
      per_page: 20,
    },
    { enabled: open && canRead && (cell?.records.length ?? 0) > 0 },
  );

  const title = row
    ? `${localizedName({ name: row.name, name_am: row.name_am }, locale)}${cell ? ` · ${formatDate(cell.date)}` : ""}`
    : "";

  return (
    <Sheet
      open={open}
      onOpenChange={(next) => !next && onClose()}
      title={title || t("reports_page.timesheet.day", "Day")}
      showTitle
      side="right"
      className="w-96 max-w-[95vw] gap-4 pb-6"
    >
      {row && cell ? (
        <div className="space-y-4 px-6 pt-2">
          <p className="text-sm text-muted-foreground">
            {formatWeekday(cell.date, "long")}
          </p>

          <div className="flex items-center gap-2">
            <span
              className={cn(
                "inline-flex min-w-9 justify-center rounded px-2 py-1 text-sm font-semibold",
                codeTone(cell.code),
              )}
            >
              {cell.short ?? "·"}
            </span>
            <span className="text-sm font-medium">
              {cell.code
                ? codeLabel(t, cell.code)
                : t(
                    "reports_page.timesheet.pending_day",
                    "The day is not over yet",
                  )}
            </span>
          </div>

          <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted-foreground">
              {t("reports_page.timesheet.scheduled", "Scheduled")}
            </dt>
            <dd className="tabular-nums">{clock(cell.scheduled_minutes)}</dd>
            <dt className="text-muted-foreground">
              {t("reports_page.timesheet.worked", "Worked")}
            </dt>
            <dd className="tabular-nums">{clock(cell.worked_minutes)}</dd>
            {cell.late_minutes > 0 ? (
              <>
                <dt className="text-muted-foreground">
                  {t("reports_page.timesheet.late_by", "Late by (minutes)")}
                </dt>
                <dd className="tabular-nums">{cell.late_minutes}</dd>
              </>
            ) : null}
            {cell.early_minutes > 0 ? (
              <>
                <dt className="text-muted-foreground">
                  {t("reports_page.timesheet.early_by", "Left early (minutes)")}
                </dt>
                <dd className="tabular-nums">{cell.early_minutes}</dd>
              </>
            ) : null}
            {Object.entries(cell.overtime).map(([type, minutes]) => (
              <div key={type} className="col-span-2 flex justify-between">
                <dt className="text-muted-foreground">
                  {overtimeTypeLabel(t, type)}
                </dt>
                <dd className="tabular-nums">{clock(minutes)}</dd>
              </div>
            ))}
          </dl>

          {cell.exception ? (
            <p className="rounded-md bg-status-warning/15 p-3 text-sm text-status-warning">
              {t(
                "reports_page.timesheet.exception_open",
                "A punch for this day still has to be fixed.",
              )}
            </p>
          ) : null}

          <section aria-live="polite" className="space-y-2">
            <h3 className="text-sm font-semibold">
              {t("reports_page.timesheet.punches", "Punches")}
            </h3>
            {cell.records.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {t(
                  "reports_page.timesheet.no_punches",
                  "Nothing was punched on this day.",
                )}
              </p>
            ) : !canRead ? (
              <p className="text-sm text-muted-foreground">
                {t(
                  "reports_page.timesheet.punches_hidden",
                  "You may see that this day has records, not the punches behind them.",
                )}
              </p>
            ) : records.isLoading ? (
              <Skeleton className="h-16 w-full" />
            ) : records.isError ? (
              <ErrorState compact onRetry={() => records.refetch()} />
            ) : (
              <ul className="space-y-3">
                {(records.data?.data ?? []).map((record) => (
                  <li
                    key={record.public_id}
                    className="rounded-md border p-3 text-sm"
                  >
                    <p className="font-medium">
                      {record.check_in ? formatTime(record.check_in) : "–"}
                      {" → "}
                      {record.check_out ? formatTime(record.check_out) : "–"}
                      <span className="ml-2 text-xs font-normal text-muted-foreground">
                        {record.status_label} · {record.source_label}
                      </span>
                    </p>
                    {record.punches.length > 0 ? (
                      <ol className="mt-2 space-y-1 text-xs text-muted-foreground">
                        {record.punches.map((value, index) => {
                          const punch = asPunch(value);
                          return (
                            <li key={index}>
                              {punch.at ? formatTime(punch.at) : "–"}
                              {punch.kind ? ` · ${punch.kind}` : ""}
                              {punch.source ? ` · ${punch.source}` : ""}
                              {punch.outcome ? ` · ${punch.outcome}` : ""}
                            </li>
                          );
                        })}
                      </ol>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </section>

          {cell.locked ? (
            <p className="flex items-start gap-2 rounded-md bg-muted p-3 text-sm">
              <Lock className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              {t(
                "reports_page.timesheet.day_locked",
                "Payroll has paid this day, so it can no longer be edited. A fix goes into the next run.",
              )}
            </p>
          ) : (
            <Link
              href="/attendance/corrections"
              className="inline-block text-sm font-medium text-interactive-primary underline-offset-4 hover:underline"
            >
              {t(
                "reports_page.timesheet.request_correction",
                "Request a correction",
              )}
            </Link>
          )}
        </div>
      ) : null}
    </Sheet>
  );
}

/** The name of an overtime rate type, as payroll calls it. */
export function overtimeTypeLabel(
  t: ReturnType<typeof useT>["t"],
  type: string,
): string {
  switch (type) {
    case "normal":
      return t("reports_page.timesheet.ot.normal", "Overtime, day");
    case "night":
      return t("reports_page.timesheet.ot.night", "Overtime, night");
    case "rest_day":
      return t("reports_page.timesheet.ot.rest_day", "Overtime, rest day");
    case "holiday":
      return t("reports_page.timesheet.ot.holiday", "Overtime, holiday");
    case "holiday_night":
      return t(
        "reports_page.timesheet.ot.holiday_night",
        "Overtime, holiday night",
      );
    default:
      return type;
  }
}
