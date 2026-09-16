"use client";

import { useCallback, useMemo } from "react";
import { useCurrentTenant } from "@/features/auth/api";
import {
  DEFAULT_TIMEZONE,
  resolveTimeZone,
  formatDate,
  formatDateTime,
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
 * The date formatters, bound to the tenant's timezone.
 *
 * Components call these instead of importing the raw helpers, so a screen
 * cannot forget to pass the zone. The raw helpers stay pure and default to
 * `Africa/Addis_Ababa`, so a call site that has not been migrated is still
 * correct for the common case rather than falling back to the browser.
 */
export function useDateFormatters() {
  const timeZone = useTenantTimezone();

  return {
    timeZone,
    formatDate: useCallback(
      (dateStr: string) => formatDate(dateStr, timeZone),
      [timeZone],
    ),
    formatDateTime: useCallback(
      (dateStr: string) => formatDateTime(dateStr, timeZone),
      [timeZone],
    ),
    formatTime: useCallback(
      (dateStr: string) => formatTime(dateStr, timeZone),
      [timeZone],
    ),
    timeAgo: useCallback(
      (dateStr: string) => timeAgo(dateStr, timeZone),
      [timeZone],
    ),
  };
}
