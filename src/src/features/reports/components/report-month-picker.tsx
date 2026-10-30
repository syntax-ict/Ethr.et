"use client";

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { CalendarSystem } from "@/lib/calendar/calendar-context";
import {
  daysInEthiopianMonth,
  ethiopianMonthName,
  toGregorian,
  todayEthiopian,
} from "@/lib/calendar/ethiopian";
import { useT } from "@/lib/i18n/useT";

/** A month in one calendar: Ethiopian months run 1 to 13 (Pagume). */
export interface ReportMonth {
  calendar: CalendarSystem;
  year: number;
  month: number;
}

/** The month of today, in the given calendar. */
export function currentReportMonth(calendar: CalendarSystem): ReportMonth {
  if (calendar === "ethiopian") {
    const today = todayEthiopian();
    return { calendar, year: today.year, month: today.month };
  }
  const now = new Date();
  return { calendar, year: now.getFullYear(), month: now.getMonth() + 1 };
}

function iso(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/**
 * The month's first and last day as Gregorian dates, which is what the report
 * engine reads. Tikimt 2019 is 2026-10-11 to 2026-11-09.
 */
export function reportMonthRange(month: ReportMonth): {
  from: string;
  to: string;
} {
  if (month.calendar === "ethiopian") {
    return {
      from: iso(toGregorian(month.year, month.month, 1)),
      to: iso(
        toGregorian(
          month.year,
          month.month,
          daysInEthiopianMonth(month.year, month.month),
        ),
      ),
    };
  }
  const last = new Date(month.year, month.month, 0).getDate();
  const mm = String(month.month).padStart(2, "0");
  return {
    from: `${month.year}-${mm}-01`,
    to: `${month.year}-${mm}-${String(last).padStart(2, "0")}`,
  };
}

/**
 * Month and year in the viewer's calendar. The browser's month input offered
 * only Gregorian months, in English, on a screen set to the Ethiopian
 * calendar, so Tikimt could not be chosen at all.
 */
export function ReportMonthPicker({
  value,
  onChange,
}: {
  value: ReportMonth;
  onChange: (value: ReportMonth) => void;
}) {
  const { t, locale } = useT();
  const ethiopian = value.calendar === "ethiopian";
  const current = currentReportMonth(value.calendar).year;
  const years = [current - 3, current - 2, current - 1, current];
  const months = Array.from({ length: ethiopian ? 13 : 12 }, (_, i) => i + 1);
  const gregorianName = new Intl.DateTimeFormat(locale === "am" ? "am" : "en", {
    month: "long",
  });

  return (
    <div className="flex gap-2">
      <Select
        value={String(value.month)}
        onValueChange={(month) => onChange({ ...value, month: Number(month) })}
      >
        <SelectTrigger
          className="h-9 w-40"
          aria-label={t("reports_page.fields.month", "Month")}
        >
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {months.map((month) => (
            <SelectItem key={month} value={String(month)}>
              {ethiopian
                ? ethiopianMonthName(month, locale)
                : gregorianName.format(new Date(2026, month - 1, 1))}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <Select
        value={String(value.year)}
        onValueChange={(year) => onChange({ ...value, year: Number(year) })}
      >
        <SelectTrigger
          className="h-9 w-28"
          aria-label={t("reports_page.fields.year", "Year")}
        >
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {years.map((year) => (
            <SelectItem key={year} value={String(year)}>
              {year}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}
