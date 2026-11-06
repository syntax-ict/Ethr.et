"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { ChartCard, ChartTable } from "@/components/dashboard/chart-card";
import {
  ChartLegend,
  axisProps,
  gridProps,
  lineTooltipProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import {
  leaveTypeName,
  useMyApprovedLeave,
  type LeaveBalance,
  type LeaveRequest,
} from "@/features/leave/api";
import {
  monthLabel,
  monthOf,
  todayIn,
  type CalendarMonth,
} from "@/app/(dashboard)/analytics/period";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useT } from "@/lib/i18n/useT";
import type { CalendarSystem } from "@/lib/calendar/calendar-context";
import { addDaysIso } from "@/features/shifts/dates";

/** One decimal: 2.5 days, not 2.4999. */
function tenth(value: number): number {
  return Math.round(value * 10) / 10;
}

function monthKey(m: CalendarMonth): string {
  return `${m.year}-${String(m.month).padStart(2, "0")}`;
}

/**
 * Approved leave as days per month of the reader's calendar, over the last
 * twelve months ending in the month holding `today`. A request's days are
 * spread evenly over the calendar days it covers, so a request straddling
 * two months counts in both.
 */
export function leaveDaysPerMonth(
  requests: LeaveRequest[],
  calendar: CalendarSystem,
  today: string,
): { key: string; month: CalendarMonth; days: number }[] {
  const months: { key: string; month: CalendarMonth; days: number }[] = [];
  const index = new Map<string, number>();
  let cursor = monthOf(today, calendar);
  const back: CalendarMonth[] = [];
  for (let i = 0; i < 12; i++) {
    back.unshift(cursor);
    cursor = previousMonth(cursor, calendar);
  }
  for (const month of back) {
    index.set(monthKey(month), months.length);
    months.push({ key: monthKey(month), month, days: 0 });
  }

  for (const request of requests) {
    if (!request.start_date || !request.end_date || request.days <= 0) continue;
    const span: string[] = [];
    for (
      let day = request.start_date;
      day <= request.end_date && span.length < 400;
      day = addDaysIso(day, 1)
    ) {
      span.push(day);
    }
    if (span.length === 0) continue;
    const perDay = request.days / span.length;
    for (const day of span) {
      const at = index.get(monthKey(monthOf(day, calendar)));
      if (at !== undefined) months[at].days += perDay;
    }
  }

  return months.map((m) => ({ ...m, days: tenth(m.days) }));
}

function previousMonth(m: CalendarMonth, calendar: CalendarSystem) {
  const count = calendar === "ethiopian" ? 13 : 12;
  return m.month === 1
    ? { year: m.year - 1, month: count }
    : { year: m.year, month: m.month - 1 };
}

/**
 * The reader's own leave as pictures (charts upgrade, 2026-11-04): days
 * taken by type as sideways bars, used against remaining as a donut, and
 * leave days per month as a line. Each card hides itself when it has
 * nothing to show.
 */
export function LeaveCharts({ balances }: { balances: LeaveBalance[] }) {
  const { t, locale } = useT();
  const { calendar, timeZone } = useDateFormatters();
  const approved = useMyApprovedLeave();
  const daysUnit = t("common.days", "days");

  const byType = balances
    .map((b) => ({ name: leaveTypeName(b.leave_type), days: b.used_days }))
    .filter((row) => row.days > 0)
    .sort((a, b) => b.days - a.days);
  const used = tenth(balances.reduce((sum, b) => sum + b.used_days, 0));
  const remaining = tenth(
    balances.reduce((sum, b) => sum + Math.max(0, b.remaining_days), 0),
  );
  const perMonth = leaveDaysPerMonth(
    approved.data ?? [],
    calendar,
    todayIn(timeZone),
  );
  const anyMonth = perMonth.some((m) => m.days > 0);

  if (byType.length === 0 && used + remaining === 0 && !anyMonth) return null;

  const byTypeTitle = t("leave_page.days_by_type", "Days taken by type");
  const donutTitle = t("leave_page.used_vs_remaining", "Used and remaining");
  const perMonthTitle = t("leave_page.days_per_month", "Leave days per month");
  const usedLabel = t("leave_page.used", "Used");
  const remainingLabel = t("leave_page.remaining", "Remaining");
  const leaveDays = t("leave_page.leave_days", "Leave days");
  const monthName = (m: CalendarMonth) => monthLabel(m, calendar, locale);
  const shortMonth = (key: string) => {
    const m = perMonth.find((row) => row.key === key);
    return m ? monthName(m.month).split(" ")[0] : key;
  };

  return (
    <div className="grid gap-6 lg:grid-cols-2">
      {byType.length > 0 && (
        <ChartCard title={byTypeTitle}>
          {(view) =>
            view === "table" ? (
              <ChartTable
                caption={byTypeTitle}
                columns={[
                  {
                    key: "name",
                    label: t("leave_page.leave_type", "Leave type"),
                  },
                  {
                    key: "days",
                    label: t("leave_page.days_taken", "Days taken"),
                    numeric: true,
                  },
                ]}
                rows={byType}
              />
            ) : (
              <ResponsiveContainer
                width="100%"
                height={Math.max(200, byType.length * 36)}
              >
                <BarChart
                  data={byType}
                  layout="vertical"
                  margin={{ top: 0, right: 16, left: 0, bottom: 0 }}
                  barCategoryGap={8}
                >
                  <CartesianGrid {...gridProps} horizontal={false} vertical />
                  <XAxis type="number" {...axisProps} />
                  <YAxis
                    type="category"
                    dataKey="name"
                    {...axisProps}
                    width={120}
                  />
                  <Tooltip {...tooltipProps} />
                  <Bar
                    dataKey="days"
                    name={t("leave_page.days_taken", "Days taken")}
                    fill={seriesColor(1)}
                    radius={[0, 6, 6, 0]}
                    maxBarSize={22}
                  />
                </BarChart>
              </ResponsiveContainer>
            )
          }
        </ChartCard>
      )}

      {used + remaining > 0 && (
        <ChartCard title={donutTitle}>
          {(view) =>
            view === "table" ? (
              <ChartTable
                caption={donutTitle}
                columns={[
                  { key: "name", label: donutTitle },
                  { key: "days", label: daysUnit, numeric: true },
                ]}
                rows={[
                  { name: usedLabel, days: used },
                  { name: remainingLabel, days: remaining },
                ]}
              />
            ) : (
              <div className="flex flex-col items-center gap-6 sm:flex-row">
                <div className="relative h-52 w-52 shrink-0">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={[
                          { name: usedLabel, value: used },
                          { name: remainingLabel, value: remaining },
                        ]}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={62}
                        outerRadius={90}
                        paddingAngle={2}
                        strokeWidth={0}
                      >
                        <Cell fill={seriesColor(1)} />
                        <Cell
                          fill={`color-mix(in srgb, ${seriesColor(1)} 30%, transparent)`}
                        />
                      </Pie>
                      <Tooltip {...tooltipProps} />
                    </PieChart>
                  </ResponsiveContainer>
                  <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-2xl font-semibold tabular-nums text-foreground">
                      {remaining}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {remainingLabel}
                    </span>
                  </div>
                </div>
                <ChartLegend
                  stacked
                  items={[
                    { label: usedLabel, color: seriesColor(1), value: used },
                    {
                      label: remainingLabel,
                      color: `color-mix(in srgb, ${seriesColor(1)} 30%, transparent)`,
                      value: remaining,
                    },
                  ]}
                />
              </div>
            )
          }
        </ChartCard>
      )}

      {anyMonth && (
        <ChartCard
          className="lg:col-span-2"
          title={perMonthTitle}
          description={t(
            "leave_page.days_per_month_desc",
            "Approved leave over the last twelve months.",
          )}
        >
          {(view) =>
            view === "table" ? (
              <ChartTable
                caption={perMonthTitle}
                columns={[
                  { key: "month", label: t("common.month", "Month") },
                  { key: "days", label: leaveDays, numeric: true },
                ]}
                rows={perMonth.map((m) => ({
                  month: monthName(m.month),
                  days: m.days,
                }))}
              />
            ) : (
              <ResponsiveContainer width="100%" height={240}>
                <LineChart
                  data={perMonth}
                  margin={{ top: 8, right: 8, left: 0, bottom: 0 }}
                >
                  <CartesianGrid {...gridProps} />
                  <XAxis
                    dataKey="key"
                    {...axisProps}
                    tickFormatter={shortMonth}
                  />
                  <YAxis {...axisProps} width={36} />
                  <Tooltip
                    {...lineTooltipProps}
                    labelFormatter={(key) => {
                      const m = perMonth.find((row) => row.key === key);
                      return m ? monthName(m.month) : String(key);
                    }}
                  />
                  <Line
                    type="monotone"
                    dataKey="days"
                    name={leaveDays}
                    stroke={seriesColor(1)}
                    strokeWidth={2.25}
                    dot={{ r: 3, strokeWidth: 0, fill: seriesColor(1) }}
                    activeDot={{ r: 5, strokeWidth: 0 }}
                  />
                </LineChart>
              </ResponsiveContainer>
            )
          }
        </ChartCard>
      )}
    </div>
  );
}
