"use client";

import { useState } from "react";
import { useT } from "@/lib/i18n/useT";
import { Users, TrendingUp, Wallet, UserCheck } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from "recharts";
import {
  CHART_COLORS,
  ChartCard,
  KpiBox,
  KpiBoxCurrency,
  Metric,
} from "./chart-helpers";
import { useExecutiveOverview } from "@/features/dashboard/executive-api";
import { ComplianceCard } from "@/features/dashboard/components/compliance-card";
import { DepartmentDrillDownDialog } from "@/features/dashboard/components/department-drilldown-dialog";

export function OverviewTab({ branchPublicId }: { branchPublicId?: string }) {
  const { t } = useT();
  const query = useExecutiveOverview({ branchPublicId });
  const [drilldownDept, setDrilldownDept] = useState<string | null>(null);

  // isEmpty is disabled: each section below decides for itself whether it has
  // anything to show, so the dashboard as a whole is never "empty".
  return (
    <QueryBoundary
      query={query}
      loading={<OverviewSkeleton />}
      isEmpty={() => false}
    >
      {(data) => (
        <div className="space-y-6">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <KpiBox
              icon={Users}
              title={t("analytics_page.headcount")}
              value={data.headcount?.active ?? 0}
              sub={`${data.headcount?.total ?? 0} ${t("analytics_page.total_lc")}`}
              color="blue"
            />
            <KpiBox
              icon={UserCheck}
              title={t("analytics_page.attendance_rate")}
              value={`${data.attendance_rate?.today ?? 0}%`}
              sub={t("analytics_page.today")}
              color="green"
            />
            <KpiBoxCurrency
              icon={Wallet}
              title={t("analytics_page.payroll_net")}
              cents={data.payroll_summary?.net_cents ?? 0}
              sub={t("analytics_page.current_month")}
              color="purple"
            />
            <KpiBox
              icon={TrendingUp}
              title={t("analytics_page.turnover")}
              value={`${data.turnover?.rate ?? 0}%`}
              sub={`${data.turnover?.exits ?? 0} ${t("analytics_page.exits")}`}
              color="amber"
            />
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            {data.headcount?.by_department &&
              data.headcount.by_department.length > 0 && (
                <ChartCard
                  title={t("analytics_page.headcount_by_department")}
                  subtitle={t(
                    "executive_dashboard.click_to_drill_down",
                    "Click a bar for department detail",
                  )}
                >
                  <ResponsiveContainer width="100%" height={260}>
                    <BarChart
                      data={data.headcount.by_department.map(
                        (d: {
                          department: string;
                          department_public_id: string | null;
                          count: number;
                        }) => ({
                          name: d.department,
                          value: d.count,
                          publicId: d.department_public_id,
                        }),
                      )}
                    >
                      <CartesianGrid
                        strokeDasharray="3 3"
                        className="opacity-30"
                      />
                      <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                      <YAxis tick={{ fontSize: 11 }} />
                      <Tooltip />
                      <Bar
                        dataKey="value"
                        fill={CHART_COLORS[0]}
                        radius={[6, 6, 0, 0]}
                        cursor="pointer"
                        onClick={(bar: {
                          payload?: { publicId?: string | null };
                        }) => {
                          const publicId = bar.payload?.publicId;
                          if (publicId) setDrilldownDept(publicId);
                        }}
                      />
                    </BarChart>
                  </ResponsiveContainer>
                </ChartCard>
              )}

            {data.workforce_growth && (
              <ChartCard title={t("analytics_page.hires_over_time")}>
                <ResponsiveContainer width="100%" height={260}>
                  <LineChart data={data.workforce_growth}>
                    <CartesianGrid
                      strokeDasharray="3 3"
                      className="opacity-30"
                    />
                    <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} />
                    <Tooltip />
                    <Line
                      type="monotone"
                      dataKey="hires"
                      stroke={CHART_COLORS[1]}
                      strokeWidth={2}
                      dot={{ r: 4 }}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </ChartCard>
            )}
          </div>

          {data.leave_utilization && (
            <ChartCard title={t("analytics_page.leave_utilization")}>
              <div className="flex items-center justify-around py-6">
                <Metric
                  label={t("analytics_page.entitled_days")}
                  value={data.leave_utilization.entitled_days}
                />
                <Metric
                  label={t("analytics_page.used_days")}
                  value={data.leave_utilization.used_days}
                />
                <Metric
                  label={t("analytics_page.utilization")}
                  value={`${data.leave_utilization.utilization_rate}%`}
                  highlight
                />
              </div>
            </ChartCard>
          )}

          <ComplianceCard branchPublicId={branchPublicId} />

          <DepartmentDrillDownDialog
            departmentPublicId={drilldownDept}
            onOpenChange={(open) => {
              if (!open) setDrilldownDept(null);
            }}
          />
        </div>
      )}
    </QueryBoundary>
  );
}

function OverviewSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-28" />
        ))}
      </div>
      <Skeleton className="h-80 w-full" />
    </div>
  );
}
