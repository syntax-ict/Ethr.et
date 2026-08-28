"use client";

import { useState } from "react";
import { Users, ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";

interface TeamRecord {
  public_id: string;
  employee?: { name: string; public_id: string };
  employee_name?: string;
  date: string;
  check_in: string | null;
  check_out: string | null;
  status: string;
  source: string;
}

function todayStr() {
  return new Date().toISOString().split("T")[0];
}

function shiftDate(dateStr: string, days: number) {
  const d = new Date(dateStr);
  d.setDate(d.getDate() + days);
  return d.toISOString().split("T")[0];
}

export default function TeamAttendancePage() {
  const { t } = useT();
  const [date, setDate] = useState(todayStr);

  const query = useQuery({
    queryKey: ["attendance", "team", date],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/team", {
        params: { per_page: 50, date },
      });
      return data;
    },
  });

  const records: TeamRecord[] = query.data?.data ?? [];
  const isToday = date === todayStr();

  return (
    <RoleGate minRole="supervisor">
      <div className="space-y-6">
        <PageHeader
          title={t("attendance.team_page.title")}
          description={
            isToday
              ? t("attendance.team_page.today_desc")
              : `${t("attendance.team_page.date_desc_prefix")} ${date}`
          }
          actions={
            <div className="flex items-center gap-1">
              <Button
                variant="outline"
                size="icon"
                className="h-8 w-8"
                onClick={() => setDate(shiftDate(date, -1))}
                aria-label={t(
                  "attendance.team_page.previous_day",
                  "Previous day",
                )}
              >
                <ChevronLeft className="h-4 w-4" aria-hidden="true" />
              </Button>
              <DualCalendarDateInput
                value={date}
                onChange={(v) => setDate(v || todayStr())}
                className="w-40"
                aria-label={t(
                  "attendance.team_page.select_date",
                  "Select date",
                )}
              />
              <Button
                variant="outline"
                size="icon"
                className="h-8 w-8"
                onClick={() => setDate(shiftDate(date, 1))}
                disabled={isToday}
                aria-label={t("attendance.team_page.next_day", "Next day")}
              >
                <ChevronRight className="h-4 w-4" aria-hidden="true" />
              </Button>
              {!isToday && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="ml-1 text-xs"
                  onClick={() => setDate(todayStr())}
                >
                  {t("attendance.team_page.today")}
                </Button>
              )}
            </div>
          }
        />

        <QueryBoundary
          query={query}
          loading={
            <div className="space-y-3">
              {Array.from({ length: 5 }).map((_, i) => (
                <Skeleton key={i} className="h-14 w-full" />
              ))}
            </div>
          }
          isEmpty={() => records.length === 0}
          empty={
            <EmptyState
              icon={Users}
              title={t("attendance.team_page.empty_title")}
              description={t("attendance.team_page.empty_desc")}
            />
          }
        >
          {() => (
            <Card>
              <CardContent className="p-0">
                <SimpleTable
                  caption={t("attendance.team_page.title", "Team Attendance")}
                  headers={[
                    t("attendance.employee"),
                    t("common.date"),
                    t("attendance.in"),
                    t("attendance.out"),
                    t("attendance.source"),
                    t("common.status"),
                  ]}
                  colClassName={["", "", "", "", "hidden sm:table-cell", ""]}
                  rows={records.map((r) => ({
                    key: r.public_id,
                    cells: [
                      <span key="e" className="font-medium">
                        {r.employee?.name ?? r.employee_name ?? "—"}
                      </span>,
                      <span key="d" className="text-muted-foreground">
                        {r.date}
                      </span>,
                      <span key="i" className="text-muted-foreground">
                        {r.check_in ?? "—"}
                      </span>,
                      <span key="o" className="text-muted-foreground">
                        {r.check_out ?? "—"}
                      </span>,
                      <span
                        key="s"
                        className="capitalize text-muted-foreground"
                      >
                        {r.source}
                      </span>,
                      <StatusBadge key="st" status={r.status} />,
                    ],
                  }))}
                />
              </CardContent>
            </Card>
          )}
        </QueryBoundary>
      </div>
    </RoleGate>
  );
}
