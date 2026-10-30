"use client";

import { useState } from "react";
import { useT } from "@/lib/i18n/useT";
import { monthKeyLabel } from "./axis-format";
import { Users, TrendingUp, Wallet, UserCheck } from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
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
  axisProps,
  gridProps,
  lineTooltipProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import { ChartCard } from "@/components/dashboard/chart-card";
import { StatCard } from "@/components/dashboard/stat-card";
import { CompactCurrency } from "@/components/shared/currency-display";
import { useExecutiveOverview } from "@/features/dashboard/executive-api";
import { ComplianceCard } from "@/features/dashboard/components/compliance-card";
import { DepartmentDrillDownDialog } from "@/features/dashboard/components/department-drilldown-dialog";

export function OverviewTab({ branchPublicId }: { branchPublicId?: string }) {
  const { t, locale } = useT();
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
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
              icon={Users}
              label={t("analytics_page.headcount")}
              value={data.headcount.active}
              hint={t("analytics_page.headcount_total", ":count total", {
                count: data.headcount.total,
              })}
              tone="primary"
            />
            <StatCard
              icon={UserCheck}
              label={t("analytics_page.attendance_rate")}
              // Null when no one was expected at work today.
              value={
                data.attendance_rate.today == null
                  ? t("analytics_page.no_one_scheduled", "No one scheduled")
                  : `${data.attendance_rate.today}%`
              }
              hint={
                data.attendance_rate.today == null
                  ? t(
                      "analytics_page.no_one_scheduled_hint",
                      "Rest day or holiday",
                    )
                  : t("analytics_page.today")
              }
              tone="success"
            />
            <StatCard
              icon={Wallet}
              label={t("analytics_page.payroll_net")}
              value={<CompactCurrency cents={data.payroll_summary.net_cents} />}
              hint={t("analytics_page.current_month")}
              tone="info"
            />
            <StatCard
              icon={TrendingUp}
              label={t("analytics_page.turnover")}
              value={`${data.turnover.rate}%`}
              hint={t("analytics_page.exits_count", ":count exits", {
                count: data.turnover.exits,
              })}
              tone="warning"
            />
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            {data.headcount.by_department.length > 0 && (
              <ChartCard
                title={t("analytics_page.headcount_by_department")}
                description={t(
                  "executive_dashboard.click_to_drill_down",
                  "Click a bar for department detail",
                )}
              >
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart
                    data={data.headcount.by_department.map((d) => ({
                      name: d.department,
                      value: d.count,
                      publicId: d.department_public_id,
                    }))}
                  >
                    <CartesianGrid {...gridProps} />
                    <XAxis dataKey="name" {...axisProps} interval={0} />
                    <YAxis {...axisProps} allowDecimals={false} width={36} />
                    <Tooltip {...tooltipProps} />
                    <Bar
                      dataKey="value"
                      name={t("analytics_page.headcount")}
                      fill={seriesColor(0)}
                      radius={[6, 6, 0, 0]}
                      maxBarSize={48}
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
                  {/* Hires are whole numbers per month: bars. As an area
                      chart a month with no figure broke the line, and the
                      chart read as scattered dots (QA 2026-10-13). */}
                  <BarChart
                    data={data.workforce_growth}
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
                    <YAxis {...axisProps} allowDecimals={false} width={36} />
                    <Tooltip
                      {...lineTooltipProps}
                      labelFormatter={(label) => monthKeyLabel(label, locale)}
                    />
                    <Bar
                      dataKey="hires"
                      name={t("analytics_page.hires", "Hires")}
                      fill={seriesColor(1)}
                      radius={[4, 4, 0, 0]}
                      maxBarSize={36}
                    />
                  </BarChart>
                </ResponsiveContainer>
              </ChartCard>
            )}
          </div>

          {data.leave_utilization && (
            <ChartCard title={t("analytics_page.leave_utilization")}>
              <div className="flex items-center justify-around divide-x divide-border py-4">
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
            branchPublicId={branchPublicId}
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
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-32 rounded-xl" />
        ))}
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        <Skeleton className="h-80 rounded-xl" />
        <Skeleton className="h-80 rounded-xl" />
      </div>
    </div>
  );
}

/** One figure in the leave utilisation row. */
function Metric({
  label,
  value,
  highlight,
}: {
  label: string;
  value: string | number;
  highlight?: boolean;
}) {
  return (
    <div className="min-w-0 flex-1 px-4 text-center">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p
        className={`mt-1 text-3xl font-semibold tracking-tight tabular-nums ${highlight ? "text-primary" : "text-foreground"}`}
      >
        {value}
      </p>
    </div>
  );
}
