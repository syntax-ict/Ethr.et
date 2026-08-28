"use client";

import { useState } from "react";
import {
  Activity,
  Clock,
  LogOut,
  AlertCircle,
  AlertTriangle,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";

interface IntelligenceResponse {
  date: string;
  anomalies: {
    count: number;
    thresholds: {
      excessive_hours_minutes: number;
      excessive_overtime_minutes: number;
    };
    records: Array<{
      employee_public_id: string;
      employee_name: string;
      types: string[];
      worked_minutes: number;
      overtime_minutes: number;
    }>;
  };
  late_arrivals: {
    count: number;
    records: Array<{
      employee_name: string;
      minutes_late: number;
      check_in: string;
      shift_start: string;
    }>;
  };
  early_departures: {
    count: number;
    records: Array<{
      public_id: string;
      employee?: { name: string };
      check_out: string;
    }>;
  };
  missing_punches: {
    count: number;
    records: Array<{
      public_id: string;
      employee?: { name: string };
      date: string;
    }>;
  };
}

export default function AttendanceIntelligencePage() {
  const { t } = useT();
  const [date, setDate] = useState(new Date().toISOString().split("T")[0]);

  const query = useQuery<IntelligenceResponse>({
    queryKey: ["attendance", "intelligence", date],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/intelligence", {
        params: { date },
      });
      return data;
    },
  });

  const data = query.data;

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title={t("attendance.intelligence_page.title")}
          description={t("attendance.intelligence_page.description")}
          actions={
            <div className="flex items-center gap-2">
              <Label className="text-xs">
                {t("attendance.intelligence_page.date_label")}:
              </Label>
              <DualCalendarDateInput
                value={date}
                onChange={setDate}
                className="w-44"
              />
            </div>
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
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Kpi
                  icon={AlertTriangle}
                  title={t("attendance.intelligence_page.anomalies")}
                  value={data?.anomalies?.count ?? 0}
                  color="danger"
                />
                <Kpi
                  icon={Clock}
                  title={t("attendance.intelligence_page.late_arrivals")}
                  value={data?.late_arrivals?.count ?? 0}
                  color="warning"
                />
                <Kpi
                  icon={LogOut}
                  title={t("attendance.intelligence_page.early_departures")}
                  value={data?.early_departures?.count ?? 0}
                  color="brand"
                />
                <Kpi
                  icon={AlertCircle}
                  title={t("attendance.intelligence_page.missing_punches")}
                  value={data?.missing_punches?.count ?? 0}
                  color="danger"
                />
              </div>

              <Card>
                <CardHeader>
                  <CardTitle className="text-base flex items-center gap-2">
                    <AlertTriangle className="h-4 w-4 text-destructive" />{" "}
                    {t("attendance.intelligence_page.anomalies")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  {(data?.anomalies?.records ?? []).length === 0 ? (
                    <EmptyState
                      icon={Activity}
                      title={t("attendance.intelligence_page.no_anomalies")}
                      description={t(
                        "attendance.intelligence_page.everyone_normal",
                      )}
                    />
                  ) : (
                    <SimpleTable
                      caption={t("attendance.intelligence_page.anomalies")}
                      headers={[
                        t("attendance.employee"),
                        t("attendance.intelligence_page.anomaly_types"),
                        t("attendance.intelligence_page.explanation"),
                      ]}
                      align={["left", "left", "left"]}
                      rows={data!.anomalies.records.map((r, i) => ({
                        key: r.employee_public_id || String(i),
                        cells: [
                          <span key="n" className="font-medium">
                            {r.employee_name}
                          </span>,
                          <div key="t" className="flex flex-wrap gap-1">
                            {r.types.map((type) => (
                              <Badge
                                key={type}
                                variant="outline"
                                className="bg-destructive-soft text-destructive-on-soft border-0"
                              >
                                {t(
                                  `attendance.intelligence_page.anomaly_${type}`,
                                  type,
                                )}
                              </Badge>
                            ))}
                          </div>,
                          <span
                            key="e"
                            className="text-muted-foreground text-xs"
                          >
                            {r.types.includes("excessive_hours") &&
                              t(
                                "attendance.intelligence_page.worked_explanation",
                                "Worked :worked (threshold :threshold)",
                                {
                                  worked: formatMinutes(r.worked_minutes),
                                  threshold: formatMinutes(
                                    data!.anomalies.thresholds
                                      .excessive_hours_minutes,
                                  ),
                                },
                              )}
                            {r.types.includes("excessive_hours") &&
                              r.types.includes("excessive_overtime") &&
                              " · "}
                            {r.types.includes("excessive_overtime") &&
                              t(
                                "attendance.intelligence_page.overtime_explanation",
                                "Overtime :overtime (threshold :threshold)",
                                {
                                  overtime: formatMinutes(r.overtime_minutes),
                                  threshold: formatMinutes(
                                    data!.anomalies.thresholds
                                      .excessive_overtime_minutes,
                                  ),
                                },
                              )}
                          </span>,
                        ],
                      }))}
                    />
                  )}
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle className="text-base flex items-center gap-2">
                    <Clock className="h-4 w-4 text-warning" />{" "}
                    {t("attendance.intelligence_page.late_arrivals")}
                  </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                  {(data?.late_arrivals?.records ?? []).length === 0 ? (
                    <EmptyState
                      icon={Activity}
                      title={t("attendance.intelligence_page.no_late_arrivals")}
                      description={t(
                        "attendance.intelligence_page.everyone_on_time",
                      )}
                    />
                  ) : (
                    <SimpleTable
                      caption={t("attendance.intelligence_page.late_arrivals")}
                      headers={[
                        t("attendance.employee"),
                        t("attendance.intelligence_page.check_in_col"),
                        t("attendance.intelligence_page.shift_start"),
                        t("attendance.intelligence_page.late_by"),
                      ]}
                      align={["left", "left", "left", "right"]}
                      rows={data!.late_arrivals.records.map((r, i) => ({
                        key: String(i),
                        cells: [
                          <span key="n" className="font-medium">
                            {r.employee_name}
                          </span>,
                          <span key="ci" className="text-muted-foreground">
                            {r.check_in
                              ? new Date(r.check_in).toLocaleTimeString()
                              : "—"}
                          </span>,
                          <span key="ss" className="text-muted-foreground">
                            {r.shift_start ?? "—"}
                          </span>,
                          <Badge
                            key="m"
                            variant="outline"
                            className="bg-warning-soft text-warning-on-soft border-0"
                          >
                            +{r.minutes_late}{" "}
                            {t("attendance.intelligence_page.min")}
                          </Badge>,
                        ],
                      }))}
                    />
                  )}
                </CardContent>
              </Card>

              <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                  <CardHeader>
                    <CardTitle className="text-base flex items-center gap-2">
                      <LogOut className="h-4 w-4 text-warning" />{" "}
                      {t("attendance.intelligence_page.early_departures")}
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    {(data?.early_departures?.records ?? []).length === 0 ? (
                      <p className="text-sm text-muted-foreground py-4 text-center">
                        {t("attendance.intelligence_page.no_early_departures")}
                      </p>
                    ) : (
                      <div className="space-y-1">
                        {data!.early_departures.records.map((r) => (
                          <div
                            key={r.public_id}
                            className="flex items-center justify-between rounded-lg border p-2 text-sm"
                          >
                            <span className="font-medium">
                              {r.employee?.name ?? "—"}
                            </span>
                            <span className="text-xs text-muted-foreground">
                              {r.check_out
                                ? new Date(r.check_out).toLocaleTimeString()
                                : "—"}
                            </span>
                          </div>
                        ))}
                      </div>
                    )}
                  </CardContent>
                </Card>

                <Card>
                  <CardHeader>
                    <CardTitle className="text-base flex items-center gap-2">
                      <AlertCircle className="h-4 w-4 text-destructive" />{" "}
                      {t("attendance.intelligence_page.missing_punches")}
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    {(data?.missing_punches?.records ?? []).length === 0 ? (
                      <p className="text-sm text-muted-foreground py-4 text-center">
                        {t("attendance.intelligence_page.no_missing_punches")}
                      </p>
                    ) : (
                      <div className="space-y-1">
                        {data!.missing_punches.records.map((r) => (
                          <div
                            key={r.public_id}
                            className="flex items-center justify-between rounded-lg border p-2 text-sm"
                          >
                            <span className="font-medium">
                              {r.employee?.name ?? "—"}
                            </span>
                            <Badge variant="outline" className="text-[10px]">
                              {r.date}
                            </Badge>
                          </div>
                        ))}
                      </div>
                    )}
                  </CardContent>
                </Card>
              </div>
            </>
          )}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}

const colorClass: Record<string, string> = {
  warning: "bg-warning-soft text-warning-on-soft",
  brand: "bg-brand-soft text-brand-on-soft",
  danger: "bg-destructive-soft text-destructive-on-soft",
};

function formatMinutes(minutes: number): string {
  const hours = Math.floor(minutes / 60);
  const mins = minutes % 60;
  return `${hours}h ${mins}m`;
}

function Kpi({
  icon: Icon,
  title,
  value,
  color,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  value: number;
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
        </div>
      </CardContent>
    </Card>
  );
}
