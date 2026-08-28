"use client";

import { useState } from "react";
import { FileText, Download } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { PageHeader } from "@/components/shared/page-header";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { PaginationControls } from "@/components/shared/pagination-controls";
import { EmptyState } from "@/components/shared/empty-state";
import { useMyPayslips, type PayrollEntry } from "@/features/payroll/api";
import { useCurrentUser } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";

function formatCents(cents: number): string {
  return (cents / 100).toLocaleString("en-ET", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

interface PayslipLabels {
  title: string;
  description: string;
  amount: string;
  basicSalary: string;
  grossPay: string;
  incomeTax: string;
  employeePension: string;
  employerPension: string;
  otherDeductions: string;
  netPay: string;
  generatedBy: string;
  printSave: string;
}

function printPayslip(
  entry: PayrollEntry,
  employeeName: string,
  labels: PayslipLabels,
) {
  const w = window.open("", "_blank", "width=600,height=800");
  if (!w) return;

  const html = `<!DOCTYPE html>
<html><head><title>${labels.title}</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 600px; margin: 2rem auto; color: #1a1a1a; }
  h1 { font-size: 1.5rem; margin-bottom: 0.25rem; }
  .meta { color: #666; font-size: 0.85rem; margin-bottom: 1.5rem; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
  th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #e5e5e5; }
  th { font-size: 0.75rem; text-transform: uppercase; color: #666; background: #f9f9f9; }
  .amount { text-align: right; font-variant-numeric: tabular-nums; }
  .deduction { color: #dc2626; }
  .net-row { font-weight: 700; border-top: 2px solid #333; }
  .footer { margin-top: 2rem; font-size: 0.75rem; color: #999; text-align: center; }
  @media print { body { margin: 0; } .no-print { display: none; } }
</style>
</head><body>
  <h1>${labels.title}</h1>
  <div class="meta">${employeeName}</div>
  <table>
    <thead><tr><th>${labels.description}</th><th class="amount">${labels.amount}</th></tr></thead>
    <tbody>
      <tr><td>${labels.basicSalary}</td><td class="amount">${formatCents(entry.basic_salary_cents)}</td></tr>
      <tr><td>${labels.grossPay}</td><td class="amount">${formatCents(entry.gross_cents)}</td></tr>
      <tr><td>${labels.incomeTax}</td><td class="amount deduction">-${formatCents(entry.income_tax_cents)}</td></tr>
      <tr><td>${labels.employeePension}</td><td class="amount deduction">-${formatCents(entry.employee_pension_cents)}</td></tr>
      <tr><td>${labels.employerPension}</td><td class="amount">${formatCents(entry.employer_pension_cents)}</td></tr>
      ${entry.other_deductions_cents > 0 ? `<tr><td>${labels.otherDeductions}</td><td class="amount deduction">-${formatCents(entry.other_deductions_cents)}</td></tr>` : ""}
      <tr class="net-row"><td>${labels.netPay}</td><td class="amount">${formatCents(entry.net_cents)}</td></tr>
    </tbody>
  </table>
  <div class="footer">${labels.generatedBy}</div>
  <div class="no-print" style="text-align:center;margin-top:1rem">
    <button onclick="window.print()" style="padding:0.5rem 1.5rem;font-size:0.9rem;cursor:pointer;border:1px solid #ccc;border-radius:6px;background:#fff">
      ${labels.printSave}
    </button>
  </div>
</body></html>`;

  w.document.write(html);
  w.document.close();
}

export default function MyPayslipsPage() {
  const { t } = useT();
  const [page, setPage] = useState(1);
  const { data, isLoading } = useMyPayslips({ page });
  const { data: user } = useCurrentUser();

  const labels: PayslipLabels = {
    title: t("payroll_page.payslips_page.payslip_title"),
    description: t("payroll_page.payslips_page.description_col"),
    amount: t("payroll_page.payslips_page.amount_etb"),
    basicSalary: t("payroll_page.payslips_page.basic_salary"),
    grossPay: t("payroll_page.payslips_page.gross_pay"),
    incomeTax: t("payroll_page.payslips_page.income_tax"),
    employeePension: t("payroll_page.payslips_page.employee_pension_pct"),
    employerPension: t("payroll_page.payslips_page.employer_pension_pct"),
    otherDeductions: t("payroll_page.payslips_page.other_deductions"),
    netPay: t("payroll_page.payslips_page.net_pay"),
    generatedBy: t("payroll_page.payslips_page.generated_by"),
    printSave: t("payroll_page.payslips_page.print_save"),
  };

  return (
    <div className="space-y-6">
      <PageHeader
        title={t("payroll_page.payslips_page.title")}
        description={t("payroll_page.payslips_page.description")}
      />

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-40" />
          ))}
        </div>
      ) : !data?.data?.length ? (
        <EmptyState
          icon={FileText}
          title={t("payroll_page.payslips_page.no_payslips")}
          description={t("payroll_page.payslips_page.no_payslips_desc")}
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          {data.data.map((entry) => (
            <Card key={entry.public_id}>
              <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                  <CardTitle className="text-base">
                    {t("payroll_page.payslips_page.payslip_title")}
                  </CardTitle>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                      printPayslip(
                        entry,
                        user?.email ?? t("attendance.employee"),
                        labels,
                      )
                    }
                    // Every payslip card renders this button, so a bare
                    // "Download" would announce identically N times. Naming the
                    // period makes each one distinguishable in a screen
                    // reader's element list.
                    aria-label={[
                      t(
                        "payroll_page.payslips_page.download",
                        "Download payslip",
                      ),
                      entry.period_label,
                    ]
                      .filter(Boolean)
                      .join(" — ")}
                  >
                    <Download className="h-4 w-4" aria-hidden="true" />
                  </Button>
                </div>
              </CardHeader>
              <CardContent>
                <div className="space-y-2">
                  <Row
                    label={t("payroll_page.payslips_page.basic_salary")}
                    cents={entry.basic_salary_cents}
                  />
                  <Row
                    label={t("payroll_page.payslips_page.gross")}
                    cents={entry.gross_cents}
                  />
                  <div className="border-t pt-2">
                    <Row
                      label={t("payroll_page.payslips_page.income_tax")}
                      cents={entry.income_tax_cents}
                      deduction
                    />
                    <Row
                      label={t("payroll_page.payslips_page.employee_pension")}
                      cents={entry.employee_pension_cents}
                      deduction
                    />
                    {entry.other_deductions_cents > 0 && (
                      <Row
                        label={t("payroll_page.payslips_page.other_deductions")}
                        cents={entry.other_deductions_cents}
                        deduction
                      />
                    )}
                  </div>
                  <div className="border-t pt-2">
                    <div className="flex items-center justify-between">
                      <span className="text-sm font-semibold text-foreground">
                        {t("payroll_page.payslips_page.net_pay")}
                      </span>
                      <CurrencyDisplay
                        cents={entry.net_cents}
                        className="text-sm font-bold text-primary"
                      />
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <PaginationControls
        meta={data?.meta}
        onPageChange={setPage}
        disabled={isLoading}
      />
    </div>
  );
}

function Row({
  label,
  cents,
  deduction,
}: {
  label: string;
  cents: number;
  deduction?: boolean;
}) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-sm text-muted-foreground">{label}</span>
      <span
        className={`text-sm ${deduction ? "text-status-error" : "text-foreground"}`}
      >
        {deduction && "- "}
        <CurrencyDisplay cents={cents} />
      </span>
    </div>
  );
}
