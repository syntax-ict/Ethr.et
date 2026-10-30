"use client";

import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { EmptyState } from "@/components/shared/empty-state";
import { CalendarX } from "lucide-react";
import { useExecutiveAttendance } from "@/features/dashboard/executive-api";
import { useT } from "@/lib/i18n/useT";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { dayKeyLabel } from "./axis-format";
import type { DateRange } from "./period";
import {
  AreaChart,
  Area,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from "recharts";
import {
  ChartLegend,
  STATUS_SERIES,
  axisProps,
  gridProps,
  lineTooltipProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import { ChartCard } from "@/components/dashboard/chart-card";

export function AttendanceTab({
  branchPublicId,
  range,
}: {
  branchPublicId?: string;
  range: DateRange;
}) {
  const { t, locale } = useT();
  const { calendar } = useCalendar();
  const query = useExecutiveAttendance({ branchPublicId, ...range });

  // Every section below hides itself when it has no rows, so with no
  // attendance in the period the tab rendered an empty grid and nothing else.
  // Empty means all of them are.
  return (
    <QueryBoundary
      query={query}
      loading={
        <div className="space-y-6">
          <Skeleton className="h-80 w-full rounded-xl" />
          <div className="grid gap-6 lg:grid-cols-2">
            <Skeleton className="h-72 rounded-xl" />
            <Skeleton className="h-72 rounded-xl" />
          </div>
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
      {(data) => {
        const sources = data.by_source ?? [];
        const sourceTotal = sources.reduce((sum, s) => sum + s.count, 0);
        const late = (data.top_late ?? []).slice(0, 8);
        const lateMax = Math.max(1, ...late.map((e) => e.late_count));

        return (
          <div className="space-y-6">
            {data.daily_trend && data.daily_trend.length > 0 && (
              <ChartCard title={t("analytics_page.daily_attendance_trend")}>
                <ResponsiveContainer width="100%" height={300}>
                  <AreaChart
                    data={data.daily_trend}
                    margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                  >
                    <defs>
                      <linearGradient
                        id="analytics-present"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                      >
                        <stop
                          offset="0%"
                          stopColor={STATUS_SERIES.present}
                          stopOpacity={0.28}
                        />
                        <stop
                          offset="100%"
                          stopColor={STATUS_SERIES.present}
                          stopOpacity={0}
                        />
                      </linearGradient>
                    </defs>
                    <CartesianGrid {...gridProps} />
                    <XAxis
                      dataKey="date"
                      {...axisProps}
                      minTickGap={16}
                      tickFormatter={(value) =>
                        dayKeyLabel(value, calendar, locale)
                      }
                    />
                    <YAxis {...axisProps} allowDecimals={false} width={44} />
                    <Tooltip
                      {...lineTooltipProps}
                      labelFormatter={(label) =>
                        dayKeyLabel(label, calendar, locale)
                      }
                    />
                    <Area
                      type="monotone"
                      dataKey="present"
                      name={t("dashboard.present", "Present")}
                      stroke={STATUS_SERIES.present}
                      strokeWidth={2}
                      fill="url(#analytics-present)"
                      activeDot={{ r: 4, strokeWidth: 0 }}
                    />
                  </AreaChart>
                </ResponsiveContainer>
              </ChartCard>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
              {sources.length > 0 && (
                <ChartCard title={t("analytics_page.by_source")}>
                  <div className="flex flex-col items-center gap-6 sm:flex-row">
                    <div className="relative h-52 w-52 shrink-0">
                      <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                          <Pie
                            data={sources}
                            dataKey="count"
                            nameKey="source"
                            innerRadius={62}
                            outerRadius={90}
                            paddingAngle={2}
                            strokeWidth={0}
                          >
                            {sources.map((_, i) => (
                              <Cell key={i} fill={seriesColor(i)} />
                            ))}
                          </Pie>
                          <Tooltip {...tooltipProps} />
                        </PieChart>
                      </ResponsiveContainer>
                      <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        <span className="text-2xl font-semibold tabular-nums text-foreground">
                          {sourceTotal}
                        </span>
                        <span className="text-xs text-muted-foreground">
                          {t("analytics_page.punches", "punches")}
                        </span>
                      </div>
                    </div>
                    <ChartLegend
                      stacked
                      items={sources.map((s, i) => ({
                        label: (
                          <span className="capitalize">
                            {t(`attendance.source_${s.source}`, s.source)}
                          </span>
                        ),
                        color: seriesColor(i),
                        value: `${sourceTotal > 0 ? Math.round((s.count / sourceTotal) * 100) : 0}%`,
                      }))}
                    />
                  </div>
                </ChartCard>
              )}

              {late.length > 0 && (
                <ChartCard title={t("analytics_page.top_late_arrivals")}>
                  <ol className="space-y-3">
                    {late.map((e, i) => (
                      <li key={i} className="space-y-1.5">
                        <div className="flex items-center justify-between gap-3 text-sm">
                          <span className="flex min-w-0 items-center gap-2">
                            <span className="w-4 shrink-0 text-xs tabular-nums text-muted-foreground">
                              {i + 1}
                            </span>
                            <span className="truncate text-foreground">
                              {e.employee_name}
                            </span>
                          </span>
                          <span className="shrink-0 font-semibold tabular-nums text-foreground">
                            {e.late_count}×
                          </span>
                        </div>
                        <div className="ml-6 h-1.5 overflow-hidden rounded-full bg-muted">
                          <div
                            className="h-full rounded-full bg-status-warning"
                            style={{
                              width: `${(e.late_count / lateMax) * 100}%`,
                            }}
                          />
                        </div>
                      </li>
                    ))}
                  </ol>
                </ChartCard>
              )}
            </div>
          </div>
        );
      }}
    </QueryBoundary>
  );
}
