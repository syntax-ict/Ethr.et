"use client";

import { useState } from "react";
import { Clock, Users, ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { StatusBadge } from "@/components/shared/status-badge";
import { EmptyState } from "@/components/shared/empty-state";
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

  const { data, isLoading } = useQuery({
    queryKey: ["attendance", "team", date],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/team", {
        params: { per_page: 50, date },
      });
      return data;
    },
  });

  const records: TeamRecord[] = data?.data ?? [];
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
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <Input
                type="date"
                value={date}
                onChange={(e) => setDate(e.target.value || todayStr())}
                className="w-40 h-8 text-sm"
              />
              <Button
                variant="outline"
                size="icon"
                className="h-8 w-8"
                onClick={() => setDate(shiftDate(date, 1))}
                disabled={isToday}
              >
                <ChevronRight className="h-4 w-4" />
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

        {isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : records.length === 0 ? (
          <EmptyState
            icon={Users}
            title={t("attendance.team_page.empty_title")}
            description={t("attendance.team_page.empty_desc")}
          />
        ) : (
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("attendance.employee")}
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("common.date")}
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("attendance.in")}
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("attendance.out")}
                      </th>
                      <th className="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground sm:table-cell">
                        {t("attendance.source")}
                      </th>
                      <th className="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        {t("common.status")}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {records.map((r) => (
                      <tr
                        key={r.public_id}
                        className="border-b last:border-0 hover:bg-muted/30"
                      >
                        <td className="px-4 py-3 text-sm font-medium text-foreground">
                          {r.employee?.name ?? r.employee_name ?? "—"}
                        </td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">
                          {r.date}
                        </td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">
                          {r.check_in ?? "—"}
                        </td>
                        <td className="px-4 py-3 text-sm text-muted-foreground">
                          {r.check_out ?? "—"}
                        </td>
                        <td className="hidden px-4 py-3 text-sm capitalize text-muted-foreground sm:table-cell">
                          {r.source}
                        </td>
                        <td className="px-4 py-3">
                          <StatusBadge status={r.status} />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </RoleGate>
  );
}
