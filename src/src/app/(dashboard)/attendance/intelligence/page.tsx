"use client";

import { useState } from "react";
import { Activity, Clock, LogOut, AlertCircle, Calendar } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { PageHeader } from "@/components/shared/page-header";
import { EmptyState } from "@/components/shared/empty-state";
import { RoleGate } from "@/components/shared/role-gate";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";

interface IntelligenceResponse {
  date: string;
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
  const [date, setDate] = useState(new Date().toISOString().split("T")[0]);

  const { data, isLoading } = useQuery<IntelligenceResponse>({
    queryKey: ["attendance", "intelligence", date],
    queryFn: async () => {
      const { data } = await apiClient.get("/attendance/intelligence", {
        params: { date },
      });
      return data;
    },
  });

  return (
    <RoleGate minRole="hr_admin">
      <div className="space-y-6">
        <PageHeader
          title="Attendance Intelligence"
          description="Late arrivals, early departures, and missing punches"
          actions={
            <div className="flex items-center gap-2">
              <Label className="text-xs">Date:</Label>
              <Input
                type="date"
                value={date}
                onChange={(e) => setDate(e.target.value)}
                className="w-44"
              />
            </div>
          }
        />

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-32" />
            ))}
          </div>
        ) : (
          <>
            <div className="grid gap-4 sm:grid-cols-3">
              <Kpi
                icon={Clock}
                title="Late Arrivals"
                value={data?.late_arrivals?.count ?? 0}
                color="amber"
              />
              <Kpi
                icon={LogOut}
                title="Early Departures"
                value={data?.early_departures?.count ?? 0}
                color="orange"
              />
              <Kpi
                icon={AlertCircle}
                title="Missing Punches"
                value={data?.missing_punches?.count ?? 0}
                color="red"
              />
            </div>

            <Card>
              <CardHeader>
                <CardTitle className="text-base flex items-center gap-2">
                  <Clock className="h-4 w-4 text-amber-600" /> Late Arrivals
                </CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                {(data?.late_arrivals?.records ?? []).length === 0 ? (
                  <EmptyState
                    icon={Activity}
                    title="No late arrivals"
                    description="Everyone was on time"
                  />
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead className="border-b bg-muted/50">
                        <tr>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">
                            Employee
                          </th>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">
                            Check-in
                          </th>
                          <th className="px-4 py-2 text-left text-xs font-medium uppercase text-muted-foreground">
                            Shift Start
                          </th>
                          <th className="px-4 py-2 text-right text-xs font-medium uppercase text-muted-foreground">
                            Late By
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {data!.late_arrivals.records.map((r, i) => (
                          <tr
                            key={i}
                            className="border-b last:border-0 hover:bg-muted/30"
                          >
                            <td className="px-4 py-2 font-medium">
                              {r.employee_name}
                            </td>
                            <td className="px-4 py-2 text-muted-foreground">
                              {r.check_in
                                ? new Date(r.check_in).toLocaleTimeString()
                                : "—"}
                            </td>
                            <td className="px-4 py-2 text-muted-foreground">
                              {r.shift_start ?? "—"}
                            </td>
                            <td className="px-4 py-2 text-right">
                              <Badge
                                variant="outline"
                                className="bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-300 border-0"
                              >
                                +{r.minutes_late} min
                              </Badge>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </CardContent>
            </Card>

            <div className="grid gap-6 lg:grid-cols-2">
              <Card>
                <CardHeader>
                  <CardTitle className="text-base flex items-center gap-2">
                    <LogOut className="h-4 w-4 text-orange-600" /> Early
                    Departures
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  {(data?.early_departures?.records ?? []).length === 0 ? (
                    <p className="text-sm text-muted-foreground py-4 text-center">
                      No early departures
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
                    <AlertCircle className="h-4 w-4 text-red-600" /> Missing
                    Punches
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  {(data?.missing_punches?.records ?? []).length === 0 ? (
                    <p className="text-sm text-muted-foreground py-4 text-center">
                      No missing punches
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
      </div>
    </RoleGate>
  );
}

const colorClass: Record<string, string> = {
  amber: "bg-amber-100 text-amber-600 dark:bg-amber-950 dark:text-amber-400",
  orange:
    "bg-orange-100 text-orange-600 dark:bg-orange-950 dark:text-orange-400",
  red: "bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400",
};

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
