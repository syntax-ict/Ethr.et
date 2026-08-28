"use client";

import { useMemo, useState } from "react";
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { useT } from "@/lib/i18n/useT";
import { localizedName } from "@/lib/i18n/localizedName";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";

import { Skeleton } from "@/components/ui/skeleton";
import { CalendarDays, ChevronLeft, ChevronRight, Star } from "lucide-react";
import { useCalendar } from "@/lib/calendar/calendar-context";
import {
  toGregorian,
  formatEthiopianMonthYear,
  daysInEthiopianMonth,
  nextEthiopianMonth,
  prevEthiopianMonth,
  todayEthiopian,
} from "@/lib/calendar/ethiopian";
import { cn } from "@/lib/utils";

const WEEKDAYS_EN = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];
const WEEKDAYS_AM = ["እሁድ", "ሰኞ", "ማክሰ", "ረቡዕ", "ሐሙስ", "አርብ", "ቅዳሜ"];

function getDaysInGregorianMonth(year: number, month: number): number {
  return new Date(year, month + 1, 0).getDate();
}

function getFirstDayOfWeek(year: number, month: number): number {
  return new Date(year, month, 1).getDay();
}

function GregorianGrid({
  viewYear,
  viewMonth,
  holidays,
  locale,
}: {
  viewYear: number;
  viewMonth: number;
  holidays: Map<string, string>;
  locale: string;
}) {
  const weekdays = locale === "am" ? WEEKDAYS_AM : WEEKDAYS_EN;
  const now = new Date();
  const isCurrentMonth =
    viewYear === now.getFullYear() && viewMonth === now.getMonth();
  const today = now.getDate();
  const daysInMonth = getDaysInGregorianMonth(viewYear, viewMonth);
  const firstDay = getFirstDayOfWeek(viewYear, viewMonth);

  return (
    <>
      <div className="mb-1 grid grid-cols-7 text-center">
        {weekdays.map((d) => (
          <span
            key={d}
            className="py-1.5 text-[11px] font-medium text-muted-foreground"
          >
            {d}
          </span>
        ))}
      </div>
      <div className="grid grid-cols-7 text-center">
        {Array.from({ length: firstDay }).map((_, i) => (
          <span key={`pad-${i}`} />
        ))}
        {Array.from({ length: daysInMonth }).map((_, i) => {
          const day = i + 1;
          const key = `${viewYear}-${viewMonth}-${day}`;
          const holidayName = holidays.get(key);
          const isToday = isCurrentMonth && day === today;

          return (
            <button
              type="button"
              key={day}
              title={holidayName ?? undefined}
              className={cn(
                "relative mx-auto flex h-8 w-8 items-center justify-center rounded-lg text-sm transition-colors",
                isToday
                  ? "bg-interactive-primary font-semibold text-primary-foreground shadow-sm"
                  : holidayName
                    ? "bg-brand-accent/10 font-medium text-brand-accent"
                    : "text-foreground hover:bg-muted",
              )}
            >
              {day}
              {holidayName && !isToday && (
                <Star className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 fill-brand-accent text-brand-accent" />
              )}
            </button>
          );
        })}
      </div>
    </>
  );
}

function EthiopianGrid({
  ethYear,
  ethMonth,
  holidays,
  locale,
}: {
  ethYear: number;
  ethMonth: number;
  holidays: Map<string, string>;
  locale: string;
}) {
  const weekdays = locale === "am" ? WEEKDAYS_AM : WEEKDAYS_EN;
  const ethToday = todayEthiopian();
  const isCurrentMonth =
    ethYear === ethToday.year && ethMonth === ethToday.month;
  const days = daysInEthiopianMonth(ethYear, ethMonth);

  const firstDayGreg = toGregorian(ethYear, ethMonth, 1);
  const firstDayOfWeek = firstDayGreg.getDay();

  return (
    <>
      <div className="mb-1 grid grid-cols-7 text-center">
        {weekdays.map((d) => (
          <span
            key={d}
            className="py-1.5 text-[11px] font-medium text-muted-foreground"
          >
            {d}
          </span>
        ))}
      </div>
      <div className="grid grid-cols-7 text-center">
        {Array.from({ length: firstDayOfWeek }).map((_, i) => (
          <span key={`pad-${i}`} />
        ))}
        {Array.from({ length: days }).map((_, i) => {
          const day = i + 1;
          const gregDate = toGregorian(ethYear, ethMonth, day);
          const gKey = `${gregDate.getFullYear()}-${gregDate.getMonth()}-${gregDate.getDate()}`;
          const holidayName = holidays.get(gKey);
          const isToday = isCurrentMonth && day === ethToday.day;

          return (
            <button
              type="button"
              key={day}
              title={holidayName ?? undefined}
              className={cn(
                "relative mx-auto flex h-8 w-8 items-center justify-center rounded-lg text-sm transition-colors",
                isToday
                  ? "bg-interactive-primary font-semibold text-primary-foreground shadow-sm"
                  : holidayName
                    ? "bg-brand-accent/10 font-medium text-brand-accent"
                    : "text-foreground hover:bg-muted",
              )}
            >
              {day}
              {holidayName && !isToday && (
                <Star className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 fill-brand-accent text-brand-accent" />
              )}
            </button>
          );
        })}
      </div>
    </>
  );
}

export function CalendarWidget() {
  const { t, locale } = useT();
  const { data, isLoading } = useEmployeeDashboard();
  const { calendar } = useCalendar();

  const [gregOffset, setGregOffset] = useState(0);
  const [ethView, setEthView] = useState<{ year: number; month: number }>(
    () => {
      const today = todayEthiopian();
      return { year: today.year, month: today.month };
    },
  );

  const now = new Date();
  const gregYear = new Date(
    now.getFullYear(),
    now.getMonth() + gregOffset,
    1,
  ).getFullYear();
  const gregMonth = new Date(
    now.getFullYear(),
    now.getMonth() + gregOffset,
    1,
  ).getMonth();

  const holidays = useMemo(() => {
    const map = new Map<string, string>();
    (data?.upcoming_holidays ?? []).forEach((h) => {
      const d = new Date(h.date);
      const key = `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
      map.set(key, localizedName(h, locale));
    });
    return map;
  }, [data?.upcoming_holidays, locale]);

  const isEthMode = calendar === "ethiopian";

  const ethToday = todayEthiopian();
  const isEthCurrentMonth =
    ethView.year === ethToday.year && ethView.month === ethToday.month;
  const isGregCurrentMonth = gregOffset === 0;

  function handlePrev() {
    if (isEthMode) {
      setEthView((v) => prevEthiopianMonth(v.year, v.month));
    } else {
      setGregOffset((o) => o - 1);
    }
  }

  function handleNext() {
    if (isEthMode) {
      setEthView((v) => nextEthiopianMonth(v.year, v.month));
    } else {
      setGregOffset((o) => o + 1);
    }
  }

  function handleToday() {
    if (isEthMode) {
      const today = todayEthiopian();
      setEthView({ year: today.year, month: today.month });
    } else {
      setGregOffset(0);
    }
  }

  const showTodayBtn = isEthMode ? !isEthCurrentMonth : !isGregCurrentMonth;

  let monthName: string;
  let monthSubtitle: string | null = null;

  if (isEthMode) {
    monthName = formatEthiopianMonthYear(ethView.year, ethView.month, locale);
    const gregStart = toGregorian(ethView.year, ethView.month, 1);
    monthSubtitle = new Intl.DateTimeFormat(
      locale === "am" ? "am-ET" : "en-US",
      { month: "short", year: "numeric" },
    ).format(gregStart);
  } else {
    monthName = new Intl.DateTimeFormat(locale === "am" ? "am-ET" : "en-US", {
      month: "long",
      year: "numeric",
    }).format(new Date(gregYear, gregMonth, 1));
  }

  if (isLoading) {
    return (
      <Card>
        <CardHeader className="pb-3">
          <Skeleton className="h-5 w-36" />
        </CardHeader>
        <CardContent>
          <Skeleton className="h-48 rounded-lg" />
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className="transition-shadow duration-300 hover:shadow-md">
      <CardHeader className="flex flex-row items-center justify-between pb-2">
        <div className="flex items-center gap-2">
          <CalendarDays className="h-4 w-4 text-muted-foreground" />
          <div>
            <CardTitle className="text-sm font-semibold">{monthName}</CardTitle>
            {monthSubtitle && (
              <p className="text-[10px] text-muted-foreground">
                {monthSubtitle}
              </p>
            )}
          </div>
        </div>
        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon"
            className="h-7 w-7"
            onClick={handlePrev}
            aria-label={t("calendar.previous_month", "Previous month")}
          >
            <ChevronLeft className="h-3.5 w-3.5" aria-hidden="true" />
          </Button>
          {showTodayBtn && (
            <Button
              variant="ghost"
              size="sm"
              className="h-7 px-2 text-xs"
              onClick={handleToday}
            >
              {t("calendar.today", "Today")}
            </Button>
          )}
          <Button
            variant="ghost"
            size="icon"
            className="h-7 w-7"
            onClick={handleNext}
            aria-label={t("calendar.next_month", "Next month")}
          >
            <ChevronRight className="h-3.5 w-3.5" aria-hidden="true" />
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {isEthMode ? (
          <EthiopianGrid
            ethYear={ethView.year}
            ethMonth={ethView.month}
            holidays={holidays}
            locale={locale}
          />
        ) : (
          <GregorianGrid
            viewYear={gregYear}
            viewMonth={gregMonth}
            holidays={holidays}
            locale={locale}
          />
        )}

        {(data?.upcoming_holidays ?? []).length > 0 && (
          <div className="mt-3 border-t border-border/60 pt-3">
            <p className="mb-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t("dashboard.upcoming_holidays", "Upcoming Holidays")}
            </p>
            <div className="space-y-1.5">
              {(data?.upcoming_holidays ?? []).slice(0, 3).map((h, i) => (
                <div
                  key={i}
                  className="flex items-center justify-between rounded-lg px-2 py-1.5 text-xs transition-colors hover:bg-muted/50"
                >
                  <div className="flex items-center gap-2">
                    <span className="h-1.5 w-1.5 rounded-full bg-brand-accent" />
                    <span className="font-medium text-foreground">
                      {localizedName(h, locale)}
                    </span>
                  </div>
                  <span className="tabular-nums text-muted-foreground">
                    {h.date}
                  </span>
                </div>
              ))}
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
