"use client";

import { CalendarCheck, ClipboardCheck, Users, Wallet } from "lucide-react";
import { DashboardSection, StatCard } from "@/components/dashboard";
import { useManagerDashboard } from "@/features/dashboard/api";
import {
  useExecutiveAttendance,
  useExecutiveOverview,
} from "@/features/dashboard/executive-api";
import { useT } from "@/lib/i18n/useT";
import { formatETBCompact } from "@/lib/utils/currency";
import { WidgetError } from "./widget-error";

/**
 * The organisation at a glance, for people who run it.
 *
 * An admin landing on the dashboard used to meet "My Day" — four tiles about
 * their own attendance, leave and payslip, all dashes for an owner with no
 * employee record — and had to open Analytics to learn how many people were
 * in today. These four answer the questions an admin opens the app with:
 * how many people, how many are here, what is waiting on me, what did we pay.
 * Each tile links to the screen that explains its number.
 */
export function OrganisationSnapshot() {
  const { t } = useT();
  const overview = useExecutiveOverview();
  const attendance = useExecutiveAttendance();
  const manager = useManagerDashboard();

  const heading = (
    <span>{t("dashboard.organisation_today", "Organisation today")}</span>
  );

  if (overview.isError) {
    return (
      <DashboardSection id="organisation-heading" title={heading}>
        <WidgetError
          title={t("dashboard.organisation_today", "Organisation today")}
          onRetry={() => overview.refetch()}
        />
      </DashboardSection>
    );
  }

  const data = overview.data;
  const loading = overview.isLoading;

  const growth = data?.workforce_growth ?? [];
  const hiresThisMonth =
    growth.length > 0 ? growth[growth.length - 1].hires : 0;

  const trend = attendance.data?.daily_trend ?? [];
  const presentSpark = trend.map((d) => d.present);

  const pending = manager.data?.pending_approvals;

  const payroll = data?.payroll_summary;
  const thisMonth = payroll?.gross_cents ?? 0;
  const lastMonth = payroll?.previous_gross_cents ?? 0;
  const payrollShown = thisMonth > 0 ? thisMonth : lastMonth;
  const payrollDelta =
    thisMonth > 0 && lastMonth > 0
      ? Math.round(((thisMonth - lastMonth) / lastMonth) * 1000) / 10
      : null;

  return (
    <DashboardSection id="organisation-heading" title={heading}>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          loading={loading}
          icon={Users}
          tone="primary"
          label={t("dashboard.headcount", "Headcount")}
          value={data?.headcount.active ?? 0}
          hint={`${hiresThisMonth} ${t("dashboard.hires_this_month", "hired this month")}`}
          spark={growth.map((g) => g.hires)}
          href="/employees"
        />
        <StatCard
          loading={loading}
          icon={CalendarCheck}
          tone="success"
          label={t("dashboard.present_today", "Present Today")}
          value={`${data?.attendance_rate.today ?? 0}%`}
          hint={`${data?.attendance_rate.period ?? 0}% ${t("dashboard.this_month", "this month")}`}
          spark={presentSpark}
          href="/attendance"
        />
        <StatCard
          loading={manager.isLoading}
          icon={ClipboardCheck}
          tone="warning"
          label={t("dashboard.pending_approvals", "Pending Approvals")}
          value={pending?.total ?? 0}
          hint={
            pending
              ? `${pending.leave} ${t("dashboard.leave_short", "leave")} · ${pending.correction} ${t("dashboard.corrections_short", "corrections")}`
              : undefined
          }
          href="/approvals"
        />
        <StatCard
          loading={loading}
          icon={Wallet}
          tone="brand"
          label={t("dashboard.payroll_gross", "Payroll (gross)")}
          value={payrollShown > 0 ? formatETBCompact(payrollShown) : "—"}
          hint={
            thisMonth > 0
              ? t("dashboard.this_month_payroll", "This month")
              : t(
                  "dashboard.last_month_payroll",
                  "Last month · this month not run yet",
                )
          }
          delta={
            payrollDelta !== null
              ? {
                  value: payrollDelta,
                  label: t("dashboard.vs_last_month", "vs last month"),
                }
              : undefined
          }
          href="/payroll"
        />
      </div>
    </DashboardSection>
  );
}
