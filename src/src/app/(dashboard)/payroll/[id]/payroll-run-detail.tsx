"use client";

import { useState } from "react";
import Link from "next/link";
import { useEntityTitle } from "@/components/layouts/page-actions-slot";
import {
  Calculator,
  Download,
  CheckCircle,
  Loader2,
  FileSpreadsheet,
  FileText,
  Landmark,
  BookOpen,
  Ban,
  RotateCcw,
  Wallet,
  Banknote,
  Users,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { StatusBadge } from "@/components/shared/status-badge";
import { ConfirmDialog } from "@/components/shared/confirm-dialog";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { StatCard, type StatTone } from "@/components/dashboard/stat-card";
import { formatETBCompact } from "@/lib/utils/currency";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  bankTransferCsv,
  fetchBankTransferExport,
  downloadStatutorySchedule,
  fetchPayrollRegister,
  rateLabel,
  fetchStatutoryCheck,
  type StatutorySchedule,
  usePayrollRun,
  useApprovePayroll,
  usePayrollRegister,
  useVoidPayroll,
  useReprocessPayroll,
  type PayrollEntry,
} from "@/features/payroll/api";
import { CalculationLogDialog } from "@/features/payroll/components/calculation-log-dialog";
import { PensionExemptionsBanner } from "@/features/payroll/components/pension-exemptions-banner";
import {
  fetchPayrollJournal,
  journalCsv,
  journalFilename,
} from "@/features/accounting/api";
import { csvAmount, csvFromRows, saveCsv } from "@/lib/utils/csv-export";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useRouteId } from "@/lib/hooks/useRouteId";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";
import { usePlanFeatures } from "@/features/auth/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useCalendar } from "@/lib/calendar/calendar-context";
import { dateFromIso } from "@/lib/calendar/date-from-iso";
import { localizedPeriodLabel } from "@/lib/calendar/period-label";

export function PayrollRunDetail({ routeId }: { routeId: string }) {
  const { t, locale } = useT();
  const { calendar, formatDate } = useCalendar();
  const id = useRouteId(routeId) ?? "";
  const { data: run, isLoading } = usePayrollRun(id);
  const periodTitle = run
    ? localizedPeriodLabel(
        run.period_label,
        run.period_start,
        run.period_end,
        calendar,
        locale,
      )
    : undefined;
  // Ends the title bar's breadcrumb with this run; it had a separate "Back"
  // button instead.
  useEntityTitle(periodTitle);
  // Each action is its own permission server-side (`PayrollRunPolicy`). The
  // page asked for the tenant-admin role instead, so a custom role granted
  // `payroll.approve` never saw the button.
  const { hasPermission } = usePermissions();
  // And the plan's `payroll` feature, which the API also requires (N66).
  const hasPayroll = usePlanFeatures().has("payroll");
  const canApprove = hasPayroll && hasPermission("payroll.approve");
  const canVoid = hasPayroll && hasPermission("payroll.void");
  const canReprocess = hasPayroll && hasPermission("payroll.reprocess");
  const approvePayroll = useApprovePayroll();
  // The pension exemptions, shown before approval and acknowledged by count.
  const { data: register, isLoading: registerLoading } = usePayrollRegister(
    id,
    !!id,
  );
  const exemptionCount = register?.pension_exemptions?.count ?? 0;
  const voidPayroll = useVoidPayroll();
  const reprocessPayroll = useReprocessPayroll();
  const [voidDialogOpen, setVoidDialogOpen] = useState(false);
  const [calcEntry, setCalcEntry] = useState<{
    id: string;
    name: string;
  } | null>(null);
  const [voidReason, setVoidReason] = useState("");
  // Approving releases every payslip in the run to its employee and cannot be
  // taken back from this screen; reprocessing creates a whole new run. Both
  // went through on one click (approve) or a native confirm() (reprocess),
  // found in the 2026-10-08 browser pass. Void already used a dialog.
  const [approveDialogOpen, setApproveDialogOpen] = useState(false);
  const [reprocessDialogOpen, setReprocessDialogOpen] = useState(false);

  function approve() {
    approvePayroll.mutate(
      { publicId: id, acknowledgedPensionExemptions: exemptionCount },
      {
        onSuccess: () =>
          toast.success(t("payroll_detail_page.approved_success")),
        onError: (err: unknown) => {
          toast.error(
            apiErrorDetail(err) || t("payroll_detail_page.approve_failed"),
          );
        },
      },
    );
  }

  function handleVoid(e: React.FormEvent) {
    e.preventDefault();
    voidPayroll.mutate(
      { publicId: id, reason: voidReason },
      {
        onSuccess: () => {
          toast.success(t("payroll_detail_page.voided_success"));
          setVoidDialogOpen(false);
          setVoidReason("");
        },
        onError: (err: unknown) => {
          toast.error(
            apiErrorDetail(err) || t("payroll_detail_page.void_failed"),
          );
        },
      },
    );
  }

  function handleReprocess() {
    reprocessPayroll.mutate(
      { publicId: id, idempotency_key: crypto.randomUUID() },
      {
        onSuccess: () =>
          toast.success(t("payroll_detail_page.reprocessed_success")),
        onError: (err: unknown) => {
          toast.error(
            apiErrorDetail(err) || t("payroll_detail_page.reprocess_failed"),
          );
        },
      },
    );
  }

  // Every export goes through the shared CSV helpers: amounts as plain
  // "12345.67" (a grouped "12,345.67" split into two columns when joined
  // unquoted — the bank transfer file included), cells quoted and
  // formula-safe.
  //
  // The register comes from the server's RunRegisterService, whose totals
  // tie to the run and to the accounting journal. It was assembled here from
  // the run's entries, without overtime, allowances, taxable income, the
  // deduction breakdown or a totals row — not a file an accountant could
  // reconcile (payroll proof pack, 2026-10-14).
  // The monthly filings (StatutorySchedules on the server). The check runs
  // first so a missing employer TIN or employee TIN is said before the file
  // is handed over, not discovered at the tax office.
  async function exportStatutory(
    schedule: StatutorySchedule,
    format: "csv" | "pdf",
  ) {
    if (!run) return;
    try {
      const check = await fetchStatutoryCheck(run.public_id, schedule);
      if (!check.header.employer_tin) {
        toast.warning(t("statutory.no_employer_tin"));
      }
      if (check.missing_tin.length > 0) {
        toast.warning(
          t("statutory.missing_tin", undefined, {
            count: check.missing_tin.length,
            names: check.missing_tin.slice(0, 3).join(", "),
          }),
        );
      }
      await downloadStatutorySchedule(
        run.public_id,
        schedule,
        format,
        `${schedule}-schedule-${run.period_label?.replace(/\s/g, "-")}.${format}`,
      );
    } catch (err) {
      toast.error(apiErrorDetail(err) ?? t("statutory.failed"));
    }
  }

  async function exportPayrollRegister() {
    if (!run) return;
    try {
      const register = await fetchPayrollRegister(run.public_id);
      const columns = [
        "basic_cents",
        "overtime_cents",
        "allowances_cents",
        "gross_cents",
        "taxable_cents",
        "income_tax_cents",
        "employee_pension_cents",
        "employer_pension_cents",
        "loan_cents",
        "cost_sharing_cents",
        "net_cents",
      ] as const;
      const headers = [
        "Employee Code",
        "Employee",
        "Basic",
        "Overtime",
        "Allowances",
        "Gross",
        "Taxable",
        "Income Tax",
        // The rates this run applied, never a constant (2026-10-14).
        `Employee Pension ${rateLabel(register.pension_rates?.employee)}`.trim(),
        `Employer Pension ${rateLabel(register.pension_rates?.employer)}`.trim(),
        "Loan",
        "Cost Sharing",
        "Net Pay",
      ];
      const rows = register.rows.map((r) => [
        r.employee_code,
        r.employee_name,
        ...columns.map((c) => csvAmount(r[c])),
      ]);
      rows.push([
        "TOTAL",
        String(register.rows.length),
        ...columns.map((c) => csvAmount(register.totals[c])),
      ]);
      saveCsv(
        `payroll-register-${run.period_label?.replace(/\s/g, "-")}.csv`,
        csvFromRows(headers, rows),
      );
      toast.success(t("payroll_detail_page.register_downloaded"));
    } catch {
      toast.error(
        t(
          "payroll_detail_page.register_failed",
          "Couldn't download the payroll register",
        ),
      );
    }
  }

  // Both files are built here from the API's JSON rather than downloaded from
  // the server's CSV endpoints: `/export/bank-csv` has no branch column and
  // does not neutralise formulas, and `/accounting/export/{run}` groups
  // amounts with commas. The builders say why in full.
  async function exportBankFile() {
    try {
      const file = await fetchBankTransferExport(id);
      saveCsv(
        `bank-transfer-${file.period.replace(/\s/g, "-")}.csv`,
        bankTransferCsv(file),
        // Uploaded to the bank, whose importer may refuse a byte-order mark.
        { byteOrderMark: false },
      );
      toast.success(t("payroll_detail_page.bank_file_downloaded"));
    } catch {
      toast.error(t("payroll_detail_page.bank_file_failed"));
    }
  }

  async function exportJournal() {
    try {
      const journal = await fetchPayrollJournal(id);
      if (!journal.entries.length) {
        toast.error(t("payroll_detail_page.no_journal_entries"));
        return;
      }
      // The same file the Accounting settings page exports.
      saveCsv(journalFilename(journal), journalCsv(journal), {
        byteOrderMark: false,
      });
      toast.success(t("payroll_detail_page.journal_downloaded"));
    } catch {
      toast.error(t("payroll_detail_page.journal_failed"));
    }
  }

  if (!id || isLoading) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-48" />
        <div className="grid gap-4 sm:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  if (!run) {
    return (
      <div className="py-16 text-center">
        <p className="text-muted-foreground">
          {t("payroll_detail_page.run_not_found")}
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-semibold tracking-tight text-foreground">
              {periodTitle}
            </h1>
            <StatusBadge status={run.status} />
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            {run.period_start
              ? formatDate(dateFromIso(run.period_start), locale)
              : ""}{" "}
            {t("payroll_detail_page.to")}{" "}
            {run.period_end
              ? formatDate(dateFromIso(run.period_end), locale)
              : ""}{" "}
            &middot; {run.employee_count}{" "}
            {t("payroll_detail_page.employees_lc")}
          </p>
          {(run.skipped_no_salary ?? 0) > 0 && (
            <p className="mt-1 text-sm text-status-warning">
              {t(
                "payroll_detail_page.skipped_no_salary",
                ":count employees were skipped because no salary is set for them",
                { count: run.skipped_no_salary },
              )}
            </p>
          )}
        </div>
        <div className="flex items-center gap-2">
          {run.status === "completed" && canApprove && (
            <Button
              size="sm"
              onClick={() => setApproveDialogOpen(true)}
              disabled={approvePayroll.isPending || registerLoading}
            >
              {approvePayroll.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <CheckCircle className="mr-2 h-4 w-4" />
              )}
              {t("payroll_detail_page.approve_payroll")}
            </Button>
          )}
          {(run.status === "completed" || run.status === "approved") &&
            canVoid && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setVoidDialogOpen(true)}
              >
                <Ban className="mr-2 h-4 w-4 text-destructive" />
                {t("payroll_detail_page.void_payroll")}
              </Button>
            )}
          {run.status === "voided" && canReprocess && (
            <Button
              size="sm"
              variant="outline"
              onClick={() => setReprocessDialogOpen(true)}
              disabled={reprocessPayroll.isPending}
            >
              {reprocessPayroll.isPending ? (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              ) : (
                <RotateCcw className="mr-2 h-4 w-4" />
              )}
              {t("payroll_detail_page.reprocess_payroll")}
            </Button>
          )}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm">
                <Download className="mr-2 h-4 w-4" />
                {t("common.export")}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={exportPayrollRegister}>
                <FileSpreadsheet className="mr-2 h-4 w-4" />
                {t("payroll_detail_page.export_register")}
              </DropdownMenuItem>
              {/* A bank file is an instruction to pay, so the server builds
                  one only for an approved run. */}
              {run.status === "approved" && (
                <DropdownMenuItem onClick={exportBankFile}>
                  <Landmark className="mr-2 h-4 w-4" />
                  {t("payroll_detail_page.export_bank")}
                </DropdownMenuItem>
              )}
              <DropdownMenuItem onClick={exportJournal}>
                <BookOpen className="mr-2 h-4 w-4" />
                {t("payroll_detail_page.export_journal")}
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuLabel className="text-xs text-muted-foreground">
                {t("statutory.menu_label")}
              </DropdownMenuLabel>
              {(["income-tax", "pension"] as const).map((schedule) =>
                (["csv", "pdf"] as const).map((format) => (
                  <DropdownMenuItem
                    key={`${schedule}-${format}`}
                    onClick={() => exportStatutory(schedule, format)}
                  >
                    <FileText className="mr-2 h-4 w-4" />
                    {t(`statutory.${schedule}_${format}`)}
                  </DropdownMenuItem>
                )),
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {run.status === "voided" && (
        <div className="rounded-lg border-2 border-destructive/30 bg-destructive/5 p-3 text-sm">
          <p className="font-semibold text-destructive">
            {t("payroll_detail_page.voided_notice")}
          </p>
          {run.void_reason && (
            <p className="mt-1 text-muted-foreground">{run.void_reason}</p>
          )}
        </div>
      )}

      {register?.pension_exemptions && run.status !== "voided" && (
        <PensionExemptionsBanner exemptions={register.pension_exemptions} />
      )}

      {run.reprocessed_from_public_id && (
        <div className="rounded-lg border bg-muted/30 p-3 text-sm text-muted-foreground">
          {t("payroll_detail_page.reprocessed_from_notice")}{" "}
          <Link
            href={`/payroll/${run.reprocessed_from_public_id}`}
            className="font-medium text-primary hover:underline"
          >
            {run.reprocessed_from_public_id}
          </Link>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryCard
          icon={Wallet}
          tone="primary"
          label={t("payroll_detail_page.gross_total")}
          cents={run.gross_total_cents}
        />
        <SummaryCard
          icon={Banknote}
          tone="success"
          label={t("payroll_detail_page.net_total")}
          cents={run.net_total_cents}
        />
        <SummaryCard
          icon={Landmark}
          tone="warning"
          label={t("payroll_detail_page.total_tax")}
          cents={run.tax_total_cents}
        />
        <StatCard
          icon={Users}
          tone="neutral"
          label={t("payroll_detail_page.employees")}
          value={run.employee_count}
        />
      </div>

      {run.entries && run.entries.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("payroll_detail_page.payroll_entries")}
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <SimpleTable
              caption={t("payroll_detail_page.payroll_entries")}
              headers={[
                t("attendance.employee"),
                t("payroll_detail_page.basic"),
                t("payroll_page.payslips_page.gross"),
                t("payroll_detail_page.tax"),
                t("payroll_detail_page.pension"),
                t("payroll_detail_page.net"),
              ]}
              align={["left", "right", "right", "right", "right", "right"]}
              // Amounts no longer wrap, so the row is wider: Basic and Pension
              // join from xl, keeping Net — the column a run is checked by —
              // on screen at 1024px.
              colClassName={[
                "",
                "hidden xl:table-cell",
                "hidden md:table-cell",
                "hidden sm:table-cell",
                "hidden xl:table-cell",
                "",
              ]}
              rows={run.entries.map((entry: PayrollEntry) => ({
                key: entry.public_id,
                actions: (
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                      setCalcEntry({
                        id: entry.public_id,
                        name: entry.employee?.name ?? "",
                      })
                    }
                  >
                    <Calculator className="h-4 w-4" aria-hidden="true" />
                    <span className="sr-only">
                      {t(
                        "payroll_detail_page.calc_open",
                        "How this pay was calculated",
                      )}
                      {entry.employee?.name ? `: ${entry.employee.name}` : ""}
                    </span>
                  </Button>
                ),
                cells: [
                  <span key="e" className="font-medium">
                    {entry.employee?.name ?? "—"}
                  </span>,
                  <CurrencyDisplay
                    key="b"
                    cents={entry.basic_salary_cents}
                    className="text-muted-foreground"
                  />,
                  <CurrencyDisplay
                    key="g"
                    cents={entry.gross_cents}
                    className="text-muted-foreground"
                  />,
                  <CurrencyDisplay
                    key="t"
                    cents={entry.income_tax_cents}
                    className="text-muted-foreground"
                  />,
                  <CurrencyDisplay
                    key="p"
                    cents={entry.employee_pension_cents}
                    className="text-muted-foreground"
                  />,
                  <CurrencyDisplay
                    key="n"
                    cents={entry.net_cents}
                    className="font-semibold text-foreground"
                  />,
                ],
              }))}
            />
          </CardContent>
        </Card>
      )}

      {run && (
        <CalculationLogDialog
          runId={run.public_id}
          entryId={calcEntry?.id ?? null}
          employeeName={calcEntry?.name ?? ""}
          onOpenChange={(open) => {
            if (!open) setCalcEntry(null);
          }}
        />
      )}

      <ConfirmDialog
        open={approveDialogOpen}
        onOpenChange={setApproveDialogOpen}
        title={t("payroll_detail_page.approve_confirm_title")}
        description={
          exemptionCount > 0
            ? t("payroll_detail_page.approve_confirm_exemptions", undefined, {
                count: exemptionCount,
              })
            : t("payroll_detail_page.approve_confirm_description")
        }
        confirmLabel={
          exemptionCount > 0
            ? t("payroll_detail_page.approve_with_exemptions", undefined, {
                count: exemptionCount,
              })
            : t("payroll_detail_page.approve_payroll")
        }
        onConfirm={() => {
          setApproveDialogOpen(false);
          approve();
        }}
      />

      <ConfirmDialog
        open={reprocessDialogOpen}
        onOpenChange={setReprocessDialogOpen}
        title={t("payroll_detail_page.reprocess_confirm_title")}
        description={t("payroll_detail_page.reprocess_confirm")}
        confirmLabel={t("payroll_detail_page.reprocess_payroll")}
        onConfirm={() => {
          setReprocessDialogOpen(false);
          handleReprocess();
        }}
      />

      <Dialog open={voidDialogOpen} onOpenChange={setVoidDialogOpen}>
        <DialogContent>
          <form onSubmit={handleVoid}>
            <DialogHeader>
              <DialogTitle>{t("payroll_detail_page.void_payroll")}</DialogTitle>
              <DialogDescription>
                {t("payroll_detail_page.void_dialog_description")}
              </DialogDescription>
            </DialogHeader>
            <div className="py-4">
              <Label htmlFor="void-reason">
                {t("payroll_detail_page.void_reason_label")}
              </Label>
              <Textarea
                id="void-reason"
                className="mt-1"
                required
                maxLength={500}
                value={voidReason}
                onChange={(e) => setVoidReason(e.target.value)}
              />
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setVoidDialogOpen(false)}
              >
                {t("common.cancel")}
              </Button>
              <Button
                type="submit"
                variant="destructive"
                disabled={voidPayroll.isPending}
              >
                {voidPayroll.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {t("payroll_detail_page.void_payroll")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/**
 * A run total: the headline compact (`2.59M ETB`), the exact figure under it.
 * The exact amount at tile size wrapped onto two lines in a four-column row.
 */
function SummaryCard({
  icon,
  tone,
  label,
  cents,
}: {
  icon: React.ComponentType<{ className?: string }>;
  tone: StatTone;
  label: string;
  cents: number;
}) {
  return (
    <StatCard
      icon={icon}
      tone={tone}
      label={label}
      value={formatETBCompact(cents)}
      hint={<CurrencyDisplay cents={cents} />}
    />
  );
}
