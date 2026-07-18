"use client";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useQuery } from "@tanstack/react-query";
import { apiClient } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import {
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
import { CHART_COLORS, ChartCard } from "./chart-helpers";

export function AttendanceTab() {
  const { t } = useT();
  const { data, isLoading } = useQuery({
    queryKey: ["analytics", "attendance"],
    queryFn: async () => {
      const { data } = await apiClient.get("/dashboard/executive/attendance");
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
                stroke={CHART_COLORS[1]}
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
                    <Cell
                      key={i}
                      fill={CHART_COLORS[i % CHART_COLORS.length]}
                    />
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
                        <span className="text-sm font-semibold text-destructive">
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
