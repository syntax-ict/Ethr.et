"use client";

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { EmptyState } from "@/components/shared/empty-state";
import { CalendarX } from "lucide-react";
import { useExecutiveAttendance } from "@/features/dashboard/executive-api";
import { useT } from "@/lib/i18n/useT";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { dayKeyLabel } from "./axis-format";
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

export function AttendanceTab({ branchPublicId }: { branchPublicId?: string }) {
  const { t, locale } = useT();
  const { calendar } = useCalendar();
  const query = useExecutiveAttendance({ branchPublicId });

  // Every section below hides itself when it has no rows, so with no
  // attendance in the period the tab rendered an empty grid and nothing else.
  // Empty means all of them are.
  return (
    <QueryBoundary
      query={query}
      loading={
        <div className="space-y-6">
          <Skeleton className="h-80 w-full" />
        </div>
      }
      isEmpty={(data) =>
        !data.daily_trend?.length &&
        !data.by_source?.length &&
        !data.top_late?.length
      }
      empty={
        <EmptyState
          icon={CalendarX}
          title={t(
            "analytics_page.no_attendance_title",
            "No attendance in this period",
          )}
          description={t(
            "analytics_page.no_attendance_desc",
            "Charts appear here once employees check in.",
          )}
        />
      }
    >
      {(data) => (
        <div className="space-y-6">
          {data.daily_trend && data.daily_trend.length > 0 && (
            <ChartCard title={t("analytics_page.daily_attendance_trend")}>
              <ResponsiveContainer width="100%" height={300}>
                <LineChart data={data.daily_trend}>
                  <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
                  <XAxis
                    dataKey="date"
                    tickFormatter={(value) =>
                      dayKeyLabel(value, calendar, locale)
                    }
                    tick={{ fontSize: 11 }}
                  />
                  <YAxis allowDecimals={false} tick={{ fontSize: 11 }} />
                  <Tooltip
                    labelFormatter={(label) =>
                      dayKeyLabel(label, calendar, locale)
                    }
                  />
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
                      {data.by_source.map((_, i) => (
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
                    {data.top_late.slice(0, 10).map((e, i) => (
                      <div
                        key={i}
                        className="flex items-center justify-between rounded-lg border p-2"
                      >
                        <span className="text-sm">{e.employee_name}</span>
                        <span className="text-sm font-semibold text-destructive">
                          {e.late_count}x
                        </span>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      )}
    </QueryBoundary>
  );
}
