"use client";

import { Loader2 } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import {
  usePayrollRunLog,
  type CalculationLog,
  type CalculationLogStep,
} from "../api";

interface Line {
  key: string;
  label: string;
  cents: number;
  /** "minus" for deductions; "total" for a subtotal row; "note" for information that is not added up. */
  kind?: "minus" | "total" | "note";
  detail?: string;
}

const num = (value: unknown): number =>
  typeof value === "number" && Number.isFinite(value) ? value : 0;

/**
 * How one payslip was calculated, read from the immutable calculation_log the
 * engine stored (Convention #11) — never recomputed here, so it shows what was
 * actually paid. The log existed for every entry, but nothing displayed it: an
 * accountant or employee asking "why is my tax this?" had no answer on screen.
 */
export function CalculationLogDialog({
  runId,
  entryId,
  employeeName,
  onOpenChange,
}: {
  runId: string;
  entryId: string | null;
  employeeName: string;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useT();
  const open = entryId !== null;
  const { data, isLoading, isError } = usePayrollRunLog(runId, open);
  const log = data?.entries?.find((e) => e.public_id === entryId)
    ?.calculation_log as CalculationLog | null | undefined;

  const lines = log ? toLines(log, t) : [];

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {t("payroll_detail_page.calc_title", "How this pay was calculated")}
          </DialogTitle>
          <DialogDescription>{employeeName}</DialogDescription>
        </DialogHeader>

        {isLoading && (
          <div className="flex justify-center py-8">
            <Loader2
              className="h-5 w-5 animate-spin text-muted-foreground"
              aria-hidden="true"
            />
          </div>
        )}

        {isError && (
          <p className="text-sm text-destructive">
            {t(
              "payroll_detail_page.calc_failed",
              "The calculation could not be loaded.",
            )}
          </p>
        )}

        {!isLoading && !isError && lines.length === 0 && (
          <p className="text-sm text-muted-foreground">
            {t(
              "payroll_detail_page.calc_missing",
              "This entry has no recorded calculation.",
            )}
          </p>
        )}

        {lines.length > 0 && (
          <dl className="divide-y rounded-lg border text-sm">
            {lines.map((line) => (
              <div
                key={line.key}
                className={cn(
                  "flex items-start justify-between gap-4 px-3 py-2",
                  line.kind === "total" && "bg-muted/40 font-semibold",
                )}
              >
                <dt className="min-w-0">
                  <span
                    className={cn(
                      line.kind === "note" && "text-muted-foreground",
                    )}
                  >
                    {line.label}
                  </span>
                  {line.detail && (
                    <span className="block text-xs text-muted-foreground">
                      {line.detail}
                    </span>
                  )}
                </dt>
                <dd
                  className={cn(
                    "shrink-0 tabular-nums",
                    line.kind === "note" && "text-muted-foreground",
                  )}
                >
                  {line.kind === "minus" && "− "}
                  <CurrencyDisplay cents={line.cents} />
                </dd>
              </div>
            ))}
          </dl>
        )}

        {log?.calculated_at && (
          <p className="text-xs text-muted-foreground">
            {t(
              "payroll_detail_page.calc_recorded",
              "Recorded when the run was processed; it does not change afterwards.",
            )}
          </p>
        )}
      </DialogContent>
    </Dialog>
  );
}

function toLines(
  log: CalculationLog,
  t: (
    key: string,
    fallback: string,
    params?: Record<string, string | number>,
  ) => string,
): Line[] {
  const step = (name: string): CalculationLogStep | undefined =>
    log.steps.find((s) => s.step === name);
  const lines: Line[] = [];

  const proration = step("proration");
  if (proration) {
    lines.push({
      key: "contract",
      label: t(
        "payroll_detail_page.calc_contract_basic",
        "Monthly basic salary",
      ),
      cents: num(proration.original_basic_cents),
    });
    const factor = num(proration.proration_factor);
    if (factor > 0 && factor < 1) {
      lines.push({
        key: "prorated",
        label: t(
          "payroll_detail_page.calc_prorated",
          "Basic for the days employed",
        ),
        detail: t("payroll_detail_page.calc_factor", ":pct% of the month", {
          pct: Math.round(factor * 1000) / 10,
        }),
        cents: num(proration.prorated_basic_cents),
      });
    }
  }

  const unpaid = step("unpaid_leave");
  if (unpaid && num(unpaid.deduction_cents) > 0) {
    lines.push({
      key: "unpaid",
      label: t("payroll_detail_page.calc_unpaid_leave", "Unpaid leave"),
      detail: t(
        "payroll_detail_page.calc_unpaid_detail",
        ":days days × daily rate",
        {
          days: num(unpaid.unpaid_leave_days),
        },
      ),
      cents: num(unpaid.deduction_cents),
      kind: "minus",
    });
  }

  const overtime = step("overtime");
  if (overtime && num(overtime.overtime_amount_cents) > 0) {
    lines.push({
      key: "overtime",
      label: t("payroll_detail_page.calc_overtime", "Overtime"),
      detail: t("payroll_detail_page.calc_overtime_detail", ":hours hours", {
        hours: Math.round((num(overtime.overtime_minutes) / 60) * 10) / 10,
      }),
      cents: num(overtime.overtime_amount_cents),
    });
  }

  const allowances = step("allowances");
  const items = Array.isArray(allowances?.items)
    ? (allowances.items as Array<Record<string, unknown>>)
    : [];
  items.forEach((item, i) => {
    lines.push({
      key: `allowance-${i}`,
      label: String(
        item.name ?? t("payroll_detail_page.calc_allowance", "Allowance"),
      ),
      detail:
        item.taxable === false
          ? t("payroll_detail_page.calc_not_taxable", "Not taxable")
          : undefined,
      cents: num(item.amount_cents),
    });
  });

  const gross = step("gross");
  if (gross) {
    lines.push({
      key: "gross",
      label: t("payroll_detail_page.calc_gross", "Gross pay"),
      cents: num(gross.gross_cents),
      kind: "total",
    });
  }

  const tax = step("income_tax");
  if (tax) {
    lines.push({
      key: "taxable",
      label: t("payroll_detail_page.calc_taxable", "Taxable income"),
      cents: num(tax.taxable_amount_cents),
      kind: "note",
    });
    lines.push({
      key: "tax",
      label: t("payroll_detail_page.calc_income_tax", "Income tax"),
      detail: ruleReference(tax.rule),
      cents: num(tax.income_tax_cents),
      kind: "minus",
    });
  }

  // The rate is the one this entry was calculated with, from its own log —
  // an entry from before rates were logged shows none rather than a guess.
  const pension = step("pension");
  const employeeRate = rate(pension?.employee_rate);
  const employerRate = rate(pension?.employer_rate);
  const exempt = pension?.exempt === true;
  if (pension) {
    lines.push({
      key: "pension",
      label: exempt
        ? t("payroll_detail_page.calc_pension_exempt", "Pension: not covered")
        : employeeRate
          ? t(
              "payroll_detail_page.calc_employee_pension",
              "Pension (employee :rate)",
              { rate: employeeRate },
            )
          : t(
              "payroll_detail_page.calc_employee_pension_plain",
              "Pension (employee)",
            ),
      detail: exempt
        ? pension.exempt_reason === "min_service"
          ? t(
              "payroll_detail_page.calc_exempt_min_service",
              "Not yet in service long enough under the rule",
            )
          : pension.exempt_reason === "ethiopian_origin_unconfirmed"
            ? t(
                "payroll_detail_page.calc_exempt_ethiopian_origin",
                "Foreign national of Ethiopian origin: coverage not yet confirmed",
              )
            : t(
                "payroll_detail_page.calc_exempt_non_national",
                "Not an Ethiopian national",
              )
        : ruleReference(pension.rule),
      cents: num(pension.employee_pension_cents),
      kind: "minus",
    });
  }

  const loan = step("loan_deductions");
  if (loan && num(loan.loan_deduction_cents) > 0) {
    lines.push({
      key: "loan",
      label: t("payroll_detail_page.calc_loan", "Loan repayment"),
      cents: num(loan.loan_deduction_cents),
      kind: "minus",
    });
  }

  const costSharing = step("cost_sharing");
  if (costSharing && num(costSharing.cost_sharing_cents) > 0) {
    lines.push({
      key: "cost-sharing",
      label: t("payroll_detail_page.calc_cost_sharing", "Cost sharing"),
      cents: num(costSharing.cost_sharing_cents),
      kind: "minus",
    });
  }

  const net = step("net_calculation");
  if (net) {
    lines.push({
      key: "net",
      label: t("payroll_detail_page.calc_net", "Net pay"),
      cents: num(net.net_cents),
      kind: "total",
    });
  }

  if (pension && !exempt) {
    lines.push({
      key: "employer",
      label: employerRate
        ? t(
            "payroll_detail_page.calc_employer_pension",
            "Employer pension (:rate), paid on top",
            { rate: employerRate },
          )
        : t(
            "payroll_detail_page.calc_employer_pension_plain",
            "Employer pension, paid on top",
          ),
      cents: num(pension.employer_pension_cents),
      kind: "note",
    });
  }

  return lines;
}

/** "7%" from a logged rate, or undefined when the entry did not record one. */
function rate(value: unknown): string | undefined {
  const n = typeof value === "number" ? value : Number(value);
  return value === undefined || value === null || Number.isNaN(n)
    ? undefined
    : `${n}%`;
}

/** The law a logged rule names, e.g. "Proclamation No. 1268/2022, Art. 10". */
function ruleReference(rule: unknown): string | undefined {
  if (rule && typeof rule === "object" && "legal_reference" in rule) {
    const ref = (rule as { legal_reference?: unknown }).legal_reference;
    return typeof ref === "string" ? ref : undefined;
  }
  return undefined;
}
