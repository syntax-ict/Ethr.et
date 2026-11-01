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
import { PageActions } from "@/components/shared/page-actions";
import { RoleGate } from "@/components/shared/role-gate";
import { useShiftSchedule, useShifts } from "@/features/shifts/api";
import {
  addDaysIso,
  localIsoDate,
  mondayOf,
  parseIsoDate,
} from "@/features/shifts/dates";
import { buildRoster } from "@/features/shifts/roster";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import type { CalendarSystem } from "@/lib/calendar/calendar-context";
import {
  daysInEthiopianMonth,
  toEthiopian,
  toGregorian,
} from "@/lib/calendar/ethiopian";
import {
  monthLabel,
  monthOf,
  type CalendarMonth,
} from "@/app/(dashboard)/analytics/period";
import { cn } from "@/lib/utils";
import Link from "next/link";
import { ErrorState } from "@/components/shared/error-state";

// Shift colour palette — deterministic by shift name hash
const SHIFT_COLOURS = [
  "bg-info-soft text-info-on-soft border-status-info/20",
  "bg-success-soft text-success-on-soft border-status-success/20",
  "bg-primary-soft text-primary-on-soft border-primary-edge",
  "bg-warning-soft text-warning-on-soft border-status-warning/20",
  "bg-destructive-soft text-destructive-on-soft border-status-error/20",
  // Gold stays gold through the brand soft pair. The raw accent as text on its
  // own tint measured 1.93:1 (light) and 2.93:1 (high contrast) in the UX audit
  // baseline, 2026-10-20; the plan reserves the accent for marks, not text.
  "bg-brand-soft text-brand-on-soft border-brand-edge",
];

function shiftColour(name: string): string {
  let hash = 0;
  for (let i = 0; i < name.length; i++)
    hash = name.charCodeAt(i) + ((hash << 5) - hash);
  return SHIFT_COLOURS[Math.abs(hash) % SHIFT_COLOURS.length];
}

/** A date on each weekday, Sunday first (`getDay()` order), for naming it. */
const WEEKDAY_DATES = [
  "2024-01-07",
  "2024-01-01",
  "2024-01-02",
  "2024-01-03",
  "2024-01-04",
  "2024-01-05",
  "2024-01-06",
];

/**
 * The first and last Gregorian dates of a month in the reader's calendar:
 * Tikimt runs 30 days from 11 October, not 1 to 31 October.
 */
function monthFirstDay(m: CalendarMonth, calendar: CalendarSystem): string {
  if (calendar === "ethiopian") {
    return localIsoDate(toGregorian(m.year, m.month, 1));
  }
  const first = new Date(2000, 0, 1);
  first.setFullYear(m.year, m.month - 1, 1);
  return localIsoDate(first);
}

function monthLastDay(m: CalendarMonth, calendar: CalendarSystem): string {
  if (calendar === "ethiopian") {
    return localIsoDate(
      toGregorian(m.year, m.month, daysInEthiopianMonth(m.year, m.month)),
    );
  }
  const last = new Date(2000, 0, 1);
  last.setFullYear(m.year, m.month, 0);
  return localIsoDate(last);
}

/** The day of the month a date is, in the reader's calendar. */
function dayNumber(date: Date, calendar: CalendarSystem): number {
  if (calendar !== "ethiopian") return date.getDate();
  return toEthiopian(date.getFullYear(), date.getMonth() + 1, date.getDate())
    .day;
}

export default function RosterPage() {
  const { t, locale } = useT();
  const {
    calendar,
    formatDate,
    formatDayMonth,
    formatTimeOfDay,
    formatWeekday,
  } = useDateFormatters();
  const today = new Date();
  const todayKey = localIsoDate(today);
  // Any day of the month on screen. A date rather than a month number, so the
  // view follows the reader's calendar even when it loads after this does.
  const [anchor, setAnchor] = useState(todayKey);
  const [view, setView] = useState<"month" | "week">("month");

  // For week view: the Monday of the week being shown. Computed as
  // `getDate() - getDay() + 1`, a Sunday (getDay() 0) jumped to the *next*
  // Monday, so on Sundays "this week" showed the following one.
  const [weekStart, setWeekStart] = useState(() => mondayOf(today));

  const monthFrom = monthFirstDay(monthOf(anchor, calendar), calendar);
  const monthTo = monthLastDay(monthOf(anchor, calendar), calendar);

  const weekFrom = localIsoDate(weekStart);
  const weekTo = addDaysIso(weekFrom, 6);

  const dateFrom = view === "month" ? monthFrom : weekFrom;
  const dateTo = view === "month" ? monthTo : weekTo;

  const {
    data: scheduleData,
    isLoading: scheduleLoading,
    isError: scheduleError,
    refetch: refetchSchedule,
  } = useShiftSchedule({
    date_from: dateFrom,
    date_to: dateTo,
  });

  const { data: shiftsData } = useShifts();

  const assignments = useMemo(() => scheduleData?.data ?? [], [scheduleData]);
  const allShifts = shiftsData?.data ?? [];
  const defaultShift = allShifts.find((s) => s.is_default && s.is_active);

  const dateShiftMap = useMemo(
    () => buildRoster(assignments, dateFrom, dateTo),
    [assignments, dateFrom, dateTo],
  );

  // ── Month view ──
  const monthDays = useMemo(() => {
    const first = parseIsoDate(monthFrom).getDay(); // 0=Sun
    const days: (Date | null)[] = Array(first).fill(null);
    for (let day = monthFrom; day <= monthTo; day = addDaysIso(day, 1)) {
      days.push(parseIsoDate(day));
    }
    return days;
  }, [monthFrom, monthTo]);

  // ── Week view ──
  const weekDays = useMemo(() => {
    return Array.from({ length: 7 }, (_, i) => {
      const d = new Date(weekStart);
      d.setDate(d.getDate() + i);
      return d;
    });
  }, [weekStart]);

  // The day before this month starts is in the previous one; the day after
  // it ends, in the next.
  function prevMonth() {
    setAnchor(addDaysIso(monthFrom, -1));
  }
  function nextMonth() {
    setAnchor(addDaysIso(monthTo, 1));
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
    const key = localIsoDate(date);
    const shifts = dateShiftMap[key] ?? [];
    const isToday = key === todayKey;
    const isPast = key < todayKey;

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
          {dayNumber(date, calendar)}
        </p>
        {shifts.slice(0, 2).map((s, i) => (
          <div
            key={i}
            className={cn(
              "mb-1 rounded px-1.5 py-0.5 text-xs font-medium truncate border",
              shiftColour(s.name),
            )}
            title={`${s.name} ${formatTimeOfDay(s.start)}–${formatTimeOfDay(s.end)} (${s.type})`}
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
        <PageActions
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
                ? monthLabel(monthOf(anchor, calendar), calendar, locale)
                : `${formatDayMonth(weekFrom)} – ${formatDate(weekTo)}`}
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
                setAnchor(todayKey);
                setWeekStart(mondayOf(today));
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
                    {formatTimeOfDay(s.start_time)}–
                    {formatTimeOfDay(s.end_time)}
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
        ) : scheduleError ? (
          <ErrorState onRetry={() => refetchSchedule()} />
        ) : view === "month" ? (
          // ── Month grid ──
          <div>
            <div className="grid grid-cols-7 gap-1 mb-1">
              {WEEKDAY_DATES.map((d) => (
                <div
                  key={d}
                  className="text-center text-xs font-medium text-muted-foreground py-1"
                >
                  {formatWeekday(d)}
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
              const key = localIsoDate(date);
              const shifts = dateShiftMap[key] ?? [];
              const isToday = key === todayKey;
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
                      <div>{formatWeekday(key)}</div>
                      <div className="text-xl">{dayNumber(date, calendar)}</div>
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
                          {formatTimeOfDay(s.start)}–{formatTimeOfDay(s.end)}
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
                          {formatTimeOfDay(defaultShift.start_time)}–
                          {formatTimeOfDay(defaultShift.end_time)}
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
