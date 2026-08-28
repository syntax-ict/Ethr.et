"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  useExecutivePayroll,
  useExecutiveForecast,
} from "@/features/dashboard/executive-api";
import { useT } from "@/lib/i18n/useT";
import { Wallet } from "lucide-react";
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from "recharts";
import { CHART_COLORS, ChartCard, KpiBoxCurrency } from "./chart-helpers";

export function PayrollTab({ branchPublicId }: { branchPublicId?: string }) {
  const { t } = useT();
  const query = useExecutivePayroll({ branchPublicId });
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
          {data.monthly_trend && data.monthly_trend.length > 0 && (
            <ChartCard
              title={t("analytics_page.monthly_payroll_cost")}
              subtitle={
                (forecast.data?.payroll_gross.projected.length ?? 0) > 0
                  ? t(
                      "executive_dashboard.forecast_subtitle",
                      "Dashed bars are a simple trend projection, not a guarantee.",
                    )
                  : undefined
              }
            >
              <ResponsiveContainer width="100%" height={300}>
                <BarChart
                  data={[
                    ...data.monthly_trend.map(
                      (m: {
                        period: string;
                        net_cents: number;
                        gross_cents: number;
                      }) => ({
                        period: m.period,
                        Net: m.net_cents / 100,
                        Gross: m.gross_cents / 100,
                      }),
                    ),
                    ...(forecast.data?.payroll_gross.projected ?? []).map(
                      (p) => ({
                        period: p.label,
                        Projected: p.value / 100,
                      }),
                    ),
                  ]}
                >
                  <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                  <XAxis dataKey="period" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Legend />
                  <Bar
                    dataKey="Gross"
                    fill={CHART_COLORS[0]}
                    radius={[6, 6, 0, 0]}
                  />
                  <Bar
                    dataKey="Net"
                    fill={CHART_COLORS[1]}
                    radius={[6, 6, 0, 0]}
                  />
                  <Bar
                    dataKey="Projected"
                    name={t(
                      "executive_dashboard.projected_gross",
                      "Projected gross",
                    )}
                    fill={CHART_COLORS[0]}
                    fillOpacity={0.35}
                    radius={[6, 6, 0, 0]}
                  />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

          {data.overtime_trend && data.overtime_trend.length > 0 && (
            <ChartCard title={t("analytics_page.overtime_trend")}>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart
                  data={data.overtime_trend.map(
                    (o: { period: string; overtime_cents: number }) => ({
                      period: o.period,
                      Overtime: o.overtime_cents / 100,
                    }),
                  )}
                >
                  <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                  <XAxis dataKey="period" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Bar
                    dataKey="Overtime"
                    fill={CHART_COLORS[3]}
                    radius={[6, 6, 0, 0]}
                  />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

          {data.by_department && data.by_department.length > 0 && (
            <ChartCard title={t("analytics_page.payroll_by_department")}>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart
                  layout="vertical"
                  data={data.by_department.map(
                    (d: { department: string; total_gross_cents: number }) => ({
                      name: d.department,
                      value: d.total_gross_cents / 100,
                    }),
                  )}
                >
                  <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                  <XAxis type="number" tick={{ fontSize: 11 }} />
                  <YAxis
                    type="category"
                    dataKey="name"
                    tick={{ fontSize: 11 }}
                    width={100}
                  />
                  <Tooltip />
                  <Bar
                    dataKey="value"
                    fill={CHART_COLORS[4]}
                    radius={[0, 6, 6, 0]}
                  />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

          {data.by_cost_center && data.by_cost_center.length > 0 && (
            <ChartCard title={t("analytics_page.payroll_by_cost_center")}>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart
                  layout="vertical"
                  data={data.by_cost_center.map(
                    (c: {
                      cost_center: string;
                      total_gross_cents: number;
                    }) => ({
                      name: c.cost_center,
                      value: c.total_gross_cents / 100,
                    }),
                  )}
                >
                  <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                  <XAxis type="number" tick={{ fontSize: 11 }} />
                  <YAxis
                    type="category"
                    dataKey="name"
                    tick={{ fontSize: 11 }}
                    width={100}
                  />
                  <Tooltip />
                  <Bar
                    dataKey="value"
                    fill={CHART_COLORS[2]}
                    radius={[0, 6, 6, 0]}
                  />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

          {data.totals && (
            <div className="grid gap-4 sm:grid-cols-3">
              <KpiBoxCurrency
                icon={Wallet}
                title={t("analytics_page.total_gross")}
                cents={data.totals.total_gross_cents}
                color="blue"
              />
              <KpiBoxCurrency
                icon={Wallet}
                title={t("analytics_page.total_net")}
                cents={data.totals.total_net_cents}
                color="green"
              />
              <KpiBoxCurrency
                icon={Wallet}
                title={t("payroll_detail_page.total_tax")}
                cents={data.totals.total_tax_cents}
                color="amber"
              />
            </div>
          )}
        </div>
      )}
    </QueryBoundary>
  );
}
