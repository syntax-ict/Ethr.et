"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { ChartCard, ChartTable } from "@/components/dashboard/chart-card";
import {
  axisProps,
  gridProps,
  lineTooltipProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import { dayKeyLabel } from "@/app/(dashboard)/analytics/axis-format";
import {
  useAuditLogStats,
  type AuditLogFilters,
} from "@/features/audit-log/api";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useT } from "@/lib/i18n/useT";
import { auditActionLabel } from "@/lib/audit/labels";

/**
 * The trail's shape above its rows: entries per day as a line and the
 * busiest actions as sideways bars, over the same filters as the list
 * (charts upgrade, 2026-11-04). `audit_log` carries `(tenant_id,
 * created_at)` and `(action, created_at)` indexes, so the counts are a range
 * scan. Nothing is drawn when the range holds no entries.
 */
export function AuditLogCharts({ filters }: { filters: AuditLogFilters }) {
  const { t, locale } = useT();
  const { calendar } = useCalendar();
  const query = useAuditLogStats(filters);
  const events = t("audit_logs_page.events", "Events");
  const day = (value: unknown) => dayKeyLabel(value, calendar, locale);

  return (
    <QueryBoundary
      query={query}
      loading={
        <div className="grid gap-6 lg:grid-cols-2">
          <Skeleton className="h-64 rounded-xl" />
          <Skeleton className="h-64 rounded-xl" />
        </div>
      }
      isEmpty={(data) => data.by_day.length === 0}
      empty={<></>}
    >
      {(data) => {
        const perDay = t("audit_logs_page.events_per_day", "Events per day");
        const byAction = t(
          "audit_logs_page.events_by_action",
          "Events by action",
        );
        const actions = data.by_action.map((row) => ({
          name: auditActionLabel(row.action, t),
          action: row.action,
          count: row.count,
        }));

        return (
          <div className="grid gap-6 lg:grid-cols-2">
            <ChartCard
              title={perDay}
              description={t(
                "audit_logs_page.charts_range",
                "The last 30 days unless the filters say otherwise, in the organisation's time.",
              )}
            >
              {(view) =>
                view === "table" ? (
                  <ChartTable
                    caption={perDay}
                    columns={[
                      { key: "date", label: t("common.date", "Date") },
                      { key: "count", label: events, numeric: true },
                    ]}
                    rows={data.by_day.map((row) => ({
                      date: day(row.date),
                      count: row.count,
                    }))}
                  />
                ) : (
                  <ResponsiveContainer width="100%" height={240}>
                    <LineChart
                      data={data.by_day}
                      margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                    >
                      <CartesianGrid {...gridProps} />
                      <XAxis
                        dataKey="date"
                        {...axisProps}
                        minTickGap={16}
                        tickFormatter={day}
                      />
                      <YAxis {...axisProps} allowDecimals={false} width={40} />
                      <Tooltip {...lineTooltipProps} labelFormatter={day} />
                      <Line
                        type="monotone"
                        dataKey="count"
                        name={events}
                        stroke={seriesColor(0)}
                        strokeWidth={2.25}
                        dot={{ r: 3, strokeWidth: 0, fill: seriesColor(0) }}
                        activeDot={{ r: 5, strokeWidth: 0 }}
                      />
                    </LineChart>
                  </ResponsiveContainer>
                )
              }
            </ChartCard>

            {actions.length > 0 && (
              <ChartCard title={byAction}>
                {(view) =>
                  view === "table" ? (
                    <ChartTable
                      caption={byAction}
                      columns={[
                        {
                          key: "name",
                          label: t("audit_logs_page.action"),
                        },
                        { key: "count", label: events, numeric: true },
                      ]}
                      rows={actions.map((row) => ({
                        name: row.name,
                        count: row.count,
                      }))}
                    />
                  ) : (
                    <ResponsiveContainer
                      width="100%"
                      height={Math.max(240, actions.length * 30)}
                    >
                      <BarChart
                        data={actions}
                        layout="vertical"
                        margin={{ top: 0, right: 16, left: 0, bottom: 0 }}
                        barCategoryGap={6}
                      >
                        <CartesianGrid
                          {...gridProps}
                          horizontal={false}
                          vertical
                        />
                        <XAxis
                          type="number"
                          {...axisProps}
                          allowDecimals={false}
                        />
                        <YAxis
                          type="category"
                          dataKey="name"
                          {...axisProps}
                          width={128}
                        />
                        <Tooltip {...tooltipProps} />
                        <Bar
                          dataKey="count"
                          name={events}
                          fill={seriesColor(1)}
                          radius={[0, 4, 4, 0]}
                          maxBarSize={20}
                        />
                      </BarChart>
                    </ResponsiveContainer>
                  )
                }
              </ChartCard>
            )}
          </div>
        );
      }}
    </QueryBoundary>
  );
}
