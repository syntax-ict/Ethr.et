"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
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

export function PayrollTab() {
  const { t } = useT();
  const { data, isLoading } = useQuery({
    queryKey: ["analytics", "payroll"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/payroll");
      return data;
    },
  });

  if (isLoading || !data)
    return (
      <div className="space-y-6">
        <Skeleton className="h-80 w-full" />
      </div>
    );

  return (
    <div className="space-y-6">
      {data.monthly_trend && data.monthly_trend.length > 0 && (
        <ChartCard title={t("analytics_page.monthly_payroll_cost")}>
          <ResponsiveContainer width="100%" height={300}>
            <BarChart
              data={data.monthly_trend.map(
                (m: {
                  period: string;
                  net_cents: number;
                  gross_cents: number;
                }) => ({
                  period: m.period,
                  Net: m.net_cents / 100,
                  Gross: m.gross_cents / 100,
                }),
              )}
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
              <Bar dataKey="Net" fill={CHART_COLORS[1]} radius={[6, 6, 0, 0]} />
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
  );
}
