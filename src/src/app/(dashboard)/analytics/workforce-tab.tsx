"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useExecutiveWorkforce,
  useExecutiveForecast,
} from "@/features/dashboard/executive-api";
import { useT } from "@/lib/i18n/useT";
import { monthKeyLabel } from "./axis-format";
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from "recharts";
import {
  ChartLegend,
  axisProps,
  gridProps,
  lineTooltipProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import { ChartCard } from "@/components/dashboard/chart-card";

export function WorkforceTab({ branchPublicId }: { branchPublicId?: string }) {
  const { t, locale } = useT();
  const query = useExecutiveWorkforce(branchPublicId);
  const forecast = useExecutiveForecast(branchPublicId);

  // isEmpty is disabled: each chart below hides itself when it has no rows, so
  // the tab as a whole is never "empty".
  return (
    <QueryBoundary
      query={query}
      loading={
        <div className="space-y-6">
          <Skeleton className="h-80 w-full rounded-xl" />
        </div>
      }
      isEmpty={() => false}
    >
      {(data) => (
        <div className="space-y-6">
          {data.headcount_trend && data.headcount_trend.length > 0 && (
            <ChartCard
              title={t("analytics_page.headcount_trend")}
              description={
                (forecast.data?.headcount.projected.length ?? 0) > 0
                  ? t(
                      "executive_dashboard.forecast_subtitle",
                      "Dashed bars are a simple trend projection, not a guarantee.",
                    )
                  : undefined
              }
            >
              <ResponsiveContainer width="100%" height={300}>
                <LineChart
                  data={[
                    ...data.headcount_trend.map((h, i, arr) => ({
                      month: h.month,
                      count: h.count,
                      // The projected line starts exactly at the last real
                      // point, so the two segments read as one continuous line.
                      Projected: i === arr.length - 1 ? h.count : undefined,
                    })),
                    ...(forecast.data?.headcount.projected ?? []).map((p) => ({
                      month: p.label,
                      Projected: p.value,
                    })),
                  ]}
                  margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                >
                  <CartesianGrid {...gridProps} />
                  <XAxis
                    dataKey="month"
                    {...axisProps}
                    tickFormatter={(value) =>
                      monthKeyLabel(value, locale, "short")
                    }
                  />
                  <YAxis {...axisProps} allowDecimals={false} width={40} />
                  <Tooltip
                    {...lineTooltipProps}
                    labelFormatter={(label) => monthKeyLabel(label, locale)}
                  />
                  <Line
                    type="monotone"
                    dataKey="count"
                    name={t("executive_dashboard.actual", "Actual")}
                    stroke={seriesColor(0)}
                    strokeWidth={2.25}
                    dot={{ r: 3, strokeWidth: 0, fill: seriesColor(0) }}
                    activeDot={{ r: 5, strokeWidth: 0 }}
                  />
                  <Line
                    type="monotone"
                    dataKey="Projected"
                    name={t("executive_dashboard.projected", "Projected")}
                    stroke={seriesColor(0)}
                    strokeOpacity={0.6}
                    strokeWidth={2}
                    strokeDasharray="5 5"
                    dot={{ r: 3, strokeWidth: 0, fill: seriesColor(0) }}
                  />
                </LineChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

          <div className="grid gap-6 lg:grid-cols-2">
            {data.by_gender && data.by_gender.length > 0 && (
              <ChartCard title={t("analytics_page.gender_distribution")}>
                <div className="flex flex-col items-center gap-6 sm:flex-row">
                  <div className="h-52 w-52 shrink-0">
                    <ResponsiveContainer width="100%" height="100%">
                      <PieChart>
                        <Pie
                          data={data.by_gender}
                          dataKey="count"
                          nameKey="gender"
                          innerRadius={62}
                          outerRadius={90}
                          paddingAngle={2}
                          strokeWidth={0}
                        >
                          {data.by_gender.map((_, i) => (
                            <Cell key={i} fill={seriesColor(i)} />
                          ))}
                        </Pie>
                        <Tooltip {...tooltipProps} />
                      </PieChart>
                    </ResponsiveContainer>
                  </div>
                  <ChartLegend
                    stacked
                    items={data.by_gender.map((g, i) => ({
                      label: <span className="capitalize">{g.gender}</span>,
                      color: seriesColor(i),
                      value: g.count,
                    }))}
                  />
                </div>
              </ChartCard>
            )}

            {data.by_tenure && data.by_tenure.length > 0 && (
              <ChartCard title={t("analytics_page.tenure_distribution")}>
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart
                    data={data.by_tenure}
                    margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                  >
                    <CartesianGrid {...gridProps} />
                    <XAxis dataKey="bucket" {...axisProps} />
                    <YAxis {...axisProps} allowDecimals={false} width={40} />
                    <Tooltip {...tooltipProps} />
                    <Bar
                      dataKey="count"
                      name={t("dashboard.total_employees", "Employees")}
                      fill={seriesColor(1)}
                      radius={[6, 6, 0, 0]}
                      maxBarSize={48}
                    />
                  </BarChart>
                </ResponsiveContainer>
              </ChartCard>
            )}
          </div>
        </div>
      )}
    </QueryBoundary>
  );
}
