"use client";

import { useState } from "react";
import { TrendingUp, Clock, Users } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";

interface OvertimeResponse {
  period: string;
  employees: Array<{
    employee_public_id: string;
    employee_name: string;
    total_overtime_minutes: number;
    days_with_overtime: number;
  }>;
}

const THRESHOLD_MINUTES = 600; // 10 hours/month flag

export default function OvertimePage() {
  const { t } = useT();
  const [period, setPeriod] = useState<"weekly" | "monthly">("monthly");

  const query = useQuery<OvertimeResponse>({
    queryKey: ["attendance", "overtime", period],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/overtime", {
        params: { period },
      });
      return data;
    },
  });

  const employees = (query.data?.employees ?? [])
    .slice()
    .sort((a, b) => b.total_overtime_minutes - a.total_overtime_minutes);
  const totalMinutes = employees.reduce(
    (s, e) => s + e.total_overtime_minutes,
    0,
  );
  const overThreshold = employees.filter(
    (e) => e.total_overtime_minutes > THRESHOLD_MINUTES,
  ).length;

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("attendance.overtime_page.title")}
          description={t("attendance.overtime_page.description")}
          actions={
            <Select
              value={period}
              onValueChange={(v) => setPeriod(v as "weekly" | "monthly")}
            >
              {/* A Select in an actions bar has no visible label to point at,
                  so it needs its own name — otherwise it announces only as
                  "combobox". */}
              <SelectTrigger
                className="w-40"
                aria-label={t("attendance.overtime_page.period", "Period")}
              >
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="weekly">
                  {t("attendance.overtime_page.this_week")}
                </SelectItem>
                <SelectItem value="monthly">
                  {t("attendance.overtime_page.this_month")}
                </SelectItem>
              </SelectContent>
            </Select>
          }
        />

        <QueryBoundary
          query={query}
          loading={
            <div className="grid gap-4 sm:grid-cols-3">
              {Array.from({ length: 3 }).map((_, i) => (
                <Skeleton key={i} className="h-32" />
              ))}
            </div>
          }
          isEmpty={() => false}
        >
          {() => (
            <>
              <div className="grid gap-4 sm:grid-cols-3">
                <Kpi
                  icon={Users}
                  title={t("attendance.overtime_page.employees_with_ot")}
                  value={String(employees.length)}
                  sub={
                    period === "monthly"
                      ? t("attendance.overtime_page.this_month")
                      : t("attendance.overtime_page.this_week")
                  }
                  color="info"
                />
                <Kpi
                  icon={Clock}
                  title={t("attendance.overtime_page.total_ot_hours")}
                  value={(totalMinutes / 60).toFixed(1)}
                  sub={t("attendance.overtime_page.across_all_employees")}
                  color="brand"
                />
                <Kpi
                  icon={TrendingUp}
                  title={t("attendance.overtime_page.over_threshold")}
                  value={String(overThreshold)}
                  sub={`>${(THRESHOLD_MINUTES / 60).toFixed(0)}h ${t("attendance.overtime_page.flagged")}`}
                  color={overThreshold > 0 ? "danger" : "success"}
                />
              </div>

              <Card>
                <CardHeader>
                  <CardTitle className="text-base">
                    {t("attendance.overtime_page.by_employee")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  {employees.length === 0 ? (
                    <EmptyState
                      icon={Clock}
                      title={t("attendance.overtime_page.empty_title")}
                      description={`${t("attendance.overtime_page.empty_desc_prefix")} ${period === "monthly" ? t("attendance.overtime_page.this_month_lc") : t("attendance.overtime_page.this_week_lc")}`}
                    />
                  ) : (
                    <SimpleTable
                      caption={t("attendance.overtime_page.by_employee")}
                      headers={[
                        t("attendance.employee"),
                        t("attendance.overtime_page.days_with_ot"),
                        t("attendance.overtime_page.total_hours"),
                        t("common.status"),
                      ]}
                      align={["left", "right", "right", "right"]}
                      colClassName={["", "hidden sm:table-cell", "", ""]}
                      rows={employees.map((e) => {
                        const hours = e.total_overtime_minutes / 60;
                        const isFlagged =
                          e.total_overtime_minutes > THRESHOLD_MINUTES;
                        return {
                          key: e.employee_public_id,
                          cells: [
                            <span key="n" className="font-medium">
                              {e.employee_name}
                            </span>,
                            <span key="d" className="text-muted-foreground">
                              {e.days_with_overtime}
                            </span>,
                            <span key="h" className="font-mono font-semibold">
                              {hours.toFixed(1)}h
                            </span>,
                            isFlagged ? (
                              <Badge
                                key="s"
                                variant="outline"
                                className="bg-destructive-soft text-destructive-on-soft border-0"
                              >
                                {t(
                                  "attendance.overtime_page.over_threshold_badge",
                                )}
                              </Badge>
                            ) : (
                              <Badge
                                key="s"
                                variant="outline"
                                className="bg-success-soft text-success-on-soft border-0"
                              >
                                {t("attendance.overtime_page.ok")}
                              </Badge>
                            ),
                          ],
                        };
                      })}
                    />
                  )}
                </CardContent>
              </Card>
            </>
          )}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}

const colorClass: Record<string, string> = {
  info: "bg-info-soft text-info-on-soft",
  brand: "bg-brand-soft text-brand-on-soft",
  danger: "bg-destructive-soft text-destructive-on-soft",
  success: "bg-success-soft text-success-on-soft",
};

function Kpi({
  icon: Icon,
  title,
  value,
  sub,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  value: string;
  sub: string;
  color: string;
}) {
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-5">
        <div
          className={`flex h-11 w-11 items-center justify-center rounded-xl ${colorClass[color]}`}
        >
          <Icon className="h-5 w-5" />
        </div>
        <div>
          <p className="text-sm text-muted-foreground">{title}</p>
          <p className="text-2xl font-bold text-foreground">{value}</p>
          <p className="text-xs text-muted-foreground">{sub}</p>
        </div>
      </CardContent>
    </Card>
  );
}
