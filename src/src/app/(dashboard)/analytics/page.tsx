"use client";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { PageHeader } from "@/components/shared/page-header";
import { RoleGate } from "@/components/shared/role-gate";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { Users, TrendingUp, Wallet, UserCheck, Building2 } from "lucide-react";
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

const COLORS = [
  "#3b82f6",
  "#10b981",
  "#f59e0b",
  "#ef4444",
  "#8b5cf6",
  "#ec4899",
  "#06b6d4",
  "#84cc16",
];

export default function AnalyticsPage() {
  const { t } = useT();
  return (
    <RoleGate minRole="tenant_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("analytics_page.title")}
          description={t("analytics_page.description")}
        />

        <Tabs defaultValue="overview">
          <TabsList>
            <TabsTrigger value="overview">
              {t("analytics_page.overview")}
            </TabsTrigger>
            <TabsTrigger value="attendance">{t("nav.attendance")}</TabsTrigger>
            <TabsTrigger value="payroll">{t("nav.payroll")}</TabsTrigger>
            <TabsTrigger value="workforce">
              {t("analytics_page.workforce")}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="mt-4">
            <OverviewTab />
          </TabsContent>
          <TabsContent value="attendance" className="mt-4">
            <AttendanceTab />
          </TabsContent>
          <TabsContent value="payroll" className="mt-4">
            <PayrollTab />
          </TabsContent>
          <TabsContent value="workforce" className="mt-4">
            <WorkforceTab />
          </TabsContent>
        </Tabs>
      </div>
    </RoleGate>
  );
}

function OverviewTab() {
  const { t } = useT();
  const { data, isLoading } = useQuery({
    queryKey: ["analytics", "overview"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive");
      return data;
    },
  });

  if (isLoading || !data) return <ChartSkeleton />;

  return (
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
            <ChartCard title={t("analytics_page.headcount_by_department")}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart
                  data={data.headcount.by_department.map(
                    (d: { department: string; count: number }) => ({
                      name: d.department,
                      value: d.count,
                    }),
                  )}
                >
                  <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                  <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Bar dataKey="value" fill="#3b82f6" radius={[6, 6, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          )}

        {data.workforce_growth && (
          <ChartCard title={t("analytics_page.hires_over_time")}>
            <ResponsiveContainer width="100%" height={260}>
              <LineChart data={data.workforce_growth}>
                <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip />
                <Line
                  type="monotone"
                  dataKey="hires"
                  stroke="#10b981"
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
    </div>
  );
}

function AttendanceTab() {
  const { t } = useT();
  const { data, isLoading } = useQuery({
    queryKey: ["analytics", "attendance"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/attendance");
      return data;
    },
  });

  if (isLoading || !data) return <ChartSkeleton />;

  return (
    <div className="space-y-6">
      {data.daily_trend && data.daily_trend.length > 0 && (
        <ChartCard title={t("analytics_page.daily_attendance_trend")}>
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={data.daily_trend}>
              <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
              <XAxis dataKey="date" tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip />
              <Line
                type="monotone"
                dataKey="present"
                stroke="#10b981"
                strokeWidth={2}
              />
            </LineChart>
          </ResponsiveContainer>
        </ChartCard>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        {data.by_source && data.by_source.length > 0 && (
          <ChartCard title={t("analytics_page.by_source")}>
            <ResponsiveContainer width="100%" height={260}>
              <PieChart>
                <Pie
                  data={data.by_source}
                  dataKey="count"
                  nameKey="source"
                  label
                  outerRadius={80}
                >
                  {data.by_source.map((_: unknown, i: number) => (
                    <Cell key={i} fill={COLORS[i % COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          </ChartCard>
        )}

        {data.top_late && data.top_late.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                {t("analytics_page.top_late_arrivals")}
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="space-y-2">
                {data.top_late
                  .slice(0, 10)
                  .map(
                    (
                      e: { employee_name: string; late_count: number },
                      i: number,
                    ) => (
                      <div
                        key={i}
                        className="flex items-center justify-between rounded-lg border p-2"
                      >
                        <span className="text-sm">{e.employee_name}</span>
                        <span className="text-sm font-semibold text-red-600">
                          {e.late_count}x
                        </span>
                      </div>
                    ),
                  )}
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </div>
  );
}

function PayrollTab() {
  const { t } = useT();
  const { data, isLoading } = useQuery({
    queryKey: ["analytics", "payroll"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/payroll");
      return data;
    },
  });

  if (isLoading || !data) return <ChartSkeleton />;

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
              <Bar dataKey="Gross" fill="#3b82f6" radius={[6, 6, 0, 0]} />
              <Bar dataKey="Net" fill="#10b981" radius={[6, 6, 0, 0]} />
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
              <Bar dataKey="value" fill="#8b5cf6" radius={[0, 6, 6, 0]} />
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

function WorkforceTab() {
  const { t } = useT();
  const { data, isLoading } = useQuery({
    queryKey: ["analytics", "workforce"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/workforce");
      return data;
    },
  });

  if (isLoading || !data) return <ChartSkeleton />;

  return (
    <div className="space-y-6">
      {data.headcount_trend && data.headcount_trend.length > 0 && (
        <ChartCard title={t("analytics_page.headcount_trend")}>
          <ResponsiveContainer width="100%" height={300}>
            <LineChart data={data.headcount_trend}>
              <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
              <XAxis dataKey="month" tick={{ fontSize: 11 }} />
              <YAxis tick={{ fontSize: 11 }} />
              <Tooltip />
              <Line
                type="monotone"
                dataKey="count"
                stroke="#3b82f6"
                strokeWidth={2}
                dot={{ r: 4 }}
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
                    <Cell key={i} fill={COLORS[i % COLORS.length]} />
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
                <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                <XAxis dataKey="bucket" tick={{ fontSize: 11 }} />
                <YAxis tick={{ fontSize: 11 }} />
                <Tooltip />
                <Bar dataKey="count" fill="#10b981" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </ChartCard>
        )}
      </div>
    </div>
  );
}

function ChartCard({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">{title}</CardTitle>
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

function ChartSkeleton() {
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-28" />
        ))}
      </div>
      <Skeleton className="h-80 w-full" />
    </div>
  );
}

const colorMap: Record<string, string> = {
  blue: "bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-400",
  green: "bg-green-100 text-green-600 dark:bg-green-950 dark:text-green-400",
  amber: "bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400",
  purple:
    "bg-purple-100 text-purple-600 dark:bg-purple-950 dark:text-purple-400",
};

function KpiBox({
  icon: Icon,
  title,
  value,
  sub,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  value: string | number;
  sub: string;
  color: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div
          className={`flex h-10 w-10 items-center justify-center rounded-xl ${colorMap[color]}`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs text-muted-foreground">{title}</p>
          <p className="text-xl font-bold text-foreground">{value}</p>
          <p className="text-[10px] text-muted-foreground">{sub}</p>
        </div>
      </CardContent>
    </Card>
  );
}

function KpiBoxCurrency({
  icon: Icon,
  title,
  cents,
  sub,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  cents: number;
  sub?: string;
  color: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div
          className={`flex h-10 w-10 items-center justify-center rounded-xl ${colorMap[color]}`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-xs text-muted-foreground">{title}</p>
          <CurrencyDisplay
            cents={cents}
            className="text-lg font-bold text-foreground"
          />
          {sub && <p className="text-[10px] text-muted-foreground">{sub}</p>}
        </div>
      </CardContent>
    </Card>
  );
}

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
    <div className="text-center">
      <p className="text-sm text-muted-foreground">{label}</p>
      <p
        className={`mt-1 text-3xl font-bold ${highlight ? "text-primary" : "text-foreground"}`}
      >
        {value}
      </p>
    </div>
  );
}
