"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useExecutiveWorkforce,
  useExecutiveForecast,
} from "@/features/dashboard/executive-api";
import { useT } from "@/lib/i18n/useT";
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
  Legend,
} from "recharts";
import { CHART_COLORS, ChartCard } from "./chart-helpers";

export function WorkforceTab({ branchPublicId }: { branchPublicId?: string }) {
  const { t } = useT();
  const query = useExecutiveWorkforce(branchPublicId);
  const forecast = useExecutiveForecast(branchPublicId);

  // isEmpty is disabled: each chart below hides itself when it has no rows, so
  // the tab as a whole is never "empty".
  return (
    <QueryBoundary
      query={query}
      loading={
        <div className="space-y-6">
          <Skeleton className="h-80 w-full" />
        </div>
      }
      isEmpty={() => false}
    >
      {(data) => (
        <div className="space-y-6">
          {data.headcount_trend && data.headcount_trend.length > 0 && (
            <ChartCard
              title={t("analytics_page.headcount_trend")}
              subtitle={
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
                    ...data.headcount_trend.map(
                      (
                        h: { month: string; count: number },
                        i: number,
                        arr: Array<{ month: string; count: number }>,
                      ) => ({
                        month: h.month,
                        count: h.count,
                        // The projected line starts exactly at the last real
                        // point, so the two segments read as one continuous line.
                        Projected: i === arr.length - 1 ? h.count : undefined,
                      }),
                    ),
                    ...(forecast.data?.headcount.projected ?? []).map((p) => ({
                      month: p.label,
                      Projected: p.value,
                    })),
                  ]}
                >
                  <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Line
                    type="monotone"
                    dataKey="count"
                    name={t("executive_dashboard.actual", "Actual")}
                    stroke={CHART_COLORS[0]}
                    strokeWidth={2}
                    dot={{ r: 4 }}
                  />
                  <Line
                    type="monotone"
                    dataKey="Projected"
                    name={t("executive_dashboard.projected", "Projected")}
                    stroke={CHART_COLORS[0]}
                    strokeWidth={2}
                    strokeDasharray="5 5"
                    dot={{ r: 3 }}
                  />
                </LineChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

          <div className="grid gap-6 lg:grid-cols-2">
            {data.by_gender && data.by_gender.length > 0 && (
              <ChartCard title={t("analytics_page.gender_distribution")}>
                <ResponsiveContainer width="100%" height={260}>
                  <PieChart>
                    <Pie
                      data={data.by_gender}
                      dataKey="count"
                      nameKey="gender"
                      label
                      outerRadius={80}
                    >
                      {data.by_gender.map((_: unknown, i: number) => (
                        <Cell
                          key={i}
                          fill={CHART_COLORS[i % CHART_COLORS.length]}
                        />
                      ))}
                    </Pie>
                    <Tooltip />
                    <Legend />
                  </PieChart>
                </ResponsiveContainer>
              </ChartCard>
            )}

            {data.by_tenure && data.by_tenure.length > 0 && (
              <ChartCard title={t("analytics_page.tenure_distribution")}>
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart data={data.by_tenure}>
                    <CartesianGrid
                      strokeDasharray="3 3"
                      className="opacity-30"
                    />
                    <XAxis dataKey="bucket" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} />
                    <Tooltip />
                    <Bar
                      dataKey="count"
                      fill={CHART_COLORS[1]}
                      radius={[6, 6, 0, 0]}
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
