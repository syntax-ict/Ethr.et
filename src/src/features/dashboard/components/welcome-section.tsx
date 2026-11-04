"use client";

import { useCurrentUser, useCurrentTenant } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";
import { givenName } from "@/lib/given-name";
import { Skeleton } from "@/components/ui/skeleton";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { QuickActions } from "./quick-actions";

// quiet-on-error: a greeting; a failed /auth/me is a sign-out handled by the API client, and the time line is decoration.

/** The greeting for the hour where the organisation is, not where the browser is. */
function getGreetingKey(timeZone: string): string {
  const hour = Number(
    new Intl.DateTimeFormat("en-GB", {
      hour: "numeric",
      hourCycle: "h23",
      timeZone,
    }).format(new Date()),
  );
  if (hour < 12) return "dashboard.good_morning";
  if (hour < 17) return "dashboard.good_afternoon";
  return "dashboard.good_evening";
}

/**
 * The dashboard's page header.
 *
 * /dashboard hides the shared PageTitleBar (see components/layouts/
 * page-title-bar.tsx) because this block is the page's h1. Actions sit
 * top-right, matching the position PageActions uses on every other page, so
 * the primary action lives in one predictable place product-wide (Nielsen #4).
 */
export function WelcomeSection() {
  const { t, locale } = useT();
  const { data: user, isLoading } = useCurrentUser();
  const { data: tenant } = useCurrentTenant();
  const { formatDate } = useCalendar();
  const { timeZone } = useDateFormatters();

  const greeting = t(getGreetingKey(timeZone), "Welcome back");
  const displayName =
    locale === "am" && user?.name_am ? user.name_am : user?.name;
  const firstName = givenName(displayName);
  const dateStr = formatDate(new Date(), locale);

  if (isLoading) {
    return (
      <div className="flex items-center gap-4">
        <Skeleton className="h-12 w-12 rounded-full" />
        <div className="space-y-2">
          <Skeleton className="h-7 w-64" />
          <Skeleton className="h-4 w-48" />
        </div>
      </div>
    );
  }

  return (
    // A page header, not a card: the greeting and the day's actions sit on
    // the page like any other title. The gradient rule and the card around
    // it read as template decoration (QA 2026-10-13).
    <div className="relative">
      {/* Side by side only from xl: at 1024px the four quick actions took
          the row and squeezed the greeting to nothing. */}
      <div className="relative flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-between">
        <div className="flex min-w-0 flex-1 items-center gap-4">
          <EmployeeAvatar
            name={displayName ?? user?.email ?? "User"}
            photoThumbUrl={user?.photo_thumb_url}
            className="h-12 w-12 shrink-0 ring-1 ring-border"
            fallbackClassName="bg-primary-soft text-base font-semibold text-primary-on-soft"
          />
          <div className="min-w-0">
            <h1 className="truncate text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
              {firstName ? `${greeting}, ${firstName}` : greeting}
            </h1>
            <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
              {tenant?.name && (
                <>
                  <span className="font-medium text-foreground/80">
                    {tenant.name}
                  </span>
                  <span
                    aria-hidden="true"
                    className="h-1 w-1 rounded-full bg-border-strong"
                  />
                </>
              )}
              <time dateTime={new Date().toISOString()}>{dateStr}</time>
            </div>
          </div>
        </div>

        {/* The day's attendance state is said once, by the My Day tile, and
            acted on once, by the Check In / Check Out verb below. The chip
            that sat here said it a third time (dashboard review, 2026-11-03,
            PR 7). */}
        <div className="flex min-w-0 flex-col items-start gap-3 xl:shrink-0 xl:items-end">
          <QuickActions />
        </div>
      </div>
    </div>
  );
}
