"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { ChartCard, ChartTable } from "@/components/dashboard/chart-card";
import {
  ChartLegend,
  axisProps,
  gridProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import {
  useExecutiveOverview,
  useExecutiveWorkforce,
} from "@/features/dashboard/executive-api";
import { useThisMonth } from "@/features/dashboard/use-this-month";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";

/**
 * The organisation's shape at the top of the people list: headcount by
 * department as sideways bars and the gender split as a donut, from the
 * same executive overview and workforce figures the Analytics page draws
 * (charts upgrade, 2026-11-04). Only for a reader who holds the executive
 * dashboard, since those endpoints refuse everyone else; each card hides
 * itself when it has no rows.
 */
export function HeadcountCharts() {
  const { can } = usePermissions();

  if (!can.viewExecutiveDashboard) return null;

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      <DepartmentBars />
      <GenderDonut />
    </div>
  );
}

function DepartmentBars() {
  const { t } = useT();
  const query = useExecutiveOverview(useThisMonth());
  const title = t("analytics_page.headcount_by_department");
  const people = t("dashboard.total_employees", "Employees");

  return (
    <QueryBoundary
      query={query}
      loading={<Skeleton className="h-64 rounded-xl" />}
      isEmpty={(data) => data.headcount.by_department.length === 0}
      empty={<></>}
    >
      {(data) => {
        const departments = [...data.headcount.by_department].sort(
          (a, b) => b.count - a.count,
        );
        return (
          <ChartCard title={title}>
            {(view) =>
              view === "table" ? (
                <ChartTable
                  caption={title}
                  columns={[
                    {
                      key: "department",
                      label: t("common.department", "Department"),
                    },
                    { key: "count", label: people, numeric: true },
                  ]}
                  rows={departments.map((d) => ({
                    department: d.department,
                    count: d.count,
                  }))}
                />
              ) : (
                <ResponsiveContainer
                  width="100%"
                  height={Math.max(220, departments.length * 30)}
                >
                  <BarChart
                    data={departments}
                    layout="vertical"
                    margin={{ top: 0, right: 16, left: 0, bottom: 0 }}
                    barCategoryGap={6}
                  >
                    <CartesianGrid {...gridProps} horizontal={false} vertical />
                    <XAxis type="number" {...axisProps} allowDecimals={false} />
                    <YAxis
                      type="category"
                      dataKey="department"
                      {...axisProps}
                      width={120}
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
              )
            }
          </ChartCard>
        );
      }}
    </QueryBoundary>
  );
}

function GenderDonut() {
  const { t } = useT();
  const query = useExecutiveWorkforce();
  const title = t("analytics_page.gender_distribution");
  const people = t("dashboard.total_employees", "Employees");

  return (
    <QueryBoundary
      query={query}
      loading={<Skeleton className="h-64 rounded-xl" />}
      isEmpty={(data) => !data.by_gender || data.by_gender.length === 0}
      empty={<></>}
    >
      {(data) => {
        const genders = data.by_gender ?? [];
        const total = genders.reduce((sum, g) => sum + g.count, 0);
        return (
          <ChartCard title={title}>
            {(view) =>
              view === "table" ? (
                <ChartTable
                  caption={title}
                  columns={[
                    { key: "gender", label: t("common.gender", "Gender") },
                    { key: "count", label: people, numeric: true },
                  ]}
                  rows={genders.map((g) => ({
                    gender: <span className="capitalize">{g.gender}</span>,
                    count: g.count,
                  }))}
                />
              ) : (
                <div className="flex flex-col items-center gap-6 sm:flex-row">
                  <div className="relative h-52 w-52 shrink-0">
                    <ResponsiveContainer width="100%" height="100%">
                      <PieChart>
                        <Pie
                          data={genders}
                          dataKey="count"
                          nameKey="gender"
                          innerRadius={62}
                          outerRadius={90}
                          paddingAngle={2}
                          strokeWidth={0}
                        >
                          {genders.map((_, i) => (
                            <Cell key={i} fill={seriesColor(i)} />
                          ))}
                        </Pie>
                        <Tooltip {...tooltipProps} />
                      </PieChart>
                    </ResponsiveContainer>
                    <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                      <span className="text-2xl font-semibold tabular-nums text-foreground">
                        {total}
                      </span>
                      <span className="text-xs text-muted-foreground">
                        {people}
                      </span>
                    </div>
                  </div>
                  <ChartLegend
                    stacked
                    items={genders.map((g, i) => ({
                      label: <span className="capitalize">{g.gender}</span>,
                      color: seriesColor(i),
                      value: g.count,
                    }))}
                  />
                </div>
              )
            }
          </ChartCard>
        );
      }}
    </QueryBoundary>
  );
}
