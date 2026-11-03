"use client";

import { useMemo } from "react";
import {
  periodFor,
  todayIn,
  type DateRange,
} from "@/app/(dashboard)/analytics/period";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useTenantTimezone } from "@/lib/hooks/useTenantTimezone";

/**
 * The reader's current month as the API takes it, the window the Analytics
 * page sends for "This month".
 *
 * The dashboard tiles and chart used to send no period and take the API's
 * default, which was the Gregorian month, while the Analytics page built
 * Tikimt from `period.ts`: the same organisation read 45% attendance on the
 * Organisation today tile and 84.7% on Analytics for the same "this month"
 * (dashboard review, 2026-11-03). Both now send the same dates, so every
 * "this month" on the dashboard is the month the reader's calendar names.
 */
export function useThisMonth(): DateRange {
  const { calendar } = useCalendar();
  const timeZone = useTenantTimezone();
  const today = todayIn(timeZone);

  return useMemo(
    () => periodFor("this_month", calendar, today).current,
    [calendar, today],
  );
}
