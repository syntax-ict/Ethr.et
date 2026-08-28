"use client";

import { useState } from "react";
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
} from "recharts";
import { useEmployeeDashboard } from "@/features/dashboard/api";
import { useTeamAttendanceSummary } from "@/features/dashboard/team-api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

const CHART_COLORS = [
  "var(--chart-1)",
  "var(--chart-2)",
  "var(--chart-3)",
  "var(--chart-4)",
  "var(--chart-5)",
];

type ChartTab = "attendance" | "workforce";

export function InteractiveCharts() {
  const { isSupervisor, can } = usePermissions();
  const { t } = useT();
  const [activeTab, setActiveTab] = useState<ChartTab>("attendance");

  if (!isSupervisor && !can.viewExecutiveDashboard) return null;

  return (
    <Card className="transition-shadow duration-300 hover:shadow-md">
      <CardHeader className="flex flex-row items-center justify-between pb-3">
        <CardTitle className="text-sm font-semibold">
          {t("dashboard.analytics_overview", "Analytics Overview")}
        </CardTitle>
        <div className="flex rounded-lg bg-muted p-0.5">
          {(["attendance", "workforce"] as ChartTab[]).map((tab) => (
            <button
              key={tab}
              type="button"
              onClick={() => setActiveTab(tab)}
              className={cn(
                "rounded-md px-3 py-1 text-xs font-medium transition-all duration-200",
                activeTab === tab
                  ? "bg-card text-foreground shadow-sm"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              {tab === "attendance"
                ? t("dashboard.chart_attendance", "Attendance")
                : t("dashboard.chart_workforce", "Workforce")}
            </button>
          ))}
        </div>
      </CardHeader>
      <CardContent>
        {activeTab === "attendance" ? (
          <AttendanceTrendChart />
        ) : (
          <WorkforceDistChart />
        )}
      </CardContent>
    </Card>
  );
}

function AttendanceTrendChart() {
  const { data, isLoading } = useTeamAttendanceSummary("weekly");
  const { t } = useT();

  if (isLoading) return <Skeleton className="h-56 rounded-lg" />;

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
      <div className="flex h-56 items-center justify-center text-center">
        <p className="max-w-xs text-sm text-muted-foreground">
          {t(
            "dashboard.no_team_attendance",
            "No team attendance recorded this week.",
          )}
        </p>
      </div>
    );
  }

  const chartData = days.map((day) => ({
    name: new Date(day.date).toLocaleDateString(undefined, {
      weekday: "short",
    }),
    present: day.present,
    late: day.late,
    absent: day.absent,
  }));

  return (
    <div className="h-56">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart
          data={chartData}
          margin={{ top: 4, right: 4, left: -20, bottom: 0 }}
        >
          <defs>
            <linearGradient id="gradPresent" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={CHART_COLORS[1]} stopOpacity={0.3} />
              <stop
                offset="100%"
                stopColor={CHART_COLORS[1]}
                stopOpacity={0.02}
              />
            </linearGradient>
            <linearGradient id="gradLate" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={CHART_COLORS[2]} stopOpacity={0.3} />
              <stop
                offset="100%"
                stopColor={CHART_COLORS[2]}
                stopOpacity={0.02}
              />
            </linearGradient>
          </defs>
          <CartesianGrid
            strokeDasharray="3 3"
            stroke="var(--border)"
            strokeOpacity={0.5}
          />
          <XAxis
            dataKey="name"
            tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
            axisLine={false}
            tickLine={false}
          />
          <YAxis
            tick={{ fontSize: 11, fill: "var(--muted-foreground)" }}
            axisLine={false}
            tickLine={false}
          />
          <Tooltip
            contentStyle={{
              background: "var(--popover)",
              border: "1px solid var(--border)",
              borderRadius: 8,
              fontSize: 12,
              color: "var(--popover-foreground)",
              boxShadow: "0 4px 12px rgba(0,0,0,0.1)",
            }}
          />
          <Area
            type="monotone"
            dataKey="present"
            name={t("dashboard.present", "Present")}
            stroke={CHART_COLORS[1]}
            fill="url(#gradPresent)"
            strokeWidth={2}
          />
          <Area
            type="monotone"
            dataKey="late"
            name={t("dashboard.late", "Late")}
            stroke={CHART_COLORS[2]}
            fill="url(#gradLate)"
            strokeWidth={2}
          />
          <Area
            type="monotone"
            dataKey="absent"
            name={t("dashboard.absent", "Absent")}
            stroke={CHART_COLORS[4]}
            fill="transparent"
            strokeWidth={2}
            strokeDasharray="4 4"
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

function WorkforceDistChart() {
  const { data, isLoading } = useEmployeeDashboard();
  const { t } = useT();

  if (isLoading) return <Skeleton className="h-56 rounded-lg" />;

  const summary = data?.tenant_summary;
  if (!summary) return null;

  const chartData = [
    {
      name: t("dashboard.departments", "Departments"),
      value: summary.department_count,
    },
    {
      name: t("dashboard.branches", "Branches"),
      value: summary.branch_count,
    },
    {
      name: t("dashboard.total_employees", "Employees"),
      value: summary.employee_count,
    },
  ];

  return (
    <div className="flex h-56 items-center gap-6">
      <div className="h-full w-1/2">
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie
              data={chartData}
              cx="50%"
              cy="50%"
              innerRadius={48}
              outerRadius={72}
              paddingAngle={4}
              dataKey="value"
              strokeWidth={0}
            >
              {chartData.map((_, idx) => (
                <Cell
                  key={idx}
                  fill={CHART_COLORS[idx % CHART_COLORS.length]}
                />
              ))}
            </Pie>
            <Tooltip
              contentStyle={{
                background: "var(--popover)",
                border: "1px solid var(--border)",
                borderRadius: 8,
                fontSize: 12,
                color: "var(--popover-foreground)",
              }}
            />
          </PieChart>
        </ResponsiveContainer>
      </div>
      <div className="flex flex-1 flex-col justify-center space-y-3">
        {chartData.map((item, idx) => (
          <div key={idx} className="flex items-center gap-3">
            <span
              className="h-3 w-3 rounded-sm"
              style={{ background: CHART_COLORS[idx % CHART_COLORS.length] }}
            />
            <div className="min-w-0 flex-1">
              <p className="truncate text-xs text-muted-foreground">
                {item.name}
              </p>
            </div>
            <span className="text-sm font-bold tabular-nums text-foreground">
              {item.value}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
