"use client";

import { useMemo } from "react";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { calendarDayParts } from "@/lib/utils/date";
import {
  formatReportCell,
  reportFieldLabel,
  reportCutNote,
  reportGroupLabel,
  reportSourceLabel,
  reportValueLabel,
} from "@/features/reports/field-labels";
import { FileSpreadsheet } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { type ReportConfig, type ReportResult } from "@/features/reports/api";
import { useT } from "@/lib/i18n/useT";

/**
 * Rows past this many are not drawn on screen, where the table scrolls and the
 * note below says so; they are in the markup so that paper carries every row
 * that was loaded.
 */
const SCREEN_ROWS = 200;

type T = ReturnType<typeof useT>["t"];

/**
 * The period a report covers, in the reader's calendar, or null when its
 * settings name none (headcount has no period). `from` and `to` are the
 * date filters; leave balances filter on a `year`.
 */
function reportPeriod(
  t: T,
  filters: ReportConfig["filters"],
  formatDate: (iso: string) => string,
): string | null {
  const from = filters?.from ? formatDate(filters.from) : null;
  const to = filters?.to ? formatDate(filters.to) : null;
  if (from && to)
    return t("reports_page.period_range", undefined, { from, to });
  if (from) return t("reports_page.period_from", undefined, { from });
  if (to) return t("reports_page.period_to", undefined, { to });
  if (filters?.year) {
    return t("reports_page.period_year", undefined, { year: filters.year });
  }
  return null;
}

export function PreviewResult({
  result,
  title,
  config,
}: {
  result: ReportResult;
  /** The report's name when it is not its source's (a ready-made report). */
  title?: string;
  /** The settings that produced `result`, for the period on a printed sheet. */
  config?: ReportConfig;
}) {
  const { t, locale } = useT();
  const { formatDate: formatCalendarDate } = useCalendar();
  // Built from parts: `new Date(iso)` is UTC midnight, the previous day west
  // of UTC.
  const showDate = (iso: string) => {
    const [y, m, d] = iso.split("-").map(Number);
    return formatCalendarDate(new Date(y, m - 1, d), locale);
  };
  const { timeZone, formatTime, formatDateTime, formatDate } =
    useDateFormatters();
  const name = title ?? reportSourceLabel(t, result.source);
  // The dates the server applied, else what the settings say.
  const period = reportPeriod(
    t,
    result.period
      ? { from: result.period.from, to: result.period.to }
      : config?.filters,
    formatDate,
  );
  const cutNote = reportCutNote(t, result);
  // A check-in in the reader's clock, with its date only when it is not the
  // row's own day: a check-out after midnight says which day it fell on.
  const showInstant = (iso: string, day: unknown) => {
    const parts = calendarDayParts(iso, timeZone);
    const local = parts
      ? `${parts.year}-${String(parts.month).padStart(2, "0")}-${String(parts.day).padStart(2, "0")}`
      : null;
    return local !== null && local === day
      ? formatTime(iso)
      : formatDateTime(iso);
  };
  const headers = useMemo(
    () => (result.data.length > 0 ? Object.keys(result.data[0]) : []),
    [result.data],
  );
  const groups = result.summary?.groups;
  const groupSums = result.summary?.group_sums;
  const groupedBy =
    typeof result.summary?.grouped_by === "string"
      ? result.summary.grouped_by
      : undefined;

  return (
    <div className="space-y-4">
      {/* What a printed sheet is: the page header carries only "Reports". */}
      <div data-testid="report-print-heading" className="hidden print:block">
        <h2 className="text-base font-semibold">{name}</h2>
        {period && <p className="text-sm">{period}</p>}
      </div>

      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-muted-foreground">
            <span>{name}</span> ·{" "}
            {t("reports_page.records_count", ":count records", {
              count: result.total,
            })}
          </p>
          {cutNote && (
            <p role="note" className="mt-1 text-xs text-status-warning">
              {cutNote}
            </p>
          )}
          {result.summary?.complete === false && (
            <p role="note" className="mt-1 text-xs text-status-warning">
              {t(
                "reports_page.totals_partial",
                "The group totals are partial: this report is too large to total in one go. Narrow the dates or filters.",
              )}
            </p>
          )}
        </div>
      </div>

      {groups && Object.keys(groups).length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-sm">
              {t("reports_page.grouped_by_field", "Grouped by :field", {
                field: reportFieldLabel(t, String(result.summary.grouped_by)),
              })}
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex flex-wrap gap-2">
              {Object.entries(groups).map(([key, count]) => (
                <Badge key={key} variant="outline" className="text-xs">
                  {reportGroupLabel(t, key, groupedBy)}:{" "}
                  <span className="ml-1 font-semibold">{count}</span>
                </Badge>
              ))}
            </div>
            {groupSums && Object.keys(groupSums).length > 0 && (
              <div className="mt-3 space-y-2 border-t pt-3">
                <p className="text-xs text-muted-foreground">
                  {t("reports_page.group_totals", "Totals per group:")}
                </p>
                {Object.entries(groupSums).map(([key, sums]) => (
                  <div key={key} className="text-xs">
                    <span className="font-semibold text-foreground">
                      {reportGroupLabel(t, key, groupedBy)}:
                    </span>{" "}
                    {Object.entries(sums)
                      .map(
                        // Money in birr; day counts and hours as numbers.
                        ([field, total]) =>
                          `${reportFieldLabel(t, field)} ${formatReportCell(field, total)}`,
                      )
                      .join(" · ")}
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {result.data.length === 0 ? (
        <EmptyState
          icon={FileSpreadsheet}
          title={t("reports_page.no_data")}
          description={t("reports_page.no_rows")}
        />
      ) : (
        <Card>
          {/* print-expand: the table's own height limit and scroll end on paper. */}
          <CardContent className="print-expand p-0">
            <SimpleTable
              caption={t("reports_page.title", "Report results")}
              maxHeight="calc(100vh - 280px)"
              headers={headers.map((col) => (
                <span key={col}>{reportFieldLabel(t, col)}</span>
              ))}
              rows={result.data.map((row, i) => ({
                key: String(i),
                className: i >= SCREEN_ROWS ? "hidden print:table-row" : "",
                cells: headers.map((h) => (
                  <span key={h} className="whitespace-nowrap">
                    {formatReportCell(
                      h,
                      reportValueLabel(t, h, row[h]),
                      showDate,
                      (iso) => showInstant(iso, row.date),
                    )}
                  </span>
                )),
              }))}
            />
            {result.data.length > SCREEN_ROWS && (
              <div className="border-t bg-muted/30 px-4 py-2 text-xs text-muted-foreground print:hidden">
                {t(
                  "reports_page.showing_first_rows",
                  "Showing the first :shown of :total rows. Download CSV for full data.",
                  { shown: SCREEN_ROWS, total: result.data.length },
                )}
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
