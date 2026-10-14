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
import { useCurrentUser } from "@/features/auth/api";
import { DashboardSection } from "@/components/dashboard";
import { InstallAppBanner } from "@/components/shared/install-app";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { dateFromIso } from "@/lib/calendar/date-from-iso";
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
  OrganisationSnapshot,
  LinkEmployeeCallout,
} from "@/features/dashboard/components";
import {
  IntegrityPrompt,
  PulsePrompt,
} from "@/features/dashboard/components/voice-and-integrity-prompts";

/**
 * Dashboard layout order is deliberate:
 *
 *   1. Header — who/where/when, plus the primary actions.
 *   2. Setup banner — only while onboarding is incomplete.
 *   3. "Organisation today" — headcount, attendance, approvals and payroll,
 *      for those who can see the whole organisation.
 *   4. "Today" — the supervisor's operational state (present/absent/on leave).
 *   5. "My Day" — the individual's own state, shown to every role; an account
 *      with no employee record is told why it has none instead.
 *   6. Work queue, then trends, then reference material.
 *
 * The governing rule is decisions-before-trends: what needs a response today
 * outranks a chart of the last five days. Previously the analytics card was
 * the first thing a supervisor met in the main column, so "two people are
 * absent and three approvals are waiting" sat below a week-over-week graph.
 */
export default function DashboardPage() {
  const { isSupervisor, can } = usePermissions();
  const { t } = useT();
  const { data: user } = useCurrentUser();

  // Only an explicit null means "no employee record": while /auth/me loads,
  // or from an older payload without the field, the tiles show as before.
  const hasNoEmployee = user?.employee_public_id === null;

  return (
    <div className="mx-auto max-w-7xl space-y-8">
      <div className="animate-fade-in-up">
        <WelcomeSection />
      </div>

      <InstallAppBanner />

      <SetupProgress />

      {can.viewExecutiveDashboard && <OrganisationSnapshot />}

      {/* Section headings turn what was a run of seven undifferentiated tiles
          into two labelled groups of four. Chunking gives the eye an entry
          point per group instead of asking it to infer where one metric family
          ends and the next begins. */}
      {isSupervisor && <TeamOverview />}

      <DashboardSection
        id="my-day-heading"
        title={t("dashboard.my_day", "My Day")}
      >
        {hasNoEmployee ? <LinkEmployeeCallout /> : <SelfServiceKpis />}
      </DashboardSection>

      {/* Three columns from xl: with the sidebar open at 1024px the side
          column was ~170px wide, and "Leave Balances" pushed its own
          "View all" off the page. */}
      <div className="grid gap-6 xl:grid-cols-3">
        <div className="min-w-0 space-y-6 xl:col-span-2">
          <PendingApprovalsPanel />
          <InteractiveCharts />
        </div>
        <div className="min-w-0 space-y-6">
          {!hasNoEmployee && <PulsePrompt />}
          {can.viewAttendanceConflicts && <IntegrityPrompt />}
          {/* Personal balances; an account with no employee record has none,
              and "My Day" already says how to get one. */}
          {!hasNoEmployee && <LeaveOverview />}
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
  const { formatTime } = useDateFormatters();
  const { formatDate } = useCalendar();

  if (isLoading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-28 rounded-xl" />
        ))}
      </div>
    );
  }

  const attendance = data?.attendance_today;
  // `not_checked_in` carries no times at all.
  const checkIn =
    attendance && attendance.status !== "not_checked_in"
      ? attendance.check_in
      : null;
  const balances = data?.leave_balances ?? [];
  const payslip = data?.latest_payslip;
  const holidays = data?.upcoming_holidays ?? [];

  return (
    <div className="stagger-children grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <KpiCard
        icon={Clock}
        tone="info"
        href="/attendance/mobile"
        title={t("dashboard.attendance", "Attendance")}
        value={
          attendance?.status === "checked_in"
            ? t("dashboard.checked_in", "Checked In")
            : attendance?.status === "checked_out"
              ? t("dashboard.checked_out", "Checked Out")
              : t("dashboard.not_checked_in", "Not Checked In")
        }
        sub={
          checkIn
            ? // t() has no interpolation: passing a template string as the
              // fallback returns the bare translation ("Since") whenever the
              // key exists, dropping the time. Compose outside the call.
              // `check_in` is a UTC instant; print the tenant's wall clock.
              `${t("dashboard.since", "Since")} ${formatTime(checkIn)}`
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
            : t("dashboard.none", "None")
        }
        sub={
          balances.length > 0
            ? // Null once the leave type has been deleted.
              (balances[0].type ?? t("dashboard.leave", "Leave"))
            : t("dashboard.no_leave_configured", "No leave configured")
        }
      />
      <KpiCard
        icon={Wallet}
        tone="primary"
        title={t("dashboard.latest_payslip", "Latest Payslip")}
        value={
          payslip
            ? formatETB(payslip.net_cents)
            : t("dashboard.none_yet", "None yet")
        }
        sub={payslip?.period ?? t("dashboard.no_payslips", "No payslips")}
      />
      <KpiCard
        icon={CalendarDays}
        tone="warning"
        title={t("dashboard.next_holiday", "Next Holiday")}
        value={
          holidays.length > 0
            ? localizedName(holidays[0], locale)
            : t("dashboard.none", "None")
        }
        sub={
          holidays.length > 0
            ? formatDate(dateFromIso(holidays[0].date), locale)
            : t("dashboard.no_upcoming", "No upcoming holidays")
        }
      />
    </div>
  );
}
