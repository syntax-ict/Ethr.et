"use client";

import { useState } from "react";
import { X, CalendarRange, Clock as ClockIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";

const STATUS_BG: Record<string, string> = {
  present: "bg-success hover:bg-success",
  late: "bg-warning hover:bg-warning",
  absent: "bg-destructive hover:bg-destructive",
  weekend: "bg-muted hover:bg-muted-foreground/20",
};

interface TimelineResponse {
  employee: { public_id: string; name: string };
  range: { from: string; to: string };
  totals: {
    present: number;
    late: number;
    absent: number;
    total_minutes_worked: number;
  };
  days: TimelineDay[];
}

interface TimelineDay {
  date: string;
  status: "present" | "late" | "absent" | "weekend" | string;
  check_in: string | null;
  check_out: string | null;
  worked_minutes: number | null;
  source: string | null;
  is_weekend: boolean;
}

export function AttendanceTimelineTab({ employeeId }: { employeeId: string }) {
  const { t } = useT();
  const [range, setRange] = useState<{ from: string; to: string }>(() => {
    const to = new Date().toISOString().split("T")[0];
    const from = new Date(Date.now() - 90 * 86400000)
      .toISOString()
      .split("T")[0];
    return { from, to };
  });
  const [selectedDay, setSelectedDay] = useState<TimelineDay | null>(null);

  const { data, isLoading } = useQuery<TimelineResponse>({
    queryKey: ["employee", employeeId, "timeline", range],
    queryFn: async () => {
      const { data } = await apiClient.get(
        `/employees/${employeeId}/attendance/timeline`,
        {
          params: { from: range.from, to: range.to },
        },
      );
      return data;
    },
  });

  if (isLoading || !data) {
    return (
      <Card>
        <CardContent className="p-6">
          <Skeleton className="h-8 w-48 mb-4" />
          <Skeleton className="h-40 w-full" />
        </CardContent>
      </Card>
    );
  }

  const weeks: TimelineDay[][] = [];
  let currentWeek: TimelineDay[] = [];
  const firstDate = new Date(data.days[0]?.date ?? range.from);
  const firstDow = (firstDate.getDay() + 6) % 7;
  for (let i = 0; i < firstDow; i++) {
    currentWeek.push({
      date: "",
      status: "weekend",
      check_in: null,
      check_out: null,
      worked_minutes: null,
      source: null,
      is_weekend: true,
    });
  }
  for (const day of data.days) {
    currentWeek.push(day);
    if (currentWeek.length === 7) {
      weeks.push(currentWeek);
      currentWeek = [];
    }
  }
  if (currentWeek.length > 0) {
    while (currentWeek.length < 7) {
      currentWeek.push({
        date: "",
        status: "weekend",
        check_in: null,
        check_out: null,
        worked_minutes: null,
        source: null,
        is_weekend: true,
      });
    }
    weeks.push(currentWeek);
  }

  const totalHoursWorked = (data.totals.total_minutes_worked / 60).toFixed(1);

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <CardTitle className="text-base flex items-center gap-2">
            <CalendarRange className="h-4 w-4" />{" "}
            {t("employee.attendance.timeline_title", "Attendance Timeline")}
          </CardTitle>
          <p className="mt-1 text-xs text-muted-foreground">
            {data.range.from} to {data.range.to}
          </p>
        </div>
        <div className="flex gap-2">
          <DualCalendarDateInput
            value={range.from}
            onChange={(v) => setRange((r) => ({ ...r, from: v }))}
            className="w-36"
          />
          <DualCalendarDateInput
            value={range.to}
            onChange={(v) => setRange((r) => ({ ...r, to: v }))}
            className="w-36"
          />
        </div>
      </CardHeader>
      <CardContent>
        <div className="mb-6 grid gap-3 sm:grid-cols-4">
          <TotalChip
            color="green"
            label={t("employee.attendance.present", "Present")}
            value={data.totals.present}
          />
          <TotalChip
            color="amber"
            label={t("employee.attendance.late", "Late")}
            value={data.totals.late}
          />
          <TotalChip
            color="red"
            label={t("employee.attendance.absent", "Absent")}
            value={data.totals.absent}
          />
          <TotalChip
            color="blue"
            label={t("employee.attendance.hours_worked", "Hours Worked")}
            value={totalHoursWorked}
            suffix="h"
          />
        </div>

        <div className="overflow-x-auto pb-2">
          <div className="flex gap-2">
            <div className="flex flex-col gap-1 pt-0">
              {["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d) => (
                <div
                  key={d}
                  className="h-4 text-[10px] font-medium text-muted-foreground leading-none flex items-center w-6"
                >
                  {d}
                </div>
              ))}
            </div>
            <div className="flex gap-1">
              {weeks.map((week, wi) => (
                <div key={wi} className="flex flex-col gap-1">
                  {week.map((day, di) => {
                    const isEmpty = day.date === "";
                    const bg = isEmpty
                      ? "bg-transparent"
                      : (STATUS_BG[day.status] ?? "bg-muted");
                    return (
                      <button
                        key={di}
                        onClick={() => !isEmpty && setSelectedDay(day)}
                        disabled={isEmpty}
                        title={isEmpty ? "" : `${day.date} — ${day.status}`}
                        className={cn(
                          "h-4 w-4 rounded-sm transition-colors",
                          bg,
                          !isEmpty &&
                            "cursor-pointer ring-offset-1 hover:ring-2 hover:ring-primary",
                        )}
                      />
                    );
                  })}
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="mt-6 flex flex-wrap items-center gap-4 text-xs">
          <span className="text-muted-foreground">
            {t("employee.attendance.legend", "Legend:")}
          </span>
          <LegendDot
            color="green"
            label={t("employee.attendance.present", "Present")}
          />
          <LegendDot
            color="amber"
            label={t("employee.attendance.late", "Late")}
          />
          <LegendDot
            color="red"
            label={t("employee.attendance.absent", "Absent")}
          />
          <LegendDot
            color="gray"
            label={t("employee.attendance.weekend", "Weekend / no data")}
          />
        </div>

        {selectedDay && (
          <div className="mt-6 rounded-lg border p-4">
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="font-semibold text-foreground">
                  {selectedDay.date}
                </p>
                <p className="text-xs text-muted-foreground capitalize">
                  {selectedDay.status}
                  {selectedDay.source && ` · ${selectedDay.source}`}
                </p>
              </div>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => setSelectedDay(null)}
              >
                <X className="h-3 w-3" />
              </Button>
            </div>
            {selectedDay.check_in && (
              <div className="mt-3 grid gap-2 sm:grid-cols-3 text-sm">
                <div className="flex items-center gap-2">
                  <ClockIcon className="h-3 w-3 text-status-success" />
                  <span className="text-muted-foreground">
                    {t("employee.attendance.in", "In:")}
                  </span>
                  <span className="font-mono">{selectedDay.check_in}</span>
                </div>
                <div className="flex items-center gap-2">
                  <ClockIcon className="h-3 w-3 text-status-warning" />
                  <span className="text-muted-foreground">
                    {t("employee.attendance.out", "Out:")}
                  </span>
                  <span className="font-mono">
                    {selectedDay.check_out ?? "—"}
                  </span>
                </div>
                {selectedDay.worked_minutes != null && (
                  <div className="flex items-center gap-2">
                    <CalendarRange className="h-3 w-3 text-status-info" />
                    <span className="text-muted-foreground">
                      {t("employee.attendance.worked", "Worked:")}
                    </span>
                    <span className="font-mono">
                      {(selectedDay.worked_minutes / 60).toFixed(1)}h
                    </span>
                  </div>
                )}
              </div>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

/**
 * Soft-container families rather than `bg-status-x/10 text-status-x`. Tinting
 * a colour and then setting text in that same colour is contrast-neutral by
 * construction — the same defect corrected in `Badge` and the avatar chips
 * (see UX_PHASE_F.md F-19). These pairs are verified in all three themes.
 */
const TOTAL_COLORS: Record<string, string> = {
  green: "bg-success-soft text-success-on-soft",
  amber: "bg-warning-soft text-warning-on-soft",
  red: "bg-destructive-soft text-destructive-on-soft",
  blue: "bg-info-soft text-info-on-soft",
};

function TotalChip({
  color,
  label,
  value,
  suffix,
}: {
  color: string;
  label: string;
  value: string | number;
  suffix?: string;
}) {
  return (
    <div className={cn("rounded-lg p-3", TOTAL_COLORS[color])}>
      <p className="text-xs uppercase tracking-wider opacity-70">{label}</p>
      <p className="mt-1 text-2xl font-bold">
        {value}
        {suffix && <span className="text-sm ml-1">{suffix}</span>}
      </p>
    </div>
  );
}

const LEGEND_DOT_COLORS: Record<string, string> = {
  green: "bg-status-success",
  amber: "bg-status-warning",
  red: "bg-status-error",
  gray: "bg-muted",
};

function LegendDot({ color, label }: { color: string; label: string }) {
  return (
    <span className="flex items-center gap-1.5">
      <span className={cn("h-3 w-3 rounded-sm", LEGEND_DOT_COLORS[color])} />
      <span className="text-muted-foreground">{label}</span>
    </span>
  );
}
