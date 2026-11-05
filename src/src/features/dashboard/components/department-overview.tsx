"use client";

import { CalendarDays, CheckSquare, UserCheck, UserX } from "lucide-react";
import { DashboardSection, StatCard } from "@/components/dashboard";
import { useDepartmentDashboard } from "@/features/dashboard/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { localizedName } from "@/lib/i18n/localizedName";
import { useT } from "@/lib/i18n/useT";
import { WidgetError } from "./widget-error";

/**
 * "Your department" (or "Your team"): today, for the people a department- or
 * team-scoped reader is responsible for.
 *
 * A department admin had no section of their own: "Organisation today" needs
 * `dashboard.executive`, which the role lost in #561, and "Today" counts
 * direct reports, which a department head usually has none of, so the page
 * told them "No direct reports" (dashboard review, 2026-11-03). The API counts
 * exactly the people the role reaches, so this never shows more than
 * /employees does. Headcount is the denominator of "Present", as in the
 * direct-reports section, rather than a tile of its own.
 */
export function DepartmentOverview() {
  const { t, locale } = useT();
  const { orgScope } = usePermissions();
  const unitScoped = orgScope === "department" || orgScope === "team";
  const { data, isLoading, isError, refetch } =
    useDepartmentDashboard(unitScoped);

  if (!unitScoped) return null;

  const title =
    (data?.scope ?? orgScope) === "team"
      ? t("dashboard.your_team", "Your team")
      : t("dashboard.your_department", "Your department");

  if (isError) {
    return <WidgetError title={title} onRetry={() => refetch()} />;
  }

  // An employee record with no department (or team) reaches nobody; four
  // zeros would measure nothing.
  if (data && data.unit === null) return null;

  const loading = isLoading || !data;
  const headcount = data?.headcount ?? 0;

  return (
    <DashboardSection
      id="unit-heading"
      title={title}
      description={data?.unit ? localizedName(data.unit, locale) : undefined}
    >
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          loading={loading}
          icon={UserCheck}
          tone="success"
          label={t("dashboard.present_today", "Present Today")}
          value={data?.attendance.present ?? 0}
          hint={t("dashboard.of_team_count", "of :count", {
            count: headcount,
          })}
          href="/attendance"
        />
        <StatCard
          loading={loading}
          icon={UserX}
          tone="error"
          label={t("dashboard.absent_today", "Absent Today")}
          value={data?.attendance.absent ?? 0}
          hint={t("dashboard.not_checked_in", "Not checked in")}
          href="/attendance"
        />
        <StatCard
          loading={loading}
          icon={CalendarDays}
          tone="info"
          label={t("dashboard.on_leave_today", "On leave today")}
          value={data?.on_leave_today ?? 0}
          hint={t("dashboard.today", "Today")}
          href="/leave"
        />
        <StatCard
          loading={loading}
          icon={CheckSquare}
          tone="warning"
          label={t("dashboard.pending_approvals", "Pending Approvals")}
          value={data?.pending_approvals ?? 0}
          hint={t("dashboard.awaiting_review", "Awaiting review")}
          href="/approvals"
        />
      </div>
    </DashboardSection>
  );
}
