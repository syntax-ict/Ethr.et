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
} from "recharts";
import {
  ChartLegend,
  axisProps,
  gridProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import { ChartCard } from "@/components/dashboard/chart-card";
import { StatCard } from "@/components/dashboard/stat-card";
import { CompactCurrency } from "@/components/shared/currency-display";
import { birrTick, birrTooltip } from "./axis-format";

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
          <div className="grid gap-4 sm:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-32 rounded-xl" />
            ))}
          </div>
          <Skeleton className="h-80 w-full rounded-xl" />
        </div>
      }
      isEmpty={() => false}
    >
      {(data) => (
        <div className="space-y-6">
          {data.totals && (
            <div className="grid gap-4 sm:grid-cols-3">
              <StatCard
                icon={Wallet}
                label={t("analytics_page.total_gross")}
                value={
                  <CompactCurrency cents={data.totals.total_gross_cents} />
                }
                tone="primary"
              />
              <StatCard
                icon={Wallet}
                label={t("analytics_page.total_net")}
                value={<CompactCurrency cents={data.totals.total_net_cents} />}
                tone="success"
              />
              <StatCard
                icon={Wallet}
                label={t("payroll_detail_page.total_tax")}
                value={<CompactCurrency cents={data.totals.total_tax_cents} />}
                tone="warning"
              />
            </div>
          )}

          {data.monthly_trend && data.monthly_trend.length > 0 && (
            <ChartCard
              title={t("analytics_page.monthly_payroll_cost")}
              description={
                (forecast.data?.payroll_gross.projected.length ?? 0) > 0
                  ? t(
                      "executive_dashboard.forecast_subtitle",
                      "Dashed bars are a simple trend projection, not a guarantee.",
                    )
                  : undefined
              }
            >
              <ChartLegend
                className="mb-3"
                items={[
                  {
                    label: t("analytics_page.gross", "Gross"),
                    color: seriesColor(0),
                  },
                  {
                    label: t("analytics_page.net", "Net"),
                    color: seriesColor(1),
                  },
                  ...((forecast.data?.payroll_gross.projected.length ?? 0) > 0
                    ? [
                        {
                          label: t(
                            "executive_dashboard.projected_gross",
                            "Projected gross",
                          ),
                          color: `color-mix(in srgb, ${seriesColor(0)} 35%, transparent)`,
                        },
                      ]
                    : []),
                ]}
              />
              <ResponsiveContainer width="100%" height={300}>
                <BarChart
                  barGap={4}
                  data={[
                    ...data.monthly_trend.map((m) => ({
                      period: m.period,
                      Net: m.net_cents / 100,
                      Gross: m.gross_cents / 100,
                    })),
                    ...(forecast.data?.payroll_gross.projected ?? []).map(
                      (p) => ({
                        period: p.label,
                        Projected: p.value / 100,
                      }),
                    ),
                  ]}
                >
                  <CartesianGrid {...gridProps} />
                  <XAxis dataKey="period" {...axisProps} />
                  <YAxis {...axisProps} tickFormatter={birrTick} width={48} />
                  <Tooltip {...tooltipProps} formatter={birrTooltip} />
                  <Bar
                    dataKey="Gross"
                    name={t("analytics_page.gross", "Gross")}
                    fill={seriesColor(0)}
                    radius={[4, 4, 0, 0]}
                    maxBarSize={28}
                  />
                  <Bar
                    dataKey="Net"
                    name={t("analytics_page.net", "Net")}
                    fill={seriesColor(1)}
                    radius={[4, 4, 0, 0]}
                    maxBarSize={28}
                  />
                  <Bar
                    dataKey="Projected"
                    name={t(
                      "executive_dashboard.projected_gross",
                      "Projected gross",
                    )}
                    fill={seriesColor(0)}
                    fillOpacity={0.35}
                    radius={[4, 4, 0, 0]}
                    maxBarSize={28}
                  />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

          {data.overtime_trend && data.overtime_trend.length > 0 && (
            <ChartCard title={t("analytics_page.overtime_trend")}>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart
                  data={data.overtime_trend.map((o) => ({
                    period: o.period,
                    Overtime: o.overtime_cents / 100,
                  }))}
                >
                  <CartesianGrid {...gridProps} />
                  <XAxis dataKey="period" {...axisProps} />
                  <YAxis {...axisProps} tickFormatter={birrTick} width={48} />
                  <Tooltip {...tooltipProps} formatter={birrTooltip} />
                  <Bar
                    dataKey="Overtime"
                    name={t("analytics_page.overtime_trend")}
                    fill={seriesColor(2)}
                    radius={[4, 4, 0, 0]}
                    maxBarSize={36}
                  />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

          <div className="grid gap-6 lg:grid-cols-2">
            {data.by_department && data.by_department.length > 0 && (
              <ChartCard title={t("analytics_page.payroll_by_department")}>
                <ResponsiveContainer width="100%" height={300}>
                  <BarChart
                    layout="vertical"
                    data={data.by_department.map((d) => ({
                      name: d.department,
                      value: d.total_gross_cents / 100,
                    }))}
                  >
                    <CartesianGrid {...gridProps} />
                    <XAxis
                      type="number"
                      {...axisProps}
                      tickFormatter={birrTick}
                    />
                    <YAxis
                      type="category"
                      dataKey="name"
                      {...axisProps}
                      width={110}
                    />
                    <Tooltip {...tooltipProps} formatter={birrTooltip} />
                    <Bar
                      dataKey="value"
                      name={t("analytics_page.gross", "Gross")}
                      fill={seriesColor(0)}
                      radius={[0, 4, 4, 0]}
                      maxBarSize={22}
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
                    data={data.by_cost_center.map((c) => ({
                      name: c.cost_center,
                      value: c.total_gross_cents / 100,
                    }))}
                  >
                    <CartesianGrid {...gridProps} />
                    <XAxis
                      type="number"
                      {...axisProps}
                      tickFormatter={birrTick}
                    />
                    <YAxis
                      type="category"
                      dataKey="name"
                      {...axisProps}
                      width={110}
                    />
                    <Tooltip {...tooltipProps} formatter={birrTooltip} />
                    <Bar
                      dataKey="value"
                      name={t("analytics_page.gross", "Gross")}
                      fill={seriesColor(3)}
                      radius={[0, 4, 4, 0]}
                      maxBarSize={22}
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
