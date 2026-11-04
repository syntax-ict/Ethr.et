"use client";

import { useEffect } from "react";
import dynamic from "next/dynamic";
import { useRouter } from "next/navigation";
import { Clock, CalendarDays, Wallet } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";

/**
 * Charts are the heaviest thing on this page (recharts, ~435 KB uncompressed,
 * about 98 KB brotli) and they sit below the fold, so loading them in the
 * initial bundle delays the KPIs and approvals the user actually landed here
 * for. Split out with a skeleton that reserves the same height — deferring the
 * import must not cost a layout shift.
 */
const CHART_PLACEHOLDER = "h-[360px] w-full rounded-lg";

const InteractiveCharts = dynamic(
  () =>
    import("@/features/dashboard/components/interactive-charts").then(
      (m) => m.InteractiveCharts,
    ),
  {
    ssr: false,
    loading: () => <Skeleton className={CHART_PLACEHOLDER} />,
  },
);
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { useCurrentUser } from "@/features/auth/api";
import { DashboardSection } from "@/components/dashboard";
import { InstallAppBanner } from "@/components/shared/install-app";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useNearViewport } from "@/lib/hooks/useNearViewport";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import {
  type CalendarSystem,
  useCalendar,
} from "@/lib/calendar/calendar-context";
import { dateFromIso } from "@/lib/calendar/date-from-iso";
import { localizedPeriodLabel } from "@/lib/calendar/period-label";
import { storedPeriodLabel } from "@/lib/calendar/stored-period-label";
import { useT } from "@/lib/i18n/useT";
import { localizedName } from "@/lib/i18n/localizedName";
import { formatETB } from "@/lib/utils/currency";
import {
  WelcomeSection,
  KpiCard,
  PendingApprovalsPanel,
  RecentActivity,
  AnnouncementsWidget,
  CalendarWidget,
  TeamOverview,
  DepartmentOverview,
  LeaveOverview,
  OrganisationSnapshot,
  LinkEmployeeCallout,
} from "@/features/dashboard/components";
import { PulsePrompt } from "@/features/dashboard/components/voice-and-integrity-prompts";
import { NextStepsCard } from "@/features/next-steps/components/next-steps-card";
import { ErrorState } from "@/components/shared/error-state";

/**
 * Dashboard layout order is deliberate:
 *
 *   1. Header — who/where/when, plus the primary actions.
 *   2. "Organisation today" — headcount, attendance, approvals and payroll,
 *      for those who can see the whole organisation.
 *   3. "Today" — the supervisor's operational state (present/absent/on leave).
 *   4. "My Day" — the individual's own state, shown to every role; an account
 *      with no employee record is told why it has none instead.
 *   5. Next steps: the list the server built for this person (their own
 *      profile, requests waiting on them, records to fix, setup to finish),
 *      three rows first. Then the work queue, trends and reference material.
 *
 * The governing rule is decisions-before-trends: what needs a response today
 * outranks a chart of the last five days. Previously the analytics card was
 * the first thing a supervisor met in the main column, so "two people are
 * absent and three approvals are waiting" sat below a week-over-week graph.
 */
export default function DashboardPage() {
  const { isSupervisor, isSuperAdmin, can, orgScope } = usePermissions();
  const { t } = useT();
  const { data: user } = useCurrentUser();
  const router = useRouter();

  // A platform account has no organisation, so this page has nothing to show
  // it, and every sign-in lands here: production, 2026-10-28, the console's
  // own users met an organisation dashboard whose tiles all failed. Their
  // home is the console.
  useEffect(() => {
    if (isSuperAdmin) router.replace("/admin");
  }, [isSuperAdmin, router]);

  // Only an explicit null means "no employee record": while /auth/me loads,
  // or from an older payload without the field, the tiles show as before.
  const hasNoEmployee = user?.employee_public_id === null;
  const unitScoped = orgScope === "department" || orgScope === "team";

  if (isSuperAdmin) return null;

  return (
    <div className="mx-auto max-w-7xl space-y-8">
      <div className="animate-fade-in-up">
        <WelcomeSection />
      </div>

      <InstallAppBanner />

      {can.viewExecutiveDashboard && <OrganisationSnapshot />}

      {/* A department head or team lead: the people their role reaches,
          counted today. They have no organisation tiles and usually no direct
          reports, so without this the page told them "No direct reports". */}
      {unitScoped && <DepartmentOverview />}

      {/* Section headings turn what was a run of seven undifferentiated tiles
          into two labelled groups of four. Chunking gives the eye an entry
          point per group instead of asking it to infer where one metric family
          ends and the next begins. */}
      {isSupervisor && <TeamOverview emptyState={!unitScoped} />}

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
          <NextStepsCard />
          <PendingApprovalsPanel />
          {(isSupervisor || can.viewExecutiveDashboard) && <DeferredCharts />}
        </div>
        <div className="min-w-0 space-y-6">
          {!hasNoEmployee && <PulsePrompt />}
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

/**
 * The payslip's month in the reader's calendar and language, as /payroll
 * names it: the stored `period` is English Gregorian ("September 2026"), which
 * an Ethiopian-calendar reader saw beside Meskerem everywhere else. The run's
 * dates decide the month; an answer cached before the API sent them falls
 * back to reading the label.
 */
function payslipPeriod(
  payslip: {
    period: string;
    period_start?: string | null;
    period_end?: string | null;
  },
  calendar: CalendarSystem,
  locale: string,
): string {
  return payslip.period_start
    ? localizedPeriodLabel(
        payslip.period,
        payslip.period_start,
        payslip.period_end,
        calendar,
        locale,
      )
    : storedPeriodLabel(payslip.period, calendar, locale);
}

function SelfServiceKpis() {
  const { t, locale } = useT();
  const { data, isLoading, isError, refetch } = useEmployeeDashboard();
  const { formatTime } = useDateFormatters();
  const { calendar, formatDate } = useCalendar();

  // Without this a failed request fell through to the cards below, which
  // read a missing record as "Not Checked In" and an empty balance list.
  if (isError) {
    return (
      <ErrorState
        onRetry={() => refetch()}
        className="rounded-xl border border-border bg-card"
      />
    );
  }

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
              t("dashboard.since_time", "Since :time", {
                time: formatTime(checkIn),
              })
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
        sub={
          payslip
            ? payslipPeriod(payslip, calendar, locale)
            : t("dashboard.no_payslips", "No payslips")
        }
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

/**
 * Splitting the chunk was not enough: a split chunk still downloads as soon as
 * its component renders. InteractiveCharts renders nothing for someone who is
 * neither a supervisor nor allowed the executive view, yet every employee
 * fetched recharts to learn that (measured 2026-11-01: 98 of the 470 KB brotli
 * the signed-in dashboard loaded). The same test now runs here, before the
 * import, and those who do see the charts fetch them on the way down the page.
 */
function DeferredCharts() {
  const { ref, near } = useNearViewport<HTMLDivElement>();

  return (
    <div ref={ref}>
      {near ? (
        <InteractiveCharts />
      ) : (
        <Skeleton className={CHART_PLACEHOLDER} />
      )}
    </div>
  );
}
