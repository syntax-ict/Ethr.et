"use client";

import { useState } from "react";
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { useTeamAttendanceSummary } from "@/features/dashboard/team-api";
import {
  useExecutiveAttendance,
  useExecutiveOverview,
} from "@/features/dashboard/executive-api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { formatWeekday } from "@/lib/utils/date";
import { useT } from "@/lib/i18n/useT";
import { Skeleton } from "@/components/ui/skeleton";
import { ChartCard } from "@/components/dashboard/chart-card";
import {
  ChartLegend,
  STATUS_SERIES,
  axisProps,
  gridProps,
  lineTooltipProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import { dayKeyLabel } from "@/app/(dashboard)/analytics/axis-format";
import { cn } from "@/lib/utils";
import { ErrorState } from "@/components/shared/error-state";

type ChartTab = "attendance" | "workforce";

const CHART_HEIGHT = "h-64";

/**
 * Trends under the work queue.
 *
 * Someone who can see the whole organisation gets the organisation's figures:
 * daily attendance this month and headcount by department. A supervisor gets
 * their team's week. The workforce tab used to draw departments, branches and
 * employees as slices of one donut — three counts of different things, so the
 * slices compared nothing.
 */
export function InteractiveCharts() {
  const { isSupervisor, can } = usePermissions();
  const { t } = useT();
  const [activeTab, setActiveTab] = useState<ChartTab>("attendance");

  if (!isSupervisor && !can.viewExecutiveDashboard) return null;

  const organisation = Boolean(can.viewExecutiveDashboard);
  const tabs: ChartTab[] = organisation
    ? ["attendance", "workforce"]
    : ["attendance"];

  return (
    <ChartCard
      title={t("dashboard.analytics_overview", "Analytics Overview")}
      description={
        activeTab === "workforce"
          ? t("dashboard.headcount_by_department", "Headcount by department")
          : organisation
            ? t(
                "dashboard.attendance_this_month",
                "People present each day this month",
              )
            : t("dashboard.team_this_week", "Your team this week")
      }
      action={
        tabs.length > 1 && (
          <div
            role="tablist"
            aria-label={t("dashboard.analytics_overview", "Analytics Overview")}
            className="flex rounded-lg border border-border bg-muted p-0.5"
          >
            {tabs.map((tab) => (
              <button
                key={tab}
                type="button"
                role="tab"
                aria-selected={activeTab === tab}
                onClick={() => setActiveTab(tab)}
                className={cn(
                  "rounded-md px-3 py-1 text-xs font-medium transition-colors",
                  activeTab === tab
                    ? "bg-card text-foreground shadow-xs"
                    : "text-muted-foreground hover:text-foreground",
                )}
              >
                {tab === "attendance"
                  ? t("dashboard.chart_attendance", "Attendance")
                  : t("dashboard.chart_workforce", "Workforce")}
              </button>
            ))}
          </div>
        )
      }
    >
      {activeTab === "workforce" && organisation ? (
        <HeadcountByDepartment />
      ) : organisation ? (
        <OrganisationAttendance />
      ) : (
        <TeamAttendanceTrend />
      )}
    </ChartCard>
  );
}

function ChartEmpty({ children }: { children: React.ReactNode }) {
  return (
    <div
      className={cn(
        CHART_HEIGHT,
        "flex items-center justify-center rounded-lg border border-dashed border-border text-center",
      )}
    >
      <p className="max-w-xs text-sm text-muted-foreground">{children}</p>
    </div>
  );
}

function OrganisationAttendance() {
  const { data, isLoading, isError, refetch } = useExecutiveAttendance();
  const { t, locale } = useT();
  const { calendar } = useCalendar();

  if (isLoading) return <Skeleton className={cn(CHART_HEIGHT, "rounded-lg")} />;
  // A failed request is not "no attendance this month".
  if (isError)
    return (
      <ErrorState compact onRetry={() => refetch()} className={CHART_HEIGHT} />
    );

  const days = data?.daily_trend ?? [];
  if (days.length === 0) {
    return (
      <ChartEmpty>
        {t(
          "dashboard.no_attendance_this_month",
          "No attendance recorded this month yet.",
        )}
      </ChartEmpty>
    );
  }

  const present = t("dashboard.present", "Present");

  return (
    <div className={CHART_HEIGHT}>
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={days}
          margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
        >
          <defs>
            <linearGradient id="dash-org-present" x1="0" y1="0" x2="0" y2="1">
              <stop
                offset="0%"
                stopColor={STATUS_SERIES.present}
                stopOpacity={0.28}
              />
              <stop
                offset="100%"
                stopColor={STATUS_SERIES.present}
                stopOpacity={0}
              />
            </linearGradient>
          </defs>
          <CartesianGrid {...gridProps} />
          <XAxis
            dataKey="date"
            {...axisProps}
            minTickGap={16}
            tickFormatter={(v) => dayKeyLabel(v, calendar, locale)}
          />
          <YAxis {...axisProps} allowDecimals={false} width={44} />
          <Tooltip
            {...lineTooltipProps}
            labelFormatter={(v) => dayKeyLabel(v, calendar, locale)}
          />
          <Area
            type="monotone"
            dataKey="present"
            name={present}
            stroke={STATUS_SERIES.present}
            strokeWidth={2}
            fill="url(#dash-org-present)"
            activeDot={{ r: 4, strokeWidth: 0 }}
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

function HeadcountByDepartment() {
  const { data, isLoading, isError, refetch } = useExecutiveOverview();
  const { t } = useT();

  if (isLoading) return <Skeleton className={cn(CHART_HEIGHT, "rounded-lg")} />;
  if (isError)
    return (
      <ErrorState compact onRetry={() => refetch()} className={CHART_HEIGHT} />
    );

  const departments = [...(data?.headcount.by_department ?? [])].sort(
    (a, b) => b.count - a.count,
  );
  if (departments.length === 0) {
    return (
      <ChartEmpty>
        {t("dashboard.no_departments", "No departments yet.")}
      </ChartEmpty>
    );
  }

  const people = t("dashboard.total_employees", "Employees");

  return (
    <div className={CHART_HEIGHT}>
      <ResponsiveContainer width="100%" height="100%">
        <BarChart
          data={departments}
          layout="vertical"
          margin={{ top: 0, right: 16, left: 8, bottom: 0 }}
          barCategoryGap={8}
        >
          <CartesianGrid {...gridProps} horizontal={false} vertical />
          <XAxis type="number" {...axisProps} allowDecimals={false} />
          <YAxis
            type="category"
            dataKey="department"
            {...axisProps}
            width={120}
            tickFormatter={(v: string) =>
              v.length > 16 ? `${v.slice(0, 15)}…` : v
            }
          />
          <Tooltip {...tooltipProps} />
          <Bar
            dataKey="count"
            name={people}
            fill={seriesColor(0)}
            radius={[0, 6, 6, 0]}
            maxBarSize={22}
          />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

function TeamAttendanceTrend() {
  const { data, isLoading, isError, refetch } =
    useTeamAttendanceSummary("weekly");
  const { t } = useT();

  if (isLoading) return <Skeleton className={cn(CHART_HEIGHT, "rounded-lg")} />;
  if (isError)
    return (
      <ErrorState compact onRetry={() => refetch()} className={CHART_HEIGHT} />
    );

  // This chart used to synthesise a week from a single day's totals —
  // `attn.present - i * 2 + Math.round(Math.random() * 3)` — so the "trend" a
  // manager read on the dashboard was invented, and re-rolled on every render.
  // `GET /team/attendance/summary` already returned true per-day figures; it was
  // simply never wired up. (Math.random() during render is also what the
  // react-hooks/purity rule was flagging.)
  const days = data?.data ?? [];

  // An explicit empty state rather than `return null`. A user with no direct
  // reports (an HR admin, say) gets a real answer instead of a blank card —
  // and, critically, instead of the invented numbers this used to show.
  if (days.length === 0) {
    return (
      <ChartEmpty>
        {t(
          "dashboard.no_team_attendance",
          "No team attendance recorded this week.",
        )}
      </ChartEmpty>
    );
  }

  const chartData = days.map((day) => ({
    // `day.date` is a `Y-m-d` calendar date from the API, not an instant, so
    // it takes no timezone -- reading it through the host zone would name the
    // previous day's weekday anywhere behind UTC. `undefined` as the locale
    // also meant the axis was labelled in whatever language the browser was
    // set to, which is not the language the rest of the chart is in.
    name: formatWeekday(day.date),
    present: day.present,
    late: day.late,
    absent: day.absent,
  }));

  const series = [
    {
      key: "present",
      label: t("dashboard.present", "Present"),
      color: STATUS_SERIES.present,
    },
    {
      key: "late",
      label: t("dashboard.late", "Late"),
      color: STATUS_SERIES.late,
    },
    {
      key: "absent",
      label: t("dashboard.absent", "Absent"),
      color: STATUS_SERIES.absent,
    },
  ] as const;

  return (
    <div className="space-y-3">
      <ChartLegend
        items={series.map((s) => ({ label: s.label, color: s.color }))}
      />
      <div className="h-56">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart
            data={chartData}
            margin={{ top: 4, right: 4, left: -8, bottom: 0 }}
            barGap={2}
          >
            <CartesianGrid {...gridProps} />
            <XAxis dataKey="name" {...axisProps} />
            <YAxis {...axisProps} allowDecimals={false} />
            <Tooltip {...tooltipProps} />
            {series.map((s) => (
              <Bar
                key={s.key}
                dataKey={s.key}
                name={s.label}
                fill={s.color}
                radius={[4, 4, 0, 0]}
                maxBarSize={18}
              />
            ))}
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}
