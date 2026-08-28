"use client";

import { useCurrentUser, useCurrentTenant } from "@/features/auth/api";
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { Skeleton } from "@/components/ui/skeleton";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import { Clock, Users, Building2, GitBranch } from "lucide-react";
import { useCalendar } from "@/lib/calendar/calendar-context";
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
  const { can } = usePermissions();
  const { formatDate } = useCalendar();

  const greeting = t(getGreetingKey(), "Welcome back");
  const displayName =
    locale === "am" && user?.name_am ? user.name_am : user?.name;
  const firstName = displayName?.split(" ")[0];
  const dateStr = formatDate(new Date(), locale);

  if (isLoading) {
    return (
      <div className="flex items-center gap-4 rounded-2xl border border-border/60 bg-gradient-to-r from-interactive-primary/[0.04] to-transparent p-6">
        <Skeleton className="h-14 w-14 rounded-2xl" />
        <div className="space-y-2">
          <Skeleton className="h-7 w-64" />
          <Skeleton className="h-4 w-48" />
        </div>
      </div>
    );
  }

  const attendance = dashData?.attendance_today;
  const isCheckedIn = attendance?.status === "checked_in";
  const summary = dashData?.tenant_summary;

  return (
    <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-gradient-to-br from-interactive-primary/[0.04] via-transparent to-brand-accent/[0.03] p-5 sm:p-6">
      {/* Decorative pattern */}
      <div
        className="pointer-events-none absolute -right-8 -top-8 h-32 w-32 opacity-[0.04]"
        style={{
          background:
            "repeating-conic-gradient(var(--interactive-primary) 0% 25%, transparent 0% 50%)",
          borderRadius: "50%",
        }}
      />

      <div className="relative flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex min-w-0 items-center gap-4">
          <EmployeeAvatar
            name={displayName ?? user?.email ?? "User"}
            photoThumbUrl={user?.photo_thumb_url}
            className="h-14 w-14 shrink-0 ring-2 ring-interactive-primary/20 ring-offset-2 ring-offset-background"
            fallbackClassName="bg-interactive-primary/10 text-lg font-bold text-interactive-primary"
          />
          <div className="min-w-0">
            <h1 className="truncate text-xl font-bold tracking-tight text-foreground sm:text-2xl">
              {firstName ? `${greeting}, ${firstName}` : greeting}
            </h1>
            <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
              {tenant?.name && (
                <>
                  <span className="font-medium text-foreground/80">
                    {tenant.name}
                  </span>
                  <span className="text-border-strong">|</span>
                </>
              )}
              <time dateTime={new Date().toISOString()}>{dateStr}</time>
            </div>

            {/* Organisation facts.
                These were three full KPI tiles ("Total Employees",
                "Departments", "Branches"). Department and branch counts are
                configuration that changes a few times a year — framing them as
                headline metrics gave the least decision-relevant numbers the
                most prominent position on the page, pushing today's actual
                state below the fold. Same information, one line, no false
                signal of "something to act on". */}
            {can.viewExecutiveDashboard && summary && (
              <dl className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <OrgFact
                  icon={Users}
                  label={t("dashboard.total_employees", "Employees")}
                  value={summary.employee_count}
                />
                <OrgFact
                  icon={Building2}
                  label={t("dashboard.departments", "Departments")}
                  value={summary.department_count}
                />
                <OrgFact
                  icon={GitBranch}
                  label={t("dashboard.branches", "Branches")}
                  value={summary.branch_count}
                />
              </dl>
            )}
          </div>
        </div>

        <div className="flex shrink-0 flex-col items-start gap-3 lg:items-end">
          {attendance && (
            <div
              className={cn(
                "flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-medium transition-colors",
                isCheckedIn
                  ? "border-status-success/30 bg-status-success/5 text-status-success"
                  : "border-border/60 bg-muted/50 text-muted-foreground",
              )}
            >
              <Clock className="h-3.5 w-3.5" />
              {isCheckedIn
                ? t("dashboard.checked_in", "Checked In")
                : t("dashboard.not_checked_in", "Not Checked In")}
              {isCheckedIn && attendance.check_in && (
                <span className="tabular-nums opacity-70">
                  {attendance.check_in}
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

function OrgFact({
  icon: Icon,
  label,
  value,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: number;
}) {
  return (
    <div className="flex items-center gap-1.5">
      <Icon className="h-3.5 w-3.5 shrink-0 text-muted-foreground/60" />
      <dt className="sr-only">{label}</dt>
      <dd className="flex items-center gap-1">
        <span className="font-semibold tabular-nums text-foreground">
          {value}
        </span>
        <span>{label}</span>
      </dd>
    </div>
  );
}
