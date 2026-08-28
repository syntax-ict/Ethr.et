"use client";

import { useMemo, useState } from "react";
import { ChevronLeft, ChevronRight, CalendarDays, Clock } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import Link from "next/link";

// Shift colour palette — deterministic by shift name hash
const SHIFT_COLOURS = [
  "bg-info-soft text-info-on-soft border-status-info/20",
  "bg-success-soft text-success-on-soft border-status-success/20",
  "bg-primary-soft text-primary-on-soft border-primary-edge",
  "bg-warning-soft text-warning-on-soft border-status-warning/20",
  "bg-destructive-soft text-destructive-on-soft border-status-error/20",
  "bg-brand-accent/10 text-brand-accent border-brand-accent/20",
];

function shiftColour(name: string): string {
  let hash = 0;
  for (let i = 0; i < name.length; i++)
    hash = name.charCodeAt(i) + ((hash << 5) - hash);
  return SHIFT_COLOURS[Math.abs(hash) % SHIFT_COLOURS.length];
}

const MONTH_NAMES = [
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December",
];

const DAY_SHORT = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

function startOfMonth(y: number, m: number): Date {
  return new Date(y, m, 1);
}

function daysInMonth(y: number, m: number): number {
  return new Date(y, m + 1, 0).getDate();
}

function isoDate(d: Date): string {
  return d.toISOString().slice(0, 10);
}

interface ShiftAssignment {
  shift?: {
    name: string;
    start_time: string;
    end_time: string;
    working_days: string;
  } | null;
  assignable_type: string;
  effective_from: string;
  effective_to: string | null;
}

interface Shift {
  public_id: string;
  name: string;
  start_time: string;
  end_time: string;
  working_days: string;
  is_active: boolean;
  is_default: boolean;
}

export default function RosterPage() {
  const { t } = useT();
  const today = new Date();
  const [year, setYear] = useState(today.getFullYear());
  const [month, setMonth] = useState(today.getMonth());
  const [view, setView] = useState<"month" | "week">("month");

  // For week view: track which week's Monday
  const [weekStart, setWeekStart] = useState(() => {
    const d = new Date(today);
    d.setDate(d.getDate() - d.getDay() + 1); // Monday
    return d;
  });

  const monthFrom = `${year}-${String(month + 1).padStart(2, "0")}-01`;
  const monthTo = isoDate(new Date(year, month + 1, 0));

  const weekFrom = isoDate(weekStart);
  const weekEnd = new Date(weekStart);
  weekEnd.setDate(weekEnd.getDate() + 6);
  const weekTo = isoDate(weekEnd);

  const dateFrom = view === "month" ? monthFrom : weekFrom;
  const dateTo = view === "month" ? monthTo : weekTo;

  const { data: scheduleData, isLoading: scheduleLoading } = useQuery({
    queryKey: ["shifts", "schedule", dateFrom, dateTo],
    queryFn: async () =>
      (
        await apiClient.get(
          `/shifts/schedule?filter[date_from]=${dateFrom}&filter[date_to]=${dateTo}&per_page=200`,
        )
      ).data,
  });

  const { data: shiftsData } = useQuery({
    queryKey: ["shifts"],
    queryFn: async () => (await apiClient.get("/shifts?per_page=100")).data,
  });

  const assignments: ShiftAssignment[] = useMemo(
    () => scheduleData?.data ?? [],
    [scheduleData],
  );
  const allShifts: Shift[] = shiftsData?.data ?? [];
  const defaultShift = allShifts.find((s) => s.is_default && s.is_active);

  // Build a map: date string → array of assignment-applicable shifts
  const dateShiftMap = useMemo(() => {
    const map: Record<
      string,
      { name: string; start: string; end: string; type: string }[]
    > = {};

    assignments.forEach((a) => {
      if (!a.shift) return;
      const from = new Date(a.effective_from);
      const to = a.effective_to
        ? new Date(a.effective_to)
        : new Date("2099-12-31");
      const workingDays = a.shift.working_days?.split(",").map(Number) ?? [];

      const cursor = new Date(from);
      const rangeEnd = new Date(
        Math.min(to.getTime(), new Date(dateTo).getTime()),
      );
      const rangeStart = new Date(
        Math.max(from.getTime(), new Date(dateFrom).getTime()),
      );
      cursor.setTime(rangeStart.getTime());

      while (cursor <= rangeEnd) {
        const dow = cursor.getDay() === 0 ? 7 : cursor.getDay(); // Mon=1..Sun=7
        if (workingDays.includes(dow)) {
          const key = isoDate(cursor);
          if (!map[key]) map[key] = [];
          map[key].push({
            name: a.shift.name,
            start: a.shift.start_time,
            end: a.shift.end_time,
            type: a.assignable_type,
          });
        }
        cursor.setDate(cursor.getDate() + 1);
      }
    });

    return map;
  }, [assignments, dateFrom, dateTo]);

  // ── Month view ──
  const monthDays = useMemo(() => {
    const total = daysInMonth(year, month);
    const first = startOfMonth(year, month).getDay(); // 0=Sun
    const days: (Date | null)[] = Array(first).fill(null);
    for (let d = 1; d <= total; d++) days.push(new Date(year, month, d));
    return days;
  }, [year, month]);

  // ── Week view ──
  const weekDays = useMemo(() => {
    return Array.from({ length: 7 }, (_, i) => {
      const d = new Date(weekStart);
      d.setDate(d.getDate() + i);
      return d;
    });
  }, [weekStart]);

  function prevMonth() {
    if (month === 0) {
      setYear((y) => y - 1);
      setMonth(11);
    } else setMonth((m) => m - 1);
  }
  function nextMonth() {
    if (month === 11) {
      setYear((y) => y + 1);
      setMonth(0);
    } else setMonth((m) => m + 1);
  }
  function prevWeek() {
    setWeekStart((d) => {
      const n = new Date(d);
      n.setDate(n.getDate() - 7);
      return n;
    });
  }
  function nextWeek() {
    setWeekStart((d) => {
      const n = new Date(d);
      n.setDate(n.getDate() + 7);
      return n;
    });
  }

  function DayCell({ date }: { date: Date }) {
    const key = isoDate(date);
    const shifts = dateShiftMap[key] ?? [];
    const isToday = key === isoDate(today);
    const isPast = date < today && !isToday;

    return (
      <div
        className={cn(
          "min-h-[80px] rounded-lg border p-2",
          isToday ? "border-primary bg-primary/5" : "border-border",
          // Past days were dimmed with `opacity-50`, which multiplies down
          // *everything* inside — including the date number and shift names,
          // which measured 2.68:1 in dark and 3.31:1 in light. Opacity is not a
          // safe way to de-emphasise text that still carries information.
          // A recessed background reads as "past" just as clearly and leaves
          // every foreground colour at its designed contrast.
          isPast && "bg-muted/40",
        )}
      >
        <p
          className={cn(
            "text-xs font-medium mb-1",
            isToday ? "text-primary" : "text-muted-foreground",
          )}
        >
          {date.getDate()}
        </p>
        {shifts.slice(0, 2).map((s, i) => (
          <div
            key={i}
            className={cn(
              "mb-1 rounded px-1.5 py-0.5 text-xs font-medium truncate border",
              shiftColour(s.name),
            )}
            title={`${s.name} ${s.start}–${s.end} (${s.type})`}
          >
            {s.name}
          </div>
        ))}
        {shifts.length > 2 && (
          <p className="text-xs text-muted-foreground">
            +{shifts.length - 2} {t("leave_page.more")}
          </p>
        )}
        {shifts.length === 0 && defaultShift && (
          <div
            className={cn(
              "rounded px-1.5 py-0.5 text-xs opacity-40 border",
              shiftColour(defaultShift.name),
            )}
          >
            {defaultShift.name}
          </div>
        )}
      </div>
    );
  }

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("shift_roster_page.title")}
          description={t("shift_roster_page.description")}
          actions={
            <div className="flex gap-2">
              <Link href="/shifts/assignments">
                <Button variant="outline">
                  {t("shift_roster_page.manage_assignments")}
                </Button>
              </Link>
              <Link href="/shifts">
                <Button variant="outline">
                  {t("shift_roster_page.edit_shifts")}
                </Button>
              </Link>
            </div>
          }
        />

        {/* Controls */}
        <div className="flex items-center justify-between gap-4 flex-wrap">
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="icon"
              onClick={view === "month" ? prevMonth : prevWeek}
              aria-label={t("common.previous", "Previous")}
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <span className="min-w-[180px] text-center font-semibold">
              {view === "month"
                ? `${MONTH_NAMES[month]} ${year}`
                : `${weekFrom} – ${weekTo}`}
            </span>
            <Button
              variant="outline"
              size="icon"
              onClick={view === "month" ? nextMonth : nextWeek}
              aria-label={t("common.next", "Next")}
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setYear(today.getFullYear());
                setMonth(today.getMonth());
                const d = new Date(today);
                d.setDate(d.getDate() - d.getDay() + 1);
                setWeekStart(d);
              }}
            >
              {t("shift_roster_page.today")}
            </Button>
          </div>

          <Select
            value={view}
            onValueChange={(v) => setView(v as "month" | "week")}
          >
            <SelectTrigger
              className="w-32"
              aria-label={t("shifts.roster_view", "View")}
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="month">
                <span className="flex items-center gap-2">
                  <CalendarDays className="h-3.5 w-3.5" />{" "}
                  {t("shift_roster_page.month")}
                </span>
              </SelectItem>
              <SelectItem value="week">
                <span className="flex items-center gap-2">
                  <Clock className="h-3.5 w-3.5" />{" "}
                  {t("shift_roster_page.week")}
                </span>
              </SelectItem>
            </SelectContent>
          </Select>
        </div>

        {/* Legend */}
        {allShifts.length > 0 && (
          <div className="flex gap-2 flex-wrap">
            {allShifts
              .filter((s) => s.is_active)
              .map((s) => (
                <div
                  key={s.public_id}
                  className={cn(
                    "flex items-center gap-1.5 rounded px-2 py-1 text-xs font-medium border",
                    shiftColour(s.name),
                  )}
                >
                  {s.name}
                  {/* `opacity-70` here put the shift times at 3.91:1 against
                      the chip's tinted background. The times are the most
                      useful thing in the chip — they stay at full opacity and
                      are set apart by weight instead. */}
                  <span className="font-normal tabular-nums">
                    {s.start_time}–{s.end_time}
                  </span>
                  {s.is_default && (
                    <Badge
                      variant="outline"
                      className="text-[10px] px-1 py-0 h-4"
                    >
                      {t("shift_roster_page.default_lc")}
                    </Badge>
                  )}
                </div>
              ))}
          </div>
        )}

        {scheduleLoading ? (
          <Skeleton className="h-96" />
        ) : view === "month" ? (
          // ── Month grid ──
          <div>
            <div className="grid grid-cols-7 gap-1 mb-1">
              {DAY_SHORT.map((d) => (
                <div
                  key={d}
                  className="text-center text-xs font-medium text-muted-foreground py-1"
                >
                  {d}
                </div>
              ))}
            </div>
            <div className="grid grid-cols-7 gap-1">
              {monthDays.map((date, i) =>
                date ? (
                  <DayCell key={i} date={date} />
                ) : (
                  <div key={i} className="min-h-[80px]" />
                ),
              )}
            </div>
          </div>
        ) : (
          // ── Week grid ──
          <div className="grid grid-cols-7 gap-3">
            {weekDays.map((date) => {
              const key = isoDate(date);
              const shifts = dateShiftMap[key] ?? [];
              const isToday = key === isoDate(today);
              return (
                <Card key={key} className={cn(isToday && "border-primary")}>
                  <CardHeader className="p-3 pb-2">
                    <CardTitle
                      className={cn(
                        "text-sm",
                        isToday
                          ? "text-primary font-bold"
                          : "text-muted-foreground font-medium",
                      )}
                    >
                      <div>{DAY_SHORT[date.getDay()]}</div>
                      <div className="text-xl">{date.getDate()}</div>
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="p-3 pt-0 space-y-1.5">
                    {shifts.map((s, i) => (
                      <div
                        key={i}
                        className={cn(
                          "rounded p-2 text-xs border",
                          shiftColour(s.name),
                        )}
                      >
                        <p className="font-semibold">{s.name}</p>
                        <p className="opacity-80">
                          {s.start}–{s.end}
                        </p>
                        <p className="opacity-60 capitalize">{s.type}</p>
                      </div>
                    ))}
                    {shifts.length === 0 && defaultShift && (
                      <div
                        className={cn(
                          "rounded p-2 text-xs border opacity-40",
                          shiftColour(defaultShift.name),
                        )}
                      >
                        <p className="font-semibold">{defaultShift.name}</p>
                        <p className="opacity-80">
                          {defaultShift.start_time}–{defaultShift.end_time}
                        </p>
                        <p className="opacity-60">
                          {t("shift_roster_page.default_lc")}
                        </p>
                      </div>
                    )}
                    {shifts.length === 0 && !defaultShift && (
                      <p className="text-xs text-muted-foreground py-1">
                        {t("shift_roster_page.no_assignment")}
                      </p>
                    )}
                  </CardContent>
                </Card>
              );
            })}
          </div>
        )}
      </div>
    </RoleGate>
  );
}
