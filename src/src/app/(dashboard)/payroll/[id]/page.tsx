"use client";

import { use, useState } from "react";
import Link from "next/link";
import {
  ArrowLeft,
  Download,
  CheckCircle,
  Loader2,
  FileSpreadsheet,
  Landmark,
  BookOpen,
  Ban,
  RotateCcw,
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
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { StatusBadge } from "@/components/shared/status-badge";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { SimpleTable } from "@/components/shared/simple-table";
import {
  usePayrollRun,
  useApprovePayroll,
  useVoidPayroll,
  useReprocessPayroll,
  type PayrollEntry,
} from "@/features/payroll/api";
import { apiClient } from "@/api/client";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

export default function PayrollDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { t } = useT();
  const { id } = use(params);
  const { data: run, isLoading } = usePayrollRun(id);
  const { isAtLeast } = usePermissions();
  const approvePayroll = useApprovePayroll();
  const voidPayroll = useVoidPayroll();
  const reprocessPayroll = useReprocessPayroll();
  const [voidDialogOpen, setVoidDialogOpen] = useState(false);
  const [voidReason, setVoidReason] = useState("");

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
          const e = err as { response?: { data?: { detail?: string } } };
          toast.error(
            e.response?.data?.detail || t("payroll_detail_page.void_failed"),
          );
        },
      },
    );
  }

  function handleReprocess() {
    if (!confirm(t("payroll_detail_page.reprocess_confirm"))) return;
    reprocessPayroll.mutate(
      { publicId: id, idempotency_key: crypto.randomUUID() },
      {
        onSuccess: () =>
          toast.success(t("payroll_detail_page.reprocessed_success")),
        onError: (err: unknown) => {
          const e = err as { response?: { data?: { detail?: string } } };
          toast.error(
            e.response?.data?.detail ||
              t("payroll_detail_page.reprocess_failed"),
          );
        },
      },
    );
  }

  function formatCents(cents: number): string {
    return (cents / 100).toLocaleString("en-ET", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function downloadCsv(filename: string, content: string) {
    const blob = new Blob([content], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
  }

  function exportPayrollRegister() {
    if (!run?.entries) return;
    const headers = [
      "Employee",
      "Basic Salary",
      "Gross",
      "Income Tax",
      "Employee Pension",
      "Employer Pension",
      "Other Deductions",
      "Net Pay",
    ];
    const rows = run.entries.map(
      (e: PayrollEntry & { employee?: { name: string } }) => [
        e.employee?.name ?? "",
        formatCents(e.basic_salary_cents),
        formatCents(e.gross_cents),
        formatCents(e.income_tax_cents),
        formatCents(e.employee_pension_cents),
        formatCents(e.employer_pension_cents),
        formatCents(e.other_deductions_cents),
        formatCents(e.net_cents),
      ],
    );
    const csv = [
      headers.join(","),
      ...rows.map((r: string[]) => r.join(",")),
    ].join("\n");
    downloadCsv(
      `payroll-register-${run.period_label?.replace(/\s/g, "-")}.csv`,
      csv,
    );
    toast.success(t("payroll_detail_page.register_downloaded"));
  }

  async function exportBankFile() {
    try {
      const { data } = await apiClient.get(`/payroll/runs/${id}/export/bank`);
      const headers = [
        "Employee Name",
        "Employee Code",
        "Bank",
        "Branch",
        "Account Number",
        "Net Amount (ETB)",
      ];
      const rows = (data.rows ?? []).map(
        (r: {
          employee_name: string;
          employee_code: string;
          bank_name: string;
          branch_name: string;
          account_number: string;
          net_amount_cents: number;
        }) => [
          r.employee_name,
          r.employee_code,
          r.bank_name,
          r.branch_name,
          r.account_number,
          formatCents(r.net_amount_cents),
        ],
      );
      const csv = [
        headers.join(","),
        ...rows.map((r: string[]) => r.join(",")),
      ].join("\n");
      downloadCsv(`bank-transfer-${data.period?.replace(/\s/g, "-")}.csv`, csv);
      toast.success(t("payroll_detail_page.bank_file_downloaded"));
    } catch {
      toast.error(t("payroll_detail_page.bank_file_failed"));
    }
  }

  async function exportJournal() {
    try {
      const { data } = await apiClient.get(`/accounting/journal/${id}`);
      const entries = data.entries ?? data.journal?.entries ?? [];
      if (!entries.length) {
        toast.error(t("payroll_detail_page.no_journal_entries"));
        return;
      }
      const headers = ["Account", "Description", "Debit (ETB)", "Credit (ETB)"];
      const rows = entries.map(
        (e: {
          account: string;
          description: string;
          debit_cents: number;
          credit_cents: number;
        }) => [
          e.account,
          e.description,
          e.debit_cents ? formatCents(e.debit_cents) : "",
          e.credit_cents ? formatCents(e.credit_cents) : "",
        ],
      );
      const csv = [
        headers.join(","),
        ...rows.map((r: string[]) => r.join(",")),
      ].join("\n");
      downloadCsv(`journal-${run?.period_label?.replace(/\s/g, "-")}.csv`, csv);
      toast.success(t("payroll_detail_page.journal_downloaded"));
    } catch {
      toast.error(t("payroll_detail_page.journal_failed"));
    }
  }

  if (isLoading) {
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
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="sm" asChild>
          <Link href="/payroll">
            <ArrowLeft className="mr-2 h-4 w-4" />
            {t("common.back")}
          </Link>
        </Button>
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-bold text-foreground">
              {run.period_label}
            </h1>
            <StatusBadge status={run.status} />
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            {run.period_start} {t("payroll_detail_page.to")} {run.period_end}{" "}
            &middot; {run.employee_count}{" "}
            {t("payroll_detail_page.employees_lc")}
          </p>
        </div>
        <div className="flex items-center gap-2">
          {run.status === "completed" && isAtLeast("tenant_admin") && (
            <Button
              size="sm"
              onClick={() =>
                approvePayroll.mutate(id, {
                  onSuccess: () =>
                    toast.success(t("payroll_detail_page.approved_success")),
                  onError: (err: unknown) => {
                    const e = err as {
                      response?: { data?: { detail?: string } };
                    };
                    toast.error(
                      e.response?.data?.detail ||
                        t("payroll_detail_page.approve_failed"),
                    );
                  },
                })
              }
              disabled={approvePayroll.isPending}
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
            isAtLeast("tenant_admin") && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => setVoidDialogOpen(true)}
              >
                <Ban className="mr-2 h-4 w-4 text-destructive" />
                {t("payroll_detail_page.void_payroll")}
              </Button>
            )}
          {run.status === "voided" && isAtLeast("tenant_admin") && (
            <Button
              size="sm"
              variant="outline"
              onClick={handleReprocess}
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
              <DropdownMenuItem onClick={exportBankFile}>
                <Landmark className="mr-2 h-4 w-4" />
                {t("payroll_detail_page.export_bank")}
              </DropdownMenuItem>
              <DropdownMenuItem onClick={exportJournal}>
                <BookOpen className="mr-2 h-4 w-4" />
                {t("payroll_detail_page.export_journal")}
              </DropdownMenuItem>
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

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryCard
          label={t("payroll_detail_page.gross_total")}
          cents={run.gross_total_cents}
        />
        <SummaryCard
          label={t("payroll_detail_page.net_total")}
          cents={run.net_total_cents}
          highlight
        />
        <SummaryCard
          label={t("payroll_detail_page.total_tax")}
          cents={run.tax_total_cents}
        />
        <Card>
          <CardContent className="p-4">
            <p className="text-sm text-muted-foreground">
              {t("payroll_detail_page.employees")}
            </p>
            <p className="mt-1 text-2xl font-bold text-foreground">
              {run.employee_count}
            </p>
          </CardContent>
        </Card>
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
              colClassName={[
                "",
                "",
                "hidden md:table-cell",
                "hidden sm:table-cell",
                "hidden sm:table-cell",
                "",
              ]}
              rows={run.entries.map(
                (entry: {
                  public_id: string;
                  employee?: { name: string };
                  basic_salary_cents: number;
                  gross_cents: number;
                  income_tax_cents: number;
                  employee_pension_cents: number;
                  net_cents: number;
                }) => ({
                  key: entry.public_id,
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
                }),
              )}
            />
          </CardContent>
        </Card>
      )}

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

function SummaryCard({
  label,
  cents,
  highlight,
}: {
  label: string;
  cents: number;
  highlight?: boolean;
}) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p
          className={`mt-1 text-2xl font-bold ${highlight ? "text-primary" : "text-foreground"}`}
        >
          <CurrencyDisplay cents={cents} />
        </p>
      </CardContent>
    </Card>
  );
}
