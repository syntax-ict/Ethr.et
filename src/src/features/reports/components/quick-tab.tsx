"use client";

import { reportFieldLabel } from "@/features/reports/field-labels";
import {
  Download,
  Loader2,
  Users,
  Clock,
  CalendarDays,
  Wallet,
  Play,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  useGenerateReport,
  type ReportSourceKey,
} from "@/features/reports/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { buildCsv, centsAsAmount, saveCsv } from "@/lib/utils/csv-export";
import { apiErrorMessage } from "@/lib/api/error-message";
import { PreviewResult } from "@/features/reports/components/preview-result";

export const prebuilt: Array<{
  key: ReportSourceKey;
  icon: typeof Users;
  titleKey: string;
  descKey: string;
  color: string;
}> = [
  {
    key: "employees",
    icon: Users,
    titleKey: "reports_page.prebuilt_employees_title",
    descKey: "reports_page.prebuilt_employees_desc",
    color: "text-status-info",
  },
  {
    key: "attendance",
    icon: Clock,
    titleKey: "reports_page.prebuilt_attendance_title",
    descKey: "reports_page.prebuilt_attendance_desc",
    color: "text-status-success",
  },
  {
    key: "leave",
    icon: CalendarDays,
    titleKey: "reports_page.prebuilt_leave_title",
    descKey: "reports_page.prebuilt_leave_desc",
    color: "text-interactive-primary",
  },
  {
    key: "payroll",
    icon: Wallet,
    titleKey: "reports_page.prebuilt_payroll_title",
    descKey: "reports_page.prebuilt_payroll_desc",
    color: "text-status-warning",
  },
];

export function QuickTab() {
  const { t } = useT();
  const generate = useGenerateReport();

  function handleGenerate(source: ReportSourceKey) {
    generate.mutate(
      { source },
      {
        // The result, not `generate.data`, which still holds the previous
        // run inside onSuccess: the first run said "Generated 0 records" (N67).
        onSuccess: (data) =>
          toast.success(
            t("reports_page.generated_count", "Generated :count records", {
              count: data.total,
            }),
          ),
        onError: (err) =>
          toast.error(apiErrorMessage(err, t("reports_page.generate_failed"))),
      },
    );
  }

  function downloadCsv() {
    const result = generate.data;
    if (!result?.data?.length) return;
    saveCsv(
      `${result.source}-${new Date().toISOString().split("T")[0]}.csv`,
      // Columns read as on screen, in the reader's language, and money as
      // birr: the file had API field names and raw cents.
      buildCsv(result.data, {
        label: (key) => reportFieldLabel(t, key),
        value: centsAsAmount,
      }),
    );
  }

  return (
    <div className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2">
        {prebuilt.map((r) => {
          const Icon = r.icon;
          const isRunning =
            generate.isPending && generate.variables?.source === r.key;
          return (
            <Card key={r.key} className="hover:shadow-md transition-shadow">
              <CardHeader className="pb-3">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                    <Icon className={cn("h-5 w-5", r.color)} />
                  </div>
                  <CardTitle className="text-base">{t(r.titleKey)}</CardTitle>
                </div>
              </CardHeader>
              <CardContent>
                <p className="mb-4 text-sm text-muted-foreground">
                  {t(r.descKey)}
                </p>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => handleGenerate(r.key)}
                  disabled={isRunning}
                >
                  {isRunning ? (
                    <Loader2 className="mr-2 h-3 w-3 animate-spin" />
                  ) : (
                    <Play className="mr-2 h-3 w-3" />
                  )}
                  {t("reports_page.run")}
                </Button>
              </CardContent>
            </Card>
          );
        })}
      </div>

      {generate.data && (
        <>
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">
                {t("reports_page.records_count", ":count records", {
                  count: generate.data.total,
                })}{" "}
                · <span className="capitalize">{generate.data.source}</span>
              </p>
              {generate.data.truncated && (
                <p role="note" className="mt-1 text-xs text-status-warning">
                  {t(
                    "reports_page.truncated",
                    "Only the newest 1,000 rows are shown. Narrow the dates to see the rest.",
                  )}
                </p>
              )}
            </div>
            <Button size="sm" onClick={downloadCsv}>
              <Download className="mr-2 h-3 w-3" />{" "}
              {t("reports_page.download_csv")}
            </Button>
          </div>
          <PreviewResult result={generate.data} />
        </>
      )}
    </div>
  );
}

// ── SAVED TAB ──────────────────────────────────────────────────
