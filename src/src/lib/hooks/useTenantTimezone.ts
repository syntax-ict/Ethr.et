"use client";

import { useCallback, useMemo } from "react";
import { useCurrentTenant } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";
import { useOptionalCalendar } from "@/lib/calendar/calendar-context";
import {
  DEFAULT_TIMEZONE,
  resolveTimeZone,
  formatCalendarDate,
  formatCalendarDateTime,
  formatTime,
  timeAgo,
} from "@/lib/utils/date";

/**
 * The tenant's configured timezone, or `Africa/Addis_Ababa`.
 *
 * Convention #2 displays timestamps in the tenant's zone, not the browser's.
 * `/auth/me` already carries `tenant.timezone` — `TenantResource` has always
 * returned it — so this needs no new request, no provider and no prop drilling:
 * it reads the same cached TanStack query every screen already holds.
 *
 * Before the tenant loads, or for an unauthenticated view, this is the default.
 * That is deliberate: the alternative is rendering in the host timezone while
 * waiting, which is the bug this replaces (`docs/audit/BASELINE.md` §12g).
 */
export function useTenantTimezone(): string {
  const { data: tenant } = useCurrentTenant();

  return useMemo(
    () => resolveTimeZone(tenant?.timezone ?? DEFAULT_TIMEZONE),
    [tenant?.timezone],
  );
}

/**
 * The date formatters, bound to the tenant's timezone and the reader's
 * calendar and language.
 *
 * Components call these instead of importing the raw helpers, so a screen
 * cannot forget to pass the zone. The raw helpers stay pure and default to
 * `Africa/Addis_Ababa`, so a call site that has not been migrated is still
 * correct for the common case rather than falling back to the browser.
 *
 * Dates follow the Ethiopian or Gregorian choice from `CalendarProvider`
 * (FRONTEND_REDESIGN_PLAN.md, Phase 2). Outside a provider they stay
 * Gregorian, as they always were.
 */
export function useDateFormatters() {
  const { t, locale } = useT();
  const timeZone = useTenantTimezone();
  const calendar = useOptionalCalendar()?.calendar ?? "gregorian";

  const formatDate = useCallback(
    (dateStr: string) =>
      formatCalendarDate(dateStr, timeZone, calendar, locale),
    [timeZone, calendar, locale],
  );

  return {
    timeZone,
    formatDate,
    formatDateTime: useCallback(
      (dateStr: string) =>
        formatCalendarDateTime(dateStr, timeZone, calendar, locale),
      [timeZone, calendar, locale],
    ),
    formatTime: useCallback(
      (dateStr: string) => formatTime(dateStr, timeZone),
      [timeZone],
    ),
    timeAgo: useCallback(
      (dateStr: string) => timeAgo(dateStr, timeZone, t, formatDate),
      [timeZone, t, formatDate],
    ),
  };
}
