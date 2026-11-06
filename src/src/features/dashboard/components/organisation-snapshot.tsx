"use client";

import Link from "next/link";
import {
  ArrowRight,
  CalendarCheck,
  ClipboardCheck,
  Users,
  Wallet,
} from "lucide-react";
import {
  DashboardSection,
  SummaryBand,
  SummaryCell,
} from "@/components/dashboard";
import { useManagerDashboard } from "@/features/dashboard/api";
import { useExecutiveOverview } from "@/features/dashboard/executive-api";
import { useThisMonth } from "@/features/dashboard/use-this-month";
import { useT } from "@/lib/i18n/useT";
import { formatETBCompact } from "@/lib/utils/currency";
import { WidgetError } from "./widget-error";

/**
 * The organisation at a glance, for people who run it.
 *
 * An admin landing on the dashboard used to meet "My Day", four tiles about
 * their own attendance, leave and payslip, all dashes for an owner with no
 * employee record, and had to open Analytics to learn how many people were
 * in today. These four answer the questions an admin opens the app with:
 * how many people, how many are here, what is waiting on me, what did we pay.
 * Each figure links to the screen that explains it.
 *
 * One band rather than four cards since 2026-11-05 (owner review): the cards
 * looked like every card under them, the headcount line plotted hires, the
 * attendance line plotted head counts beside a percentage and ended on the
 * half-filled day, a morning read "0%" as if the office were empty, a small
 * fall in payroll drew in red, and approvals, the one figure asking for
 * action, looked like the rest. Attendance is now people ("112 of 140 in")
 * with a ring for the share; its trend is on Analytics, one click away.
 * The greeting above carries today's date.
 *
 * "This month" is the reader's current month, the same window the Analytics
 * page asks for, so the two screens agree about it.
 */
export function OrganisationSnapshot() {
  const { t } = useT();
  const period = useThisMonth();
  const overview = useExecutiveOverview(period);
  const manager = useManagerDashboard();

  const title = t("dashboard.organisation_today", "Organisation today");
  const analyticsLink = (
    <Link
      href="/analytics"
      className="inline-flex items-center gap-1 rounded-md text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      {t("dashboard.open_analytics", "Open analytics")}
      <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
    </Link>
  );

  if (overview.isError) {
    return (
      <DashboardSection id="organisation-heading" title={title}>
        <WidgetError title={title} onRetry={() => overview.refetch()} />
      </DashboardSection>
    );
  }

  const data = overview.data;
  const loading = overview.isLoading;

  const growth = data?.workforce_growth ?? [];
  const hiresThisMonth =
    growth.length > 0 ? growth[growth.length - 1].hires : 0;

  const rate = data?.attendance_rate;
  const expected = rate?.today_expected ?? 0;
  const present = rate?.today_present ?? 0;
  const periodHint =
    rate?.period == null
      ? undefined
      : `${rate.period}% ${t("dashboard.this_month", "this month")}`;

  const pending = manager.data?.pending_approvals;
  const pendingTotal = pending?.total ?? 0;
  const pendingParts = pending
    ? [
        pending.leave > 0 &&
          t("dashboard.pending_leave", ":count leave", {
            count: pending.leave,
          }),
        pending.correction > 0 &&
          t("dashboard.pending_corrections", ":count corrections", {
            count: pending.correction,
          }),
        pending.profile_update > 0 &&
          t("dashboard.pending_profile", ":count profile changes", {
            count: pending.profile_update,
          }),
      ].filter((part): part is string => Boolean(part))
    : [];

  const payroll = data?.payroll_summary;
  const thisMonth = payroll?.gross_cents ?? 0;
  const lastMonth = payroll?.previous_gross_cents ?? 0;
  const payrollShown = thisMonth > 0 ? thisMonth : lastMonth;
  const payrollDelta =
    thisMonth > 0 && lastMonth > 0
      ? Math.round(((thisMonth - lastMonth) / lastMonth) * 1000) / 10
      : null;

  const presentLabel = t("dashboard.present_today", "Present Today");

  return (
    <DashboardSection
      id="organisation-heading"
      title={title}
      action={analyticsLink}
    >
      <SummaryBand>
        <SummaryCell
          loading={loading}
          icon={Users}
          label={t("dashboard.headcount", "Headcount")}
          value={data?.headcount.active ?? 0}
          hint={
            hiresThisMonth > 0
              ? t(
                  "dashboard.hired_this_month_count",
                  ":count hired this month",
                  { count: hiresThisMonth },
                )
              : t("dashboard.no_hires_this_month", "No hires this month")
          }
          spark={{
            values: growth.map((g) => g.headcount),
            caption: t("dashboard.span_six_months", "6 months"),
            color: "var(--chart-1)",
          }}
          href="/employees"
        />
        {/* Nobody expected is a rest day or a holiday, not 0% attendance. */}
        {expected === 0 ? (
          <SummaryCell
            loading={loading}
            icon={CalendarCheck}
            label={presentLabel}
            value={t("analytics_page.no_one_scheduled", "No one scheduled")}
            quiet
            hint={periodHint}
            href="/attendance"
          />
        ) : (
          <SummaryCell
            loading={loading}
            icon={CalendarCheck}
            label={presentLabel}
            value={present}
            suffix={t("dashboard.of_expected_in", "of :expected in", {
              expected,
            })}
            ring={{
              value: (present / expected) * 100,
              label: t("dashboard.checked_in_today", "Checked in today"),
              color: "var(--status-success)",
            }}
            hint={
              present === 0
                ? t("dashboard.no_checkins_yet", "No one has checked in yet")
                : periodHint
            }
            href="/attendance"
          />
        )}
        <SummaryCell
          loading={manager.isLoading}
          icon={ClipboardCheck}
          label={t("dashboard.pending_approvals", "Pending Approvals")}
          value={pendingTotal}
          hint={
            pendingTotal === 0
              ? t("dashboard.approvals_all_clear", "Nothing is waiting for you")
              : pendingParts.join(" · ")
          }
          attention={pendingTotal > 0}
          action={t("dashboard.review_action", "Review")}
          href="/approvals"
        />
        <SummaryCell
          loading={loading}
          icon={Wallet}
          label={t("dashboard.payroll_gross", "Payroll (gross)")}
          value={
            payrollShown > 0
              ? formatETBCompact(payrollShown)
              : t("dashboard.payroll_not_run", "Not run yet")
          }
          quiet={payrollShown === 0}
          hint={
            payrollShown === 0
              ? t(
                  "dashboard.payroll_none_hint",
                  "No payroll this month or last",
                )
              : thisMonth > 0
                ? payrollDelta !== null
                  ? undefined
                  : t("dashboard.this_month_payroll", "This month")
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
                  neutral: true,
                }
              : undefined
          }
          href="/payroll"
        />
      </SummaryBand>
    </DashboardSection>
  );
}
