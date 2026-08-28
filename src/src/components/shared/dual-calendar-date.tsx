"use client";

import {
  toEthiopian,
  formatEthiopian,
  type EthiopianDate,
} from "@/lib/calendar/ethiopian";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

interface DualCalendarDateProps {
  date: Date;
  className?: string;
  compact?: boolean;
}

function formatGregorian(date: Date, locale: string): string {
  return new Intl.DateTimeFormat(locale === "am" ? "am-ET" : "en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
  }).format(date);
}

export function DualCalendarDate({
  date,
  className,
  compact = false,
}: DualCalendarDateProps) {
  const { calendar } = useCalendar();
  const { locale } = useT();

  const ethiopian: EthiopianDate = toEthiopian(
    date.getFullYear(),
    date.getMonth() + 1,
    date.getDate(),
  );

  const ethStr = formatEthiopian(ethiopian, locale);
  const gregStr = formatGregorian(date, locale);

  const primary = calendar === "ethiopian" ? ethStr : gregStr;
  const secondary = calendar === "ethiopian" ? gregStr : ethStr;

  if (compact) {
    return (
      <span className={cn("inline-flex items-baseline gap-1.5", className)}>
        <span className="font-medium">{primary}</span>
        <span className="text-xs text-muted-foreground">({secondary})</span>
      </span>
    );
  }

  return (
    <div className={cn("flex flex-col", className)}>
      <span className="text-sm font-medium">{primary}</span>
      <span className="text-xs text-muted-foreground">{secondary}</span>
    </div>
  );
}
