"use client";

import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { ChartCard, ChartTable } from "@/components/dashboard/chart-card";
import { Heatmap, HeatmapTable } from "@/components/dashboard/heatmap";
import {
  ChartLegend,
  axisProps,
  gridProps,
  seriesColor,
  tooltipProps,
} from "@/components/dashboard/chart-theme";
import { useShiftCoverage, type ShiftCoverageRow } from "@/features/shifts/api";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { useMediaQuery } from "@/lib/hooks/useMediaQuery";
import { useT } from "@/lib/i18n/useT";
import { localizedName } from "@/lib/i18n/localizedName";

/** Minutes as hours to one decimal: 450 is 7.5. */
function hours(minutes: number): number {
  return Math.round((minutes / 60) * 10) / 10;
}

/**
 * This week's staffing on the Shifts page: who each shift expects on each
 * day, and the hours each shift planned against the hours its people have
 * worked (charts upgrade, 2026-11-04). Neither card is drawn when no shift
 * has anyone scheduled or attending this week.
 */
export function ShiftCoverageCharts() {
  const { t, locale } = useT();
  const { formatWeekday, formatDayMonth } = useDateFormatters();
  // Under 480 px the hour bars are drawn sideways, with the shift names on
  // the left, where upright bars would overlap their labels.
  const narrow = useMediaQuery("(max-width: 479px)");
  const query = useShiftCoverage();

  const shiftName = (row: ShiftCoverageRow) => localizedName(row, locale);
  const hoursLabel = (value: number) =>
    t("shifts_page.hours_unit", ":count h", { count: value });

  return (
    <QueryBoundary
      query={query}
      loading={
        <div className="grid gap-6 lg:grid-cols-2">
          <Skeleton className="h-64 rounded-xl" />
          <Skeleton className="h-64 rounded-xl" />
        </div>
      }
      isEmpty={(data) => data.shifts.length === 0}
      empty={<></>}
    >
      {(data) => {
        const peopleTitle = t("shifts_page.coverage_people", "People by day");
        const hoursTitle = t(
          "shifts_page.coverage_hours",
          "Planned and covered hours",
        );
        const rows = data.shifts.map((row) => ({
          key: row.public_id,
          label: shiftName(row),
        }));
        const columns = data.days.map((day) => ({
          key: day,
          label: formatWeekday(day),
          name: formatDayMonth(day),
        }));
        const scheduled = new Map(
          data.shifts.flatMap((row) =>
            row.by_day.map((d) => [`${row.public_id}|${d.date}`, d.scheduled]),
          ),
        );
        const people = (shift: string, day: string) =>
          scheduled.get(`${shift}|${day}`);
        const sayPeople = (value: number) =>
          t("shifts_page.scheduled_people", ":count people", { count: value });

        const hoursData = data.shifts.map((row) => ({
          name: shiftName(row),
          covered: hours(row.covered_minutes),
          uncovered: hours(
            Math.max(0, row.planned_minutes - row.covered_minutes),
          ),
        }));
        const series = [
          {
            key: "covered",
            label: t("shifts_page.covered_hours", "Covered"),
            color: seriesColor(1),
          },
          {
            key: "uncovered",
            label: t("shifts_page.uncovered_hours", "Not yet covered"),
            color: `color-mix(in srgb, ${seriesColor(1)} 30%, transparent)`,
          },
        ] as const;

        return (
          <div className="grid gap-6 lg:grid-cols-2">
            <ChartCard
              title={peopleTitle}
              description={t(
                "shifts_page.coverage_people_desc",
                "How many people each shift expects on each day this week.",
              )}
            >
              {(view) =>
                view === "table" ? (
                  <HeatmapTable
                    caption={peopleTitle}
                    rowHeading={t("shifts_page.shift", "Shift")}
                    rows={rows}
                    columns={columns}
                    value={people}
                  />
                ) : (
                  <Heatmap
                    rows={rows}
                    columns={columns}
                    value={people}
                    format={sayPeople}
                    color={seriesColor(0)}
                  />
                )
              }
            </ChartCard>

            <ChartCard
              title={hoursTitle}
              description={t(
                "shifts_page.coverage_hours_desc",
                "Hours each shift planned this week, and the hours its people have worked.",
              )}
            >
              {(view) =>
                view === "table" ? (
                  <ChartTable
                    caption={hoursTitle}
                    columns={[
                      { key: "name", label: t("shifts_page.shift", "Shift") },
                      {
                        key: "planned",
                        label: t("shifts_page.planned_hours", "Planned"),
                        numeric: true,
                      },
                      {
                        key: "covered",
                        label: series[0].label,
                        numeric: true,
                      },
                      {
                        key: "uncovered",
                        label: series[1].label,
                        numeric: true,
                      },
                    ]}
                    rows={data.shifts.map((row) => ({
                      name: shiftName(row),
                      planned: hoursLabel(hours(row.planned_minutes)),
                      covered: hoursLabel(hours(row.covered_minutes)),
                      uncovered: hoursLabel(
                        hours(
                          Math.max(
                            0,
                            row.planned_minutes - row.covered_minutes,
                          ),
                        ),
                      ),
                    }))}
                  />
                ) : (
                  <div className="space-y-3">
                    <ChartLegend
                      items={series.map((s) => ({
                        label: s.label,
                        color: s.color,
                      }))}
                    />
                    <ResponsiveContainer
                      width="100%"
                      height={
                        narrow ? Math.max(200, hoursData.length * 44) : 240
                      }
                    >
                      <BarChart
                        data={hoursData}
                        layout={narrow ? "vertical" : "horizontal"}
                        margin={
                          narrow
                            ? { top: 0, right: 16, left: 0, bottom: 0 }
                            : { top: 4, right: 8, left: 0, bottom: 0 }
                        }
                        barGap={2}
                      >
                        <CartesianGrid
                          {...gridProps}
                          horizontal={!narrow}
                          vertical={narrow}
                        />
                        {narrow ? (
                          <>
                            <XAxis
                              type="number"
                              {...axisProps}
                              tickFormatter={hoursLabel}
                            />
                            <YAxis
                              type="category"
                              dataKey="name"
                              {...axisProps}
                              width={104}
                            />
                          </>
                        ) : (
                          <>
                            <XAxis dataKey="name" {...axisProps} interval={0} />
                            <YAxis
                              {...axisProps}
                              tickFormatter={hoursLabel}
                              width={48}
                            />
                          </>
                        )}
                        <Tooltip
                          {...tooltipProps}
                          formatter={(value) => hoursLabel(Number(value))}
                        />
                        {series.map((s, index) => (
                          <Bar
                            key={s.key}
                            dataKey={s.key}
                            name={s.label}
                            stackId="hours"
                            fill={s.color}
                            radius={
                              index === series.length - 1
                                ? narrow
                                  ? [0, 4, 4, 0]
                                  : [4, 4, 0, 0]
                                : 0
                            }
                            maxBarSize={narrow ? 22 : 40}
                          />
                        ))}
                      </BarChart>
                    </ResponsiveContainer>
                  </div>
                )
              }
            </ChartCard>
          </div>
        );
      }}
    </QueryBoundary>
  );
}
