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
import { downloadPayslip, useMyPayslips } from "@/features/payroll/api";
import { useT } from "@/lib/i18n/useT";
import { toast } from "sonner";

export default function MyPayslipsPage() {
  const { t } = useT();
  const [page, setPage] = useState(1);
  const { data, isLoading } = useMyPayslips({ page });

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
                    {entry.period_label ??
                      t("payroll_page.payslips_page.payslip_title")}
                  </CardTitle>
                  <Button
                    variant="ghost"
                    size="sm"
                    // The server's PDF: allowance and deduction lines, the
                    // organisation's name, and Amharic that prints. This
                    // opened a bare HTML page built in the browser, and the
                    // PDF endpoint went unused (audit N79).
                    onClick={() =>
                      downloadPayslip(entry.public_id).catch(() =>
                        toast.error(
                          t(
                            "payroll_page.payslips_page.download_failed",
                            "Couldn't download the payslip",
                          ),
                        ),
                      )
                    }
                    // Every payslip card renders this button, so a bare
                    // "Download" announces identically N times; the run's
                    // period tells them apart.
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
