"use client";

import { useCurrentUser, useCurrentTenant } from "@/features/auth/api";
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { useT } from "@/lib/i18n/useT";
import { Skeleton } from "@/components/ui/skeleton";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { Clock } from "lucide-react";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { cn } from "@/lib/utils";
import { QuickActions } from "./quick-actions";

function getGreetingKey(): string {
  const hour = new Date().getHours();
  if (hour < 12) return "dashboard.good_morning";
  if (hour < 17) return "dashboard.good_afternoon";
  return "dashboard.good_evening";
}

/**
 * The dashboard's page header.
 *
 * /dashboard hides the shared PageTitleBar (see components/layouts/
 * page-title-bar.tsx) because this block is the page's h1. Actions sit
 * top-right, matching the position PageHeader uses on every other page, so
 * the primary action lives in one predictable place product-wide (Nielsen #4).
 */
export function WelcomeSection() {
  const { t, locale } = useT();
  const { data: user, isLoading } = useCurrentUser();
  const { data: tenant } = useCurrentTenant();
  const { data: dashData } = useEmployeeDashboard();
  const { formatDate } = useCalendar();
  const { formatTime } = useDateFormatters();

  const greeting = t(getGreetingKey(), "Welcome back");
  const displayName =
    locale === "am" && user?.name_am ? user.name_am : user?.name;
  const firstName = displayName?.split(" ")[0];
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

  const attendance = dashData?.attendance_today;
  const isCheckedIn = attendance?.status === "checked_in";

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

        <div className="flex min-w-0 flex-col items-start gap-3 xl:shrink-0 xl:items-end">
          {attendance && (
            <div
              className={cn(
                "flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-medium transition-colors",
                isCheckedIn
                  ? "border-success-edge bg-success-soft text-success-on-soft"
                  : "border-border bg-muted text-muted-foreground",
              )}
            >
              <Clock className="h-3.5 w-3.5" />
              {isCheckedIn
                ? t("dashboard.checked_in", "Checked In")
                : t("dashboard.not_checked_in", "Not Checked In")}
              {isCheckedIn && attendance.check_in && (
                <span className="tabular-nums opacity-70">
                  {/* A UTC instant from the datetime cast; show wall-clock time. */}
                  {formatTime(attendance.check_in)}
                </span>
              )}
            </div>
          )}

          <QuickActions />
        </div>
      </div>
    </div>
  );
}
