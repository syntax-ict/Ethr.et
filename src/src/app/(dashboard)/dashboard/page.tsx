"use client";

import dynamic from "next/dynamic";
import { Clock, CalendarDays, Wallet } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Charts are the heaviest thing on this page (recharts, ~435 KB uncompressed)
 * and they sit below the fold, so loading them in the initial bundle delays
 * the KPIs and approvals the user actually landed here for. Split out with a
 * skeleton that reserves the same height — deferring the import must not cost
 * a layout shift.
 */
const InteractiveCharts = dynamic(
  () =>
    import("@/features/dashboard/components/interactive-charts").then(
      (m) => m.InteractiveCharts,
    ),
  {
    ssr: false,
    loading: () => <Skeleton className="h-[360px] w-full rounded-lg" />,
  },
);
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { localizedName } from "@/lib/i18n/localizedName";
import { formatETB } from "@/lib/utils/currency";
import {
  WelcomeSection,
  SetupProgress,
  KpiCard,
  PendingApprovalsPanel,
  RecentActivity,
  AnnouncementsWidget,
  CalendarWidget,
  TeamOverview,
  LeaveOverview,
} from "@/features/dashboard/components";

/**
 * Dashboard layout order is deliberate:
 *
 *   1. Header — who/where/when, plus the primary actions.
 *   2. Setup banner — only while onboarding is incomplete.
 *   3. "Today" — the supervisor's operational state (present/absent/on leave).
 *   4. "My Day" — the individual's own state, shown to every role.
 *   5. Work queue, then trends, then reference material.
 *
 * The governing rule is decisions-before-trends: what needs a response today
 * outranks a chart of the last five days. Previously the analytics card was
 * the first thing a supervisor met in the main column, so "two people are
 * absent and three approvals are waiting" sat below a week-over-week graph.
 */
export default function DashboardPage() {
  const { isSupervisor } = usePermissions();
  const { t } = useT();

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <div className="animate-fade-in-up">
        <WelcomeSection />
      </div>

      <SetupProgress />

      {/* Section headings turn what was a run of seven undifferentiated tiles
          into two labelled groups of four. Chunking gives the eye an entry
          point per group instead of asking it to infer where one metric family
          ends and the next begins. */}
      {isSupervisor && <TeamOverview />}

      <section aria-labelledby="my-day-heading" className="space-y-3">
        <h2
          id="my-day-heading"
          className="text-sm font-semibold tracking-tight text-foreground"
        >
          {t("dashboard.my_day", "My Day")}
        </h2>
        <SelfServiceKpis />
      </section>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <PendingApprovalsPanel />
          <InteractiveCharts />
        </div>
        <div className="space-y-6">
          <LeaveOverview />
          <CalendarWidget />
          <AnnouncementsWidget />
          {/* The standalone unread-count tile that used to sit here restated
              what the header bell already shows and what Recent Activity
              already lists — three renderings of one number. */}
          <RecentActivity />
        </div>
      </div>
    </div>
  );
}

function SelfServiceKpis() {
  const { t, locale } = useT();
  const { data, isLoading } = useEmployeeDashboard();

  if (isLoading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-28 rounded-xl" />
        ))}
      </div>
    );
  }

  const attendance = data?.attendance_today;
  const balances = data?.leave_balances ?? [];
  const payslip = data?.latest_payslip;
  const holidays = data?.upcoming_holidays ?? [];

  return (
    <div className="stagger-children grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <KpiCard
        icon={Clock}
        tone="info"
        title={t("dashboard.attendance", "Attendance")}
        value={
          attendance?.status === "checked_in"
            ? t("dashboard.checked_in", "Checked In")
            : attendance?.status === "checked_out"
              ? t("dashboard.checked_out", "Checked Out")
              : t("dashboard.not_checked_in", "Not Checked In")
        }
        sub={
          attendance?.check_in
            ? // t() has no interpolation: passing a template string as the
              // fallback returns the bare translation ("Since") whenever the
              // key exists, dropping the time. Compose outside the call.
              `${t("dashboard.since", "Since")} ${attendance.check_in}`
            : t("dashboard.no_record_today", "No record today")
        }
      />
      <KpiCard
        icon={CalendarDays}
        tone="success"
        title={t("dashboard.leave_balance", "Leave Balance")}
        value={
          balances.length > 0
            ? `${balances[0].remaining} ${t("common.days", "days")}`
            : "—"
        }
        sub={
          balances.length > 0
            ? String(balances[0].type)
            : t("dashboard.no_leave_configured", "No leave configured")
        }
      />
      <KpiCard
        icon={Wallet}
        tone="primary"
        title={t("dashboard.latest_payslip", "Latest Payslip")}
        value={payslip ? formatETB(payslip.net_cents) : "—"}
        sub={payslip?.period ?? t("dashboard.no_payslips", "No payslips")}
      />
      <KpiCard
        icon={CalendarDays}
        tone="warning"
        title={t("dashboard.next_holiday", "Next Holiday")}
        value={holidays.length > 0 ? localizedName(holidays[0], locale) : "—"}
        sub={
          holidays.length > 0
            ? holidays[0].date
            : t("dashboard.no_upcoming", "No upcoming")
        }
      />
    </div>
  );
}
