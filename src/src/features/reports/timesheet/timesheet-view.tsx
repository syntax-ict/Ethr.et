"use client";

import { useMemo, useState } from "react";
import { AlertTriangle, Download, FileText, Lock } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { ErrorState } from "@/components/shared/error-state";
import { PageActions } from "@/components/shared/page-actions";
import { PrintAction } from "@/components/shared/print-action";
import { usePrintTitle } from "@/components/layouts/print-title";
import { reportFieldLabel } from "@/features/reports/field-labels";
import {
  ReportMonthPicker,
  reportMonthRange,
  type ReportMonth,
} from "@/features/reports/components/report-month-picker";
import {
  downloadTimesheetPdf,
  monthOf,
  periodOf,
  type TimesheetFilters,
  useTimesheet,
  useTimesheetFilters,
  type Timesheet,
  type TimesheetCell,
  type TimesheetRow,
} from "@/features/reports/timesheet/api";
import {
  TIMESHEET_CODES,
  codeLabel,
  codeTone,
  statusLabel,
  statusTone,
} from "@/features/reports/timesheet/codes";
import { DayDrawer } from "@/features/reports/timesheet/day-drawer";
import { RulesApplied } from "@/features/reports/timesheet/rules-applied";
import { useShifts } from "@/features/shifts/api";
import { branchesApi, departmentsApi } from "@/features/organization/api";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { toEthiopian } from "@/lib/calendar/ethiopian";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import { localizedName } from "@/lib/i18n/localizedName";
import { useT } from "@/lib/i18n/useT";
import { buildCsv, saveCsv } from "@/lib/utils/csv-export";
import { cn } from "@/lib/utils";

type T = ReturnType<typeof useT>["t"];

const ALL = "all";

/** Day of the month in the reader's calendar: Tikimt 30, not 9 November. */
function dayNumber(date: string, ethiopian: boolean): number {
  const [year, month, day] = date.split("-").map(Number);
  return ethiopian ? toEthiopian(year, month, day).day : day;
}

/** The columns of the summary block, in reading order; the pay column only where the API sent it. */
const SUMMARY_FIELDS = [
  "work_days",
  "present_days",
  "late_days",
  "absent_days",
  "leave_days",
  "holiday_days",
  "rest_days",
  "missing_days",
  "early_days",
] as const;

const MINUTE_FIELDS = ["late_minutes", "early_minutes"] as const;
const HOUR_FIELDS = ["scheduled_hours", "worked_hours", "short_hours"] as const;

function figure(totals: Record<string, unknown>, key: string): string {
  const value = totals[key];
  return typeof value === "number" ? String(value) : "";
}

export function TimesheetView() {
  const { t } = useT();
  const { calendar } = useCalendar();
  const ethiopian = calendar === "ethiopian";
  const { formatDate, formatWeekday } = useDateFormatters();
  const [filters, setFilters] = useTimesheetFilters();
  const period = periodOf(filters, calendar);
  const sheet = useTimesheet(filters, period);
  const departments = departmentsApi.useList().data?.data ?? [];
  const branches = branchesApi.useList().data?.data ?? [];
  const shifts = useShifts().data?.data ?? [];
  const [open, setOpen] = useState<{
    row: TimesheetRow;
    cell: TimesheetCell;
  } | null>(null);

  const month: ReportMonth = monthOf(period.from, calendar);

  usePrintTitle(
    `${t("reports_page.timesheet.title", "Monthly timesheet")} · ${formatDate(period.from)} – ${formatDate(period.to)}`,
  );

  // The picker hands a month; the API reads its first and last day.
  function setMonth(next: ReportMonth) {
    setFilters(reportMonthRange(next));
  }

  return (
    <div className="space-y-4">
      <PageActions
        actions={
          <>
            <TimesheetDownload
              data={sheet.data}
              filters={filters}
              period={period}
              stale={sheet.isPlaceholderData}
            />
            <PrintAction />
          </>
        }
      />

      <div className="flex flex-wrap items-end gap-3 print:hidden">
        <div className="space-y-1">
          <p className="text-sm font-medium">
            {t("reports_page.fields.month", "Month")}
          </p>
          <ReportMonthPicker value={month} onChange={setMonth} />
        </div>
        <UnitFilter
          label={t("reports_page.fields.department", "Department")}
          all={t("reports_page.timesheet.all_departments", "All departments")}
          value={filters.department}
          units={departments}
          onChange={(department) => setFilters({ department })}
        />
        <UnitFilter
          label={t("reports_page.fields.branch", "Branch")}
          all={t("reports_page.timesheet.all_branches", "All branches")}
          value={filters.branch}
          units={branches}
          onChange={(branch) => setFilters({ branch })}
        />
        <UnitFilter
          label={t("reports_page.timesheet.shift", "Shift")}
          all={t("reports_page.timesheet.all_shifts", "All shifts")}
          value={filters.shift}
          units={shifts}
          onChange={(shift) => setFilters({ shift })}
        />
        <StatusFilter
          value={filters.employment_status}
          onChange={(employment_status) => setFilters({ employment_status })}
        />
      </div>

      <QueryBoundary
        query={sheet}
        loading={<Skeleton className="h-96 w-full" />}
        error={
          <ErrorState
            title={t(
              "reports_page.timesheet.failed",
              "The timesheet could not be loaded",
            )}
            onRetry={() => sheet.refetch()}
          />
        }
        isEmpty={(data) => data.data.length === 0}
        empty={
          <div className="space-y-3 py-12 text-center">
            <p className="text-sm text-muted-foreground">
              {t(
                "reports_page.timesheet.empty",
                "No one has a day on the timesheet for this month and these filters.",
              )}
            </p>
            {filters.page > 1 ? (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setFilters({ page: 1 })}
              >
                {t(
                  "reports_page.timesheet.first_page",
                  "Back to the first page",
                )}
              </Button>
            ) : null}
          </div>
        }
      >
        {(data) => (
          <div className={cn(sheet.isPlaceholderData && "opacity-60")}>
            <Header data={data} period={period} />
            <div className="hidden md:block">
              <Grid
                data={data}
                ethiopian={ethiopian}
                weekday={formatWeekday}
                onOpen={(row, cell) => setOpen({ row, cell })}
              />
            </div>
            <div className="space-y-3 md:hidden">
              {data.data.map((row) => (
                <PersonCard
                  key={row.public_id}
                  row={row}
                  ethiopian={ethiopian}
                  onOpen={(cell) => setOpen({ row, cell })}
                />
              ))}
            </div>
            <Pager
              data={data}
              page={filters.page}
              onPage={(page) => setFilters({ page })}
            />
            <Totals data={data} />
            <Legend />
            <RulesApplied rules={data.rules_applied} />
          </div>
        )}
      </QueryBoundary>

      <DayDrawer
        row={open?.row ?? null}
        cell={open?.cell ?? null}
        onClose={() => setOpen(null)}
      />
    </div>
  );
}

function UnitFilter({
  label,
  all,
  value,
  units,
  onChange,
}: {
  label: string;
  all: string;
  value: string;
  units: Array<{ public_id: string; name: string; name_am?: string | null }>;
  onChange: (value: string) => void;
}) {
  const { locale } = useT();
  return (
    <div className="space-y-1">
      <p className="text-sm font-medium">{label}</p>
      <Select
        value={value || ALL}
        onValueChange={(next) => onChange(next === ALL ? "" : next)}
      >
        <SelectTrigger className="h-9 w-44" aria-label={label}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={ALL}>{all}</SelectItem>
          {units.map((unit) => (
            <SelectItem key={unit.public_id} value={unit.public_id}>
              {localizedName(unit, locale)}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

const EMPLOYMENT_STATUSES = [
  "hired",
  "probation",
  "confirmed",
  "suspended",
  "resigned",
  "terminated",
  "retired",
] as const;

function employmentLabel(t: T, status: string): string {
  switch (status) {
    case "hired":
      return t("reports_page.timesheet.employment.hired", "Hired");
    case "probation":
      return t("reports_page.timesheet.employment.probation", "On probation");
    case "confirmed":
      return t("reports_page.timesheet.employment.confirmed", "Confirmed");
    case "suspended":
      return t("reports_page.timesheet.employment.suspended", "Suspended");
    case "resigned":
      return t("reports_page.timesheet.employment.resigned", "Resigned");
    case "terminated":
      return t("reports_page.timesheet.employment.terminated", "Terminated");
    default:
      return t("reports_page.timesheet.employment.retired", "Retired");
  }
}

function StatusFilter({
  value,
  onChange,
}: {
  value: string;
  onChange: (value: string) => void;
}) {
  const { t } = useT();
  const label = t("reports_page.timesheet.employment", "Employment status");
  return (
    <div className="space-y-1">
      <p className="text-sm font-medium">{label}</p>
      <Select
        value={value || ALL}
        onValueChange={(next) => onChange(next === ALL ? "" : next)}
      >
        <SelectTrigger className="h-9 w-44" aria-label={label}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={ALL}>
            {t("reports_page.timesheet.all_statuses", "Everyone")}
          </SelectItem>
          {EMPLOYMENT_STATUSES.map((status) => (
            <SelectItem key={status} value={status}>
              {employmentLabel(t, status)}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

/** The title, the period and the status, which the printed sheet carries too. */
function Header({
  data,
  period,
}: {
  data: Timesheet;
  period: { from: string; to: string };
}) {
  const { t } = useT();
  const { formatDate } = useDateFormatters();
  const status = data.status;

  return (
    <div className="mb-3 flex flex-wrap items-center gap-3">
      <h2 className="text-lg font-semibold">
        {t("reports_page.timesheet.title", "Monthly timesheet")}
      </h2>
      <span className="text-sm text-muted-foreground">
        {formatDate(period.from)} – {formatDate(period.to)}
      </span>
      {status ? (
        <span
          className={cn(
            "inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium",
            statusTone(status.code),
          )}
        >
          {status.code === "paid" || status.code === "part_paid" ? (
            <Lock className="h-3 w-3" aria-hidden />
          ) : null}
          {statusLabel(t, status.code)}
          {status.code === "exceptions"
            ? ` · ${t("reports_page.timesheet.to_fix", ":count to fix", { count: status.blocking })}`
            : ""}
        </span>
      ) : (
        <Badge variant="secondary">
          {t(
            "reports_page.timesheet.no_status",
            "No status for a list this long",
          )}
        </Badge>
      )}
    </div>
  );
}

function Grid({
  data,
  ethiopian,
  weekday,
  onOpen,
}: {
  data: Timesheet;
  ethiopian: boolean;
  weekday: (date: string, style?: "long" | "short") => string;
  onOpen: (row: TimesheetRow, cell: TimesheetCell) => void;
}) {
  const { t, locale } = useT();
  const withPay = data.with_pay;
  const rates = data.rate_columns;

  return (
    <div className="overflow-x-auto rounded-md border">
      <table className="w-max min-w-full border-collapse text-xs">
        <caption className="sr-only">
          {t("reports_page.timesheet.title", "Monthly timesheet")}
        </caption>
        <thead>
          <tr className="bg-muted/50">
            <th
              scope="col"
              className="sticky left-0 z-10 min-w-48 bg-muted px-2 py-2 text-left font-medium"
            >
              {t("reports_page.timesheet.employee", "Employee")}
            </th>
            {data.days.map((day) => (
              <th
                key={day.date}
                scope="col"
                className={cn(
                  "w-9 min-w-9 px-0.5 py-1 text-center font-medium",
                  day.iso_weekday >= 6 && "bg-muted",
                )}
                title={weekday(day.date, "long")}
              >
                <span className="block text-[10px] text-muted-foreground">
                  {weekday(day.date, "short").slice(0, 3)}
                </span>
                {dayNumber(day.date, ethiopian)}
              </th>
            ))}
            {SUMMARY_FIELDS.map((key) => (
              <Head key={key} label={reportFieldLabel(t, key)} />
            ))}
            {withPay ? (
              <Head
                label={t("reports_page.timesheet.payable_days", "Payable days")}
              />
            ) : null}
            {MINUTE_FIELDS.map((key) => (
              <Head key={key} label={reportFieldLabel(t, key)} />
            ))}
            {HOUR_FIELDS.map((key) => (
              <Head key={key} label={reportFieldLabel(t, key)} />
            ))}
            {rates.map((rate) => (
              <Head
                key={rate.rate}
                label={t(
                  "reports_page.timesheet.overtime_rate",
                  "Overtime ×:rate",
                  {
                    rate: rate.rate,
                  },
                )}
              />
            ))}
            <Head label={reportFieldLabel(t, "overtime_unapproved_hours")} />
            <Head label={reportFieldLabel(t, "overtime_limit_days")} />
            <Head label={t("reports_page.timesheet.check", "Check")} />
          </tr>
        </thead>
        <tbody>
          {data.data.map((row, index) => (
            <tr key={row.public_id} className="border-t">
              <th
                scope="row"
                className="sticky left-0 z-10 bg-background px-2 py-1 text-left font-normal"
              >
                <span className="block font-medium">
                  {index +
                    1 +
                    (data.meta.current_page - 1) * data.meta.per_page}
                  {". "}
                  {localizedName(
                    { name: row.name, name_am: row.name_am },
                    locale,
                  )}
                </span>
                <span className="block text-[10px] text-muted-foreground">
                  {[row.employee_code, row.department?.name, row.shift?.name]
                    .filter(Boolean)
                    .join(" · ")}
                </span>
              </th>
              {row.cells.map((cell) => (
                <td key={cell.date} className="p-0.5 text-center">
                  <button
                    type="button"
                    onClick={() => onOpen(row, cell)}
                    className={cn(
                      "h-7 w-8 rounded text-[11px] font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-interactive-focus",
                      codeTone(cell.code),
                      cell.locked && "ring-1 ring-inset ring-border",
                    )}
                    aria-label={`${row.name}, ${cell.date}: ${cell.code ? codeLabel(t, cell.code) : t("reports_page.timesheet.pending_day", "The day is not over yet")}`}
                  >
                    {cell.short ?? "·"}
                  </button>
                </td>
              ))}
              {SUMMARY_FIELDS.map((key) => (
                <Num key={key} value={figure(row.totals, key)} />
              ))}
              {withPay ? (
                <Num value={figure(row.totals, "payable_days")} />
              ) : null}
              {MINUTE_FIELDS.map((key) => (
                <Num key={key} value={figure(row.totals, key)} />
              ))}
              {HOUR_FIELDS.map((key) => (
                <Num key={key} value={figure(row.totals, key)} />
              ))}
              {rates.map((rate, rateIndex) => (
                <Num
                  key={rate.rate}
                  value={String(
                    (row.totals.overtime_by_rate as number[] | undefined)?.[
                      rateIndex
                    ] ?? 0,
                  )}
                />
              ))}
              <Num value={figure(row.totals, "overtime_unapproved_hours")} />
              <Num value={figure(row.totals, "overtime_limit_days")} />
              <td className="px-2 text-center">
                {row.check_ok ? (
                  <span className="sr-only">
                    {t("reports_page.timesheet.check_ok", "Adds up")}
                  </span>
                ) : (
                  <span
                    title={t(
                      "reports_page.timesheet.check_failed",
                      "This row does not add up",
                    )}
                  >
                    <AlertTriangle
                      className="mx-auto h-4 w-4 text-status-warning"
                      aria-hidden
                    />
                    <span className="sr-only">
                      {t(
                        "reports_page.timesheet.check_failed",
                        "This row does not add up",
                      )}
                    </span>
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Head({ label }: { label: string }) {
  return (
    <th
      scope="col"
      className="min-w-14 px-1 py-1 text-center text-[10px] font-medium leading-tight"
    >
      {label}
    </th>
  );
}

function Num({ value }: { value: string }) {
  return <td className="px-1 text-center tabular-nums">{value}</td>;
}

/** On a phone, one card a person: the month's days as a strip, the figures below. */
function PersonCard({
  row,
  ethiopian,
  onOpen,
}: {
  row: TimesheetRow;
  ethiopian: boolean;
  onOpen: (cell: TimesheetCell) => void;
}) {
  const { t, locale } = useT();
  return (
    <article className="rounded-lg border p-3">
      <h3 className="font-medium">
        {localizedName({ name: row.name, name_am: row.name_am }, locale)}
        {!row.check_ok ? (
          <AlertTriangle
            className="ml-2 inline h-4 w-4 text-status-warning"
            aria-label={t(
              "reports_page.timesheet.check_failed",
              "This row does not add up",
            )}
          />
        ) : null}
      </h3>
      <p className="text-xs text-muted-foreground">
        {[row.employee_code, row.department?.name].filter(Boolean).join(" · ")}
      </p>
      <div className="mt-2 flex flex-wrap gap-1">
        {row.cells.map((cell) => (
          <button
            key={cell.date}
            type="button"
            onClick={() => onOpen(cell)}
            className={cn(
              "h-8 w-8 rounded text-[11px] font-semibold",
              codeTone(cell.code),
            )}
            aria-label={`${cell.date}: ${cell.code ? codeLabel(t, cell.code) : ""}`}
          >
            <span className="block text-[9px] font-normal leading-none opacity-70">
              {dayNumber(cell.date, ethiopian)}
            </span>
            {cell.short ?? "·"}
          </button>
        ))}
      </div>
      <dl className="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
        {(["present_days", "absent_days", "late_days"] as const).map((key) => (
          <div key={key}>
            <dd className="text-base font-semibold tabular-nums">
              {figure(row.totals, key)}
            </dd>
            <dt className="text-muted-foreground">
              {reportFieldLabel(t, key)}
            </dt>
          </div>
        ))}
        {(["worked_hours", "short_hours", "overtime_hours"] as const).map(
          (key) => (
            <div key={key}>
              <dd className="text-base font-semibold tabular-nums">
                {figure(row.totals, key)}
              </dd>
              <dt className="text-muted-foreground">
                {reportFieldLabel(t, key)}
              </dt>
            </div>
          ),
        )}
      </dl>
    </article>
  );
}

function Pager({
  data,
  page,
  onPage,
}: {
  data: Timesheet;
  page: number;
  onPage: (page: number) => void;
}) {
  const { t } = useT();
  if (data.meta.last_page <= 1) return null;
  return (
    <nav
      className="mt-3 flex items-center justify-between text-sm print:hidden"
      aria-label={t("reports_page.timesheet.pages", "Pages")}
    >
      <Button
        variant="outline"
        size="sm"
        disabled={page <= 1}
        onClick={() => onPage(page - 1)}
      >
        {t("common.previous", "Previous")}
      </Button>
      <span className="text-muted-foreground">
        {t("reports_page.timesheet.page_of", "Page :page of :last", {
          page: data.meta.current_page,
          last: data.meta.last_page,
        })}{" "}
        ·{" "}
        {t("reports_page.timesheet.people", ":count people", {
          count: data.meta.total,
        })}
      </span>
      <Button
        variant="outline"
        size="sm"
        disabled={page >= data.meta.last_page}
        onClick={() => onPage(page + 1)}
      >
        {t("common.next", "Next")}
      </Button>
    </nav>
  );
}

/** The totals over everyone matched, and each department's, or why there are none. */
function Totals({ data }: { data: Timesheet }) {
  const { t, locale } = useT();

  if (!data.complete) {
    return (
      <p className="mt-4 rounded-md bg-muted p-3 text-sm">
        {t(
          "reports_page.timesheet.too_many",
          "Totals and the status are shown for :max people or fewer. Choose a department, branch or shift to narrow the list.",
          { max: data.max_people },
        )}
      </p>
    );
  }

  const totals = data.totals ?? {};
  const keys = [
    "work_days",
    "present_days",
    "late_days",
    "absent_days",
    "leave_days",
    "worked_hours",
    "overtime_hours",
    "short_hours",
  ] as const;

  return (
    <section className="mt-4 space-y-2">
      <h3 className="text-sm font-semibold">
        {t("reports_page.timesheet.totals", "Totals for everyone listed")}
      </h3>
      <div className="overflow-x-auto rounded-md border">
        <table className="w-full text-xs">
          <thead className="bg-muted/50">
            <tr>
              <th scope="col" className="px-2 py-1 text-left font-medium">
                {t("reports_page.fields.department", "Department")}
              </th>
              <th scope="col" className="px-2 py-1 text-center font-medium">
                {t("reports_page.timesheet.people_short", "People")}
              </th>
              {keys.map((key) => (
                <th
                  key={key}
                  scope="col"
                  className="px-2 py-1 text-center font-medium"
                >
                  {reportFieldLabel(t, key)}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {data.department_subtotals.map((group) => (
              <tr
                key={group.department?.public_id ?? "none"}
                className="border-t"
              >
                <th scope="row" className="px-2 py-1 text-left font-normal">
                  {group.department
                    ? localizedName(group.department, locale)
                    : t(
                        "reports_page.timesheet.no_department",
                        "No department",
                      )}
                </th>
                <Num value={String(group.employees)} />
                {keys.map((key) => (
                  <Num key={key} value={figure(group.totals, key)} />
                ))}
              </tr>
            ))}
            <tr className="border-t bg-muted/40 font-semibold">
              <th scope="row" className="px-2 py-1 text-left">
                {t("reports_page.timesheet.grand_total", "Grand total")}
              </th>
              <Num value={String(data.meta.total)} />
              {keys.map((key) => (
                <Num key={key} value={figure(totals, key)} />
              ))}
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  );
}

function Legend() {
  const { t } = useT();
  return (
    <section className="mt-4">
      <h3 className="mb-2 text-sm font-semibold">
        {t("reports_page.timesheet.legend", "Codes")}
      </h3>
      <ul className="flex flex-wrap gap-x-4 gap-y-2 text-xs">
        {TIMESHEET_CODES.map(({ code, short }) => (
          <li key={code} className="flex items-center gap-1.5">
            <span
              className={cn(
                "inline-flex min-w-7 justify-center rounded px-1.5 py-0.5 font-semibold",
                codeTone(code),
              )}
            >
              {short}
            </span>
            {codeLabel(t, code)}
          </li>
        ))}
      </ul>
    </section>
  );
}

/** The sheet as a CSV, as it reads on screen: a code a day, then the figures. */
export function timesheetCsv(data: Timesheet, t: T, locale: string): string {
  const rows = data.data.map((row) => ({
    [t("reports_page.timesheet.employee", "Employee")]: localizedName(
      { name: row.name, name_am: row.name_am },
      locale,
    ),
    [reportFieldLabel(t, "employee_code")]: row.employee_code ?? "",
    [reportFieldLabel(t, "department")]: row.department?.name ?? "",
    ...Object.fromEntries(
      row.cells.map((cell) => [cell.date, cell.short ?? ""]),
    ),
    ...Object.fromEntries(
      [
        ...SUMMARY_FIELDS,
        ...(data.with_pay ? (["payable_days"] as const) : []),
        ...MINUTE_FIELDS,
        ...HOUR_FIELDS,
      ].map((key) => [
        key === "payable_days"
          ? t("reports_page.timesheet.payable_days", "Payable days")
          : reportFieldLabel(t, key),
        figure(row.totals, key),
      ]),
    ),
    // One column for each overtime rate, as the sheet has them.
    ...Object.fromEntries(
      data.rate_columns.map((rate, index) => [
        t("reports_page.timesheet.overtime_rate", "Overtime ×:rate", {
          rate: rate.rate,
        }),
        String(
          (row.totals.overtime_by_rate as number[] | undefined)?.[index] ?? 0,
        ),
      ]),
    ),
    [reportFieldLabel(t, "overtime_unapproved_hours")]: figure(
      row.totals,
      "overtime_unapproved_hours",
    ),
    [reportFieldLabel(t, "overtime_limit_days")]: figure(
      row.totals,
      "overtime_limit_days",
    ),
  }));
  return buildCsv(rows);
}

export function TimesheetDownload({
  data,
  filters,
  period,
  stale = false,
}: {
  data: Timesheet | undefined;
  filters: TimesheetFilters;
  period: { from: string; to: string };
  /** The rows are the previous filter's while the new ones load. */
  stale?: boolean;
}) {
  const { t, locale } = useT();
  const [busy, setBusy] = useState(false);
  const body = useMemo(
    () => (data ? timesheetCsv(data, t, locale) : ""),
    [data, t, locale],
  );
  if (!data || data.data.length === 0) return null;
  // A CSV is the rows on screen: say so when they are one page of several.
  const onePage = data.meta.last_page <= 1;

  async function pdf() {
    setBusy(true);
    try {
      await downloadTimesheetPdf(filters, period);
    } catch (error) {
      toast.error(
        error instanceof Error && error.message
          ? error.message
          : t(
              "reports_page.timesheet.pdf_failed",
              "The PDF could not be made. Try again, or choose a department.",
            ),
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <Button variant="outline" size="sm" onClick={pdf} disabled={busy}>
        <FileText className="mr-2 h-4 w-4" aria-hidden />
        {t("reports_page.timesheet.download_pdf", "Download PDF")}
      </Button>
      <Button
        variant="outline"
        size="sm"
        disabled={stale}
        onClick={() =>
          saveCsv(
            `timesheet-${data?.period?.from ?? "month"}${onePage ? "" : `-page-${data.meta.current_page}`}.csv`,
            body,
          )
        }
      >
        <Download className="mr-2 h-4 w-4" aria-hidden />
        {onePage
          ? t("reports_page.timesheet.download_csv", "Download CSV")
          : t(
              "reports_page.timesheet.download_csv_page",
              "Download this page as CSV",
            )}
      </Button>
    </>
  );
}
