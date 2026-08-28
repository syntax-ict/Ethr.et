"use client";

import Link from "next/link";
import { useManagerDashboard } from "@/features/dashboard/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { EmployeeAvatar } from "@/components/shared/employee-avatar";
import {
  ArrowRight,
  CalendarDays,
  Clock3,
  UserCheck,
  UserX,
} from "lucide-react";
import { KpiCard } from "./kpi-card";
import { WidgetError } from "./widget-error";

export function TeamOverview() {
  const { t } = useT();
  const { isSupervisor } = usePermissions();
  const { data, isLoading, isError, refetch } =
    useManagerDashboard(isSupervisor);

  if (!isSupervisor) return null;

  if (isError) {
    return (
      <WidgetError
        title={t("dashboard.today", "Today")}
        onRetry={() => refetch()}
      />
    );
  }

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-6 w-40" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-28 rounded-xl" />
          ))}
        </div>
      </div>
    );
  }

  if (!data) return null;
  const attn = data.team_attendance;

  // A supervisor with nobody assigned to them gets four tiles reading "0 of 0",
  // which is not a measurement of anything — and it costs a quarter of the first
  // screen, pushing the work queue below the fold. One line says the same thing
  // and points at the org-wide view, which is what they actually wanted.
  if (data.team_size === 0) {
    return (
      <Card>
        <CardContent className="flex flex-wrap items-center justify-between gap-3 py-4">
          <div>
            <p className="text-sm font-medium text-foreground">
              {t("dashboard.no_direct_reports", "No direct reports")}
            </p>
            <p className="text-xs text-muted-foreground">
              {t(
                "dashboard.no_direct_reports_desc",
                "Employees assigned to you will appear here.",
              )}
            </p>
          </div>
          <Button variant="outline" size="sm" className="h-8 text-xs" asChild>
            <Link href="/attendance">
              {t("dashboard.view_org_attendance", "View attendance")}
              <ArrowRight className="ml-1 h-3 w-3" />
            </Link>
          </Button>
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2
          id="today-heading"
          className="text-sm font-semibold tracking-tight text-foreground"
        >
          {t("dashboard.today", "Today")}
          <span className="ml-2 font-normal text-muted-foreground">
            {t("dashboard.your_direct_reports", "Your direct reports")}
          </span>
        </h2>
        <Button variant="ghost" size="sm" className="h-8 text-xs" asChild>
          <Link href="/attendance/team">
            {t("dashboard.view_team", "View Team")}
            <ArrowRight className="ml-1 h-3 w-3" />
          </Link>
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {/* Present carries team size as its subtitle rather than spending a
            whole tile on it: a headcount is only meaningful here as the
            denominator of "how many turned up". */}
        <KpiCard
          icon={UserCheck}
          tone="success"
          title={t("dashboard.present_today", "Present Today")}
          value={String(attn.present)}
          sub={`${t("dashboard.of_team", "of")} ${data.team_size}`}
        />
        <KpiCard
          icon={UserX}
          tone="error"
          title={t("dashboard.absent_today", "Absent Today")}
          value={String(attn.absent)}
          sub={t("dashboard.not_checked_in", "Not checked in")}
        />
        <KpiCard
          icon={Clock3}
          tone="warning"
          title={t("dashboard.late_today", "Late Today")}
          value={String(attn.late)}
          sub={t("dashboard.after_grace_period", "After grace period")}
        />
        <KpiCard
          icon={CalendarDays}
          tone="info"
          title={t("dashboard.on_leave", "On Leave")}
          value={String(data.team_on_leave.length)}
          sub={t("dashboard.this_week", "This week")}
        />
      </div>

      {/* On Leave Members */}
      {data.team_on_leave.length > 0 && (
        <Card>
          <CardHeader className="flex flex-row items-center justify-between pb-3">
            <CardTitle className="text-sm font-semibold">
              {t("dashboard.on_leave_this_week", "On Leave This Week")}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-2">
              {data.team_on_leave.map((member, i) => {
                return (
                  <div
                    key={i}
                    className="group flex items-center justify-between rounded-xl border border-border/60 p-3 transition-all duration-200 hover:border-border hover:bg-muted/30"
                  >
                    <div className="flex items-center gap-3">
                      <EmployeeAvatar
                        name={member.employee_name}
                        className="h-8 w-8"
                        fallbackClassName="bg-interactive-primary/10 text-xs font-medium text-interactive-primary"
                      />
                      <span className="text-sm font-medium text-foreground">
                        {member.employee_name}
                      </span>
                    </div>
                    <Badge
                      variant="outline"
                      className="text-[11px] tabular-nums"
                    >
                      {member.start_date} — {member.end_date}
                    </Badge>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
