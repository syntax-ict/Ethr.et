"use client";

import { useMemo } from "react";
import { useCalendar } from "@/lib/calendar/calendar-context";
import {
  formatReportCell,
  reportFieldLabel,
} from "@/features/reports/field-labels";
import { FileSpreadsheet } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { EmptyState } from "@/components/shared/empty-state";
import { SimpleTable } from "@/components/shared/simple-table";
import { type ReportResult } from "@/features/reports/api";
import { useT } from "@/lib/i18n/useT";
import { formatETB } from "@/lib/utils/currency";

export function PreviewResult({ result }: { result: ReportResult }) {
  const { t, locale } = useT();
  const { formatDate: formatCalendarDate } = useCalendar();
  // Built from parts: `new Date(iso)` is UTC midnight, the previous day west
  // of UTC.
  const showDate = (iso: string) => {
    const [y, m, d] = iso.split("-").map(Number);
    return formatCalendarDate(new Date(y, m - 1, d), locale);
  };
  const headers = useMemo(
    () => (result.data.length > 0 ? Object.keys(result.data[0]) : []),
    [result.data],
  );
  const groups = result.summary?.groups;
  const groupSums = result.summary?.group_sums;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-muted-foreground">
            <span className="capitalize">{result.source}</span> ·{" "}
            {t("reports_page.records_count", ":count records", {
              count: result.total,
            })}
          </p>
          {result.truncated && (
            <p role="note" className="mt-1 text-xs text-status-warning">
              {t(
                "reports_page.truncated",
                "Only the newest 1,000 rows are shown. Narrow the dates to see the rest.",
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
                  {key}: <span className="ml-1 font-semibold">{count}</span>
                </Badge>
              ))}
            </div>
            {groupSums && Object.keys(groupSums).length > 0 && (
              <div className="mt-3 space-y-2 border-t pt-3">
                <p className="text-xs text-muted-foreground">
                  {t(
                    "reports_page.group_totals",
                    "Totals per group, for statutory filing (e.g. monthly tax and pension remittance):",
                  )}
                </p>
                {Object.entries(groupSums).map(([key, sums]) => (
                  <div key={key} className="text-xs">
                    <span className="font-semibold text-foreground">
                      {key}:
                    </span>{" "}
                    {Object.entries(sums)
                      .map(
                        ([field, cents]) =>
                          `${field.replace(/_cents$/, "").replace(/_/g, " ")} ${formatETB(cents)}`,
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
          <CardContent className="p-0">
            <SimpleTable
              caption={t("reports_page.title", "Report results")}
              maxHeight="calc(100vh - 280px)"
              headers={headers.map((col) => (
                <span key={col}>{reportFieldLabel(t, col)}</span>
              ))}
              rows={result.data.slice(0, 200).map((row, i) => ({
                key: String(i),
                cells: headers.map((h) => (
                  <span key={h} className="whitespace-nowrap">
                    {formatReportCell(h, row[h], showDate)}
                  </span>
                )),
              }))}
            />
            {result.data.length > 200 && (
              <div className="border-t bg-muted/30 px-4 py-2 text-xs text-muted-foreground">
                {t("reports_page.showing_first_200_prefix")}{" "}
                {result.data.length}{" "}
                {t("reports_page.showing_first_200_suffix")}
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
