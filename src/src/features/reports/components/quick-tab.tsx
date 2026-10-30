"use client";

import { useState } from "react";
import {
  reportFieldLabel,
  reportSourceLabel,
} from "@/features/reports/field-labels";
import {
  Download,
  Loader2,
  Users,
  Clock,
  CalendarDays,
  CalendarCheck,
  Wallet,
  UserX,
  Play,
  Sparkles,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  useGenerateReport,
  useReportPresets,
  type ReportConfig,
  type ReportPreset,
  type ReportSourceKey,
} from "@/features/reports/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { buildCsv, centsAsAmount, saveCsv } from "@/lib/utils/csv-export";
import { apiErrorMessage } from "@/lib/api/error-message";
import { ErrorState } from "@/components/shared/error-state";
import { PreviewResult } from "@/features/reports/components/preview-result";
import {
  ReportMonthPicker,
  currentReportMonth,
  reportMonthRange,
  type ReportMonth,
} from "@/features/reports/components/report-month-picker";
import { useCalendar } from "@/lib/calendar/calendar-context";

type T = ReturnType<typeof useT>["t"];

const SOURCE_ICON: Record<ReportSourceKey, typeof Users> = {
  employees: Users,
  attendance: Clock,
  timesheet: CalendarCheck,
  absences: UserX,
  leave: CalendarDays,
  payroll: Wallet,
};

const SOURCE_COLOR: Record<ReportSourceKey, string> = {
  employees: "text-status-info",
  attendance: "text-status-success",
  timesheet: "text-status-success",
  absences: "text-status-error",
  leave: "text-interactive-primary",
  payroll: "text-status-warning",
};

/**
 * Title and description per `ReportPresets` key. Literal `t()` calls so the
 * i18n gate can check both locales; a preset the server adds before this map
 * knows it falls back to its source's name.
 */
const PRESET_TEXT: Record<string, (t: T) => { title: string; desc: string }> = {
  monthly_timesheet: (t) => ({
    title: t(
      "reports_page.presets.monthly_timesheet.title",
      "Monthly timesheet",
    ),
    desc: t(
      "reports_page.presets.monthly_timesheet.desc",
      "Workdays, days present, late, absent and on leave, with hours worked, for each employee.",
    ),
  }),
  absences: (t) => ({
    title: t("reports_page.presets.absences.title", "Absences"),
    desc: t(
      "reports_page.presets.absences.desc",
      "Every workday missed with no attendance, leave or holiday on record.",
    ),
  }),
  lateness_by_department: (t) => ({
    title: t(
      "reports_page.presets.lateness_by_department.title",
      "Lateness by department",
    ),
    desc: t(
      "reports_page.presets.lateness_by_department.desc",
      "Late arrivals, counted per department.",
    ),
  }),
  headcount_by_department: (t) => ({
    title: t(
      "reports_page.presets.headcount_by_department.title",
      "Headcount by department",
    ),
    desc: t(
      "reports_page.presets.headcount_by_department.desc",
      "Everyone on record, counted per department.",
    ),
  }),
  leave_balances: (t) => ({
    title: t("reports_page.presets.leave_balances.title", "Leave balances"),
    desc: t(
      "reports_page.presets.leave_balances.desc",
      "Entitled, used and remaining days for each leave type this year.",
    ),
  }),
  payroll_by_period: (t) => ({
    title: t(
      "reports_page.presets.payroll_by_period.title",
      "Payroll by month",
    ),
    desc: t(
      "reports_page.presets.payroll_by_period.desc",
      "Payslips with monthly totals of gross pay, tax and pension for remittance.",
    ),
  }),
  staffing_by_grade: (t) => ({
    title: t(
      "reports_page.presets.staffing_by_grade.title",
      "Staffing by grade",
    ),
    desc: t(
      "reports_page.presets.staffing_by_grade.desc",
      "Staff counted per grade, as a civil service establishment return asks.",
    ),
  }),
  staffing_by_position: (t) => ({
    title: t(
      "reports_page.presets.staffing_by_position.title",
      "Staffing by position",
    ),
    desc: t(
      "reports_page.presets.staffing_by_position.desc",
      "Who holds each position, with grade and department.",
    ),
  }),
  overtime_by_department: (t) => ({
    title: t(
      "reports_page.presets.overtime_by_department.title",
      "Overtime by department",
    ),
    desc: t(
      "reports_page.presets.overtime_by_department.desc",
      "Hours worked and overtime for the month, totalled per department.",
    ),
  }),
  attendance_by_branch: (t) => ({
    title: t(
      "reports_page.presets.attendance_by_branch.title",
      "Attendance by branch",
    ),
    desc: t(
      "reports_page.presets.attendance_by_branch.desc",
      "Days present, late and absent for the month, totalled per branch or site.",
    ),
  }),
  payroll_by_cost_center: (t) => ({
    title: t(
      "reports_page.presets.payroll_by_cost_center.title",
      "Payroll by cost centre",
    ),
    desc: t(
      "reports_page.presets.payroll_by_cost_center.desc",
      "Staff cost per cost centre or project, for donor and budget reporting.",
    ),
  }),
  payroll_by_branch: (t) => ({
    title: t(
      "reports_page.presets.payroll_by_branch.title",
      "Payroll by branch",
    ),
    desc: t(
      "reports_page.presets.payroll_by_branch.desc",
      "Gross pay, tax and net pay totalled per branch.",
    ),
  }),
};

function presetText(t: T, preset: ReportPreset) {
  return (
    PRESET_TEXT[preset.key]?.(t) ?? {
      title: reportSourceLabel(t, preset.source),
      desc: "",
    }
  );
}

/**
 * The preset's config for the chosen month: its first and last day, which a
 * month in either calendar has. Employees and leave have no period.
 */
function forMonth(config: ReportConfig, month: ReportMonth): ReportConfig {
  switch (config.source) {
    case "timesheet":
    case "attendance":
    case "absences":
    case "payroll":
      return {
        ...config,
        filters: { ...config.filters, ...reportMonthRange(month) },
      };
    default:
      return config;
  }
}

export function QuickTab() {
  const { t } = useT();
  const presets = useReportPresets();
  const generate = useGenerateReport();
  const { calendar } = useCalendar();
  const [picked, setPicked] = useState<ReportMonth>(() =>
    currentReportMonth(calendar),
  );
  // Switching calendar starts again from this month in the new calendar: a
  // Gregorian month number means nothing as an Ethiopian one.
  const month =
    picked.calendar === calendar ? picked : currentReportMonth(calendar);
  const [running, setRunning] = useState<string | null>(null);

  function handleGenerate(preset: ReportPreset) {
    setRunning(preset.key);
    generate.mutate(forMonth(preset.config, month), {
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
    });
  }

  function downloadCsv() {
    const result = generate.data;
    if (!result?.data?.length) return;
    saveCsv(
      `${running ?? result.source}-${new Date().toISOString().split("T")[0]}.csv`,
      // Columns read as on screen, in the reader's language, and money as
      // birr: the file had API field names and raw cents.
      buildCsv(result.data, {
        label: (key) => reportFieldLabel(t, key),
        value: centsAsAmount,
      }),
    );
  }

  if (presets.isError) {
    return (
      <ErrorState
        title={t("reports_page.presets_failed", "Reports could not be loaded")}
        onRetry={() => presets.refetch()}
      />
    );
  }

  const list = presets.data?.presets ?? [];
  const sections = [
    {
      key: "recommended",
      title: t(
        "reports_page.recommended_title",
        "Recommended for your organisation",
      ),
      hint: t(
        "reports_page.recommended_hint",
        "Chosen from the organisation type and working pattern set during setup.",
      ),
      items: list.filter((p) => p.recommended),
    },
    {
      key: "standard",
      title: t("reports_page.standard_title", "Standard reports"),
      hint: "",
      items: list.filter((p) => p.standard),
    },
    {
      key: "more",
      title: t("reports_page.more_title", "More reports"),
      hint: "",
      items: list.filter((p) => !p.recommended && !p.standard),
    },
  ].filter((section) => section.items.length > 0);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end gap-3">
        <div className="space-y-1">
          <p className="text-sm font-medium">
            {t("reports_page.fields.month", "Month")}
          </p>
          <ReportMonthPicker value={month} onChange={setPicked} />
        </div>
        <p className="pb-2 text-xs text-muted-foreground">
          {t(
            "reports_page.month_hint",
            "Applies to the timesheet, attendance, absence and payroll reports.",
          )}
        </p>
      </div>

      {presets.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-40 rounded-xl" />
          ))}
        </div>
      ) : (
        sections.map((section) => (
          <section key={section.key} className="space-y-3">
            <div>
              <h2 className="flex items-center gap-2 text-sm font-semibold">
                {section.key === "recommended" && (
                  <Sparkles className="h-4 w-4 text-interactive-primary" />
                )}
                {section.title}
              </h2>
              {section.hint && (
                <p className="text-xs text-muted-foreground">{section.hint}</p>
              )}
            </div>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {section.items.map((preset) => {
                const Icon =
                  SOURCE_ICON[preset.source as ReportSourceKey] ?? Users;
                const text = presetText(t, preset);
                const isRunning = generate.isPending && running === preset.key;
                return (
                  <Card
                    key={preset.key}
                    className="hover:shadow-md transition-shadow"
                  >
                    <CardHeader className="pb-3">
                      <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10">
                          <Icon
                            className={cn(
                              "h-5 w-5",
                              SOURCE_COLOR[preset.source as ReportSourceKey],
                            )}
                          />
                        </div>
                        <CardTitle className="text-base">
                          {text.title}
                        </CardTitle>
                      </div>
                    </CardHeader>
                    <CardContent>
                      <p className="mb-4 text-sm text-muted-foreground">
                        {text.desc}
                      </p>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => handleGenerate(preset)}
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
          </section>
        ))
      )}

      {generate.data && (
        <>
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">
                {t("reports_page.records_count", ":count records", {
                  count: generate.data.total,
                })}
                {running && PRESET_TEXT[running] && (
                  <> · {PRESET_TEXT[running](t).title}</>
                )}
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
