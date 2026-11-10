"use client";

import { useState } from "react";
import { ArrowLeft, Eye, Loader2, Scale } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { EmptyState } from "@/components/shared/empty-state";
import { apiErrorDetail } from "@/lib/api/error-message";
import { formatETB } from "@/lib/utils/currency";
import { useT } from "@/lib/i18n/useT";
import {
  type PayrollPreview,
  usePayrollPreview,
  useProcessPayroll,
} from "../api";

/** Each total the preview shows, summed over its payslips. */
function totals(preview: PayrollPreview) {
  return preview.payslips.reduce(
    (sum, p) => ({
      gross: sum.gross + p.gross_cents,
      tax: sum.tax + p.income_tax_cents,
      pension: sum.pension + p.employee_pension_cents,
      employer: sum.employer + p.employer_pension_cents,
      net: sum.net + p.net_cents,
    }),
    { gross: 0, tax: 0, pension: 0, employer: 0, net: 0 },
  );
}

/** Rates arrive as percentages (7, 11), as the law states them. */
function percent(rate: number): string {
  return `${Math.round(rate * 100) / 100}%`;
}

/**
 * Run Payroll, in two steps: choose the period and see a dry run of it (the
 * same engine, writing nothing, with the law and published rules every figure
 * was worked out under), then run it. Running is the existing
 * POST /payroll/process; approving the run it makes is still the final step.
 */
export function RunPayrollDialog({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useT();
  const [periodStart, setPeriodStart] = useState("");
  const [periodEnd, setPeriodEnd] = useState("");
  const [checked, setChecked] = useState(false);
  const preview = usePayrollPreview();
  const processPayroll = useProcessPayroll();
  const shown = preview.data;

  function close(next: boolean) {
    onOpenChange(next);
    if (!next) {
      setPeriodStart("");
      setPeriodEnd("");
      setChecked(false);
      preview.reset();
    }
  }

  function runPreview(e: React.FormEvent) {
    e.preventDefault();
    setChecked(false);
    preview.mutate(
      { period_start: periodStart, period_end: periodEnd },
      {
        onError: (err: unknown) =>
          toast.error(
            apiErrorDetail(err) ||
              t("payroll.preview_failed", "Could not preview this period"),
          ),
      },
    );
  }

  function run() {
    processPayroll.mutate(
      {
        period_start: periodStart,
        period_end: periodEnd,
        idempotency_key: crypto.randomUUID(),
      },
      {
        onSuccess: () => {
          toast.success(
            t("payroll.processing_started", "Payroll processing started"),
          );
          close(false);
        },
        onError: (err: unknown) => {
          toast.error(
            apiErrorDetail(err) ||
              t("payroll.process_failed", "Failed to process payroll"),
          );
        },
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className={shown ? "max-w-3xl" : undefined}>
        <DialogHeader>
          <DialogTitle>{t("payroll.run", "Run Payroll")}</DialogTitle>
          <DialogDescription>
            {shown
              ? t(
                  "payroll.preview_description",
                  "A dry run: nothing is saved yet. Check the figures, then run payroll.",
                )
              : t(
                  "payroll.preview_intro",
                  "Choose the period. You will see every payslip before anything is saved.",
                )}
          </DialogDescription>
        </DialogHeader>

        {!shown ? (
          <form onSubmit={runPreview} className="space-y-4">
            <div>
              <Label htmlFor="period_start">
                {t("payroll.period_start", "Period Start")}
              </Label>
              <DualCalendarDateInput
                id="period_start"
                value={periodStart}
                onChange={setPeriodStart}
                required
                className="mt-1"
              />
            </div>
            <div>
              <Label htmlFor="period_end">
                {t("payroll.period_end", "Period End")}
              </Label>
              <DualCalendarDateInput
                id="period_end"
                value={periodEnd}
                onChange={setPeriodEnd}
                required
                className="mt-1"
              />
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => close(false)}
              >
                {t("common.cancel", "Cancel")}
              </Button>
              <Button
                type="submit"
                disabled={!periodStart || !periodEnd || preview.isPending}
              >
                {preview.isPending ? (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                ) : (
                  <Eye className="mr-2 h-4 w-4" />
                )}
                {t("payroll.preview", "Preview payslips")}
              </Button>
            </DialogFooter>
          </form>
        ) : (
          <PreviewBody
            preview={shown}
            checked={checked}
            onCheckedChange={setChecked}
            running={processPayroll.isPending}
            onBack={() => preview.reset()}
            onRun={run}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

function PreviewBody({
  preview,
  checked,
  onCheckedChange,
  running,
  onBack,
  onRun,
}: {
  preview: PayrollPreview;
  checked: boolean;
  onCheckedChange: (checked: boolean) => void;
  running: boolean;
  onBack: () => void;
  onRun: () => void;
}) {
  const { t } = useT();
  const sum = totals(preview);
  const versions = Array.from(
    new Set(preview.rules.map((r) => r.version).filter(Boolean)),
  ).join(", ");
  const empty = preview.payslips.length === 0;

  return (
    <div className="space-y-4">
      {empty ? (
        <EmptyState
          title={t("payroll.preview_empty", "No one to pay in this period")}
          description={t(
            "payroll.preview_empty_hint",
            "Only active employees with a salary for the period are paid.",
          )}
        />
      ) : (
        <>
          <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
            {(
              [
                [
                  t("payroll.preview_people", "People"),
                  String(preview.payslips.length),
                ],
                [t("payroll.gross", "Gross"), formatETB(sum.gross)],
                [t("payroll.income_tax", "Income tax"), formatETB(sum.tax)],
                [
                  t("payroll.pension_total", "Pension (employee + employer)"),
                  formatETB(sum.pension + sum.employer),
                ],
                [t("payroll.net", "Net pay"), formatETB(sum.net)],
              ] as const
            ).map(([label, value]) => (
              <div key={label} className="rounded-md border p-2">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="font-mono font-medium">{value}</dd>
              </div>
            ))}
          </dl>

          <div className="max-h-72 overflow-auto rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>{t("payroll.employee", "Employee")}</TableHead>
                  <TableHead className="text-right">
                    {t("payroll.gross", "Gross")}
                  </TableHead>
                  <TableHead className="text-right">
                    {t("payroll.income_tax", "Income tax")}
                  </TableHead>
                  <TableHead className="text-right">
                    {t("payroll.pension", "Pension")}
                  </TableHead>
                  <TableHead className="text-right">
                    {t("payroll.net", "Net pay")}
                  </TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {preview.payslips.map((p) => (
                  <TableRow key={p.employee.public_id}>
                    <TableCell>
                      <span className="font-medium">{p.employee.name}</span>
                      {p.pension_exempt_reason && (
                        <span className="block text-xs text-muted-foreground">
                          {t(
                            "payroll.preview_pension_exempt",
                            "No pension: :reason",
                            {
                              reason: p.pension_exempt_reason.replaceAll(
                                "_",
                                " ",
                              ),
                            },
                          )}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="text-right font-mono">
                      {formatETB(p.gross_cents)}
                    </TableCell>
                    <TableCell className="text-right font-mono">
                      {formatETB(p.income_tax_cents)}
                    </TableCell>
                    <TableCell className="text-right font-mono">
                      {formatETB(p.employee_pension_cents)}
                    </TableCell>
                    <TableCell className="text-right font-mono">
                      {formatETB(p.net_cents)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </>
      )}

      <section
        aria-labelledby="preview-rules"
        className="space-y-1 rounded-md bg-muted/50 p-3 text-xs text-muted-foreground"
      >
        <h3
          id="preview-rules"
          className="flex items-center gap-1.5 text-sm font-medium text-foreground"
        >
          <Scale className="h-3.5 w-3.5" aria-hidden="true" />
          {t("payroll.preview_rules", "Worked out under")}
        </h3>
        <p>
          {t("payroll.preview_tax_rule", "Income tax: :reference", {
            reference:
              preview.income_tax.legal_reference ??
              t("payroll.preview_rule_unknown", "the rates in force"),
          })}
        </p>
        <p>
          {t(
            "payroll.preview_pension_rule",
            "Pension (:scheme): employee :employee, employer :employer · :reference",
            {
              scheme: preview.pension.scheme,
              employee: percent(preview.pension.employee_rate),
              employer: percent(preview.pension.employer_rate),
              reference: preview.pension.legal_reference,
            },
          )}
        </p>
        {versions && (
          <p>
            {t("payroll.preview_versions", "Statutory rules version :version", {
              version: versions,
            })}
          </p>
        )}
      </section>

      {!empty && (
        <div className="flex items-start gap-2">
          <Checkbox
            id="preview-checked"
            checked={checked}
            onCheckedChange={(v) => onCheckedChange(v === true)}
          />
          <Label htmlFor="preview-checked" className="text-sm font-normal">
            {t(
              "payroll.preview_confirm",
              "I have checked these figures. Run payroll for this period.",
            )}
          </Label>
        </div>
      )}

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onBack}>
          <ArrowLeft className="mr-2 h-4 w-4" />
          {t("payroll.preview_back", "Change period")}
        </Button>
        <Button
          type="button"
          onClick={onRun}
          disabled={empty || !checked || running}
        >
          {running && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
          {t("payroll.process", "Process Payroll")}
        </Button>
      </DialogFooter>
    </div>
  );
}
