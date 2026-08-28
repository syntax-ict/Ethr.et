"use client";

import { useState } from "react";
import { Loader2, Plus, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { DualCalendarDateInput } from "@/components/shared/dual-calendar-date-input";
import { Label } from "@/components/ui/label";
import { QueryBoundary } from "@/components/patterns/QueryBoundary";
import { FormErrorSummary } from "@/components/patterns/FormErrorSummary";
import { SimpleTable } from "@/components/shared/simple-table";
import { useT } from "@/lib/i18n/useT";
import { centsToETB, etbToCents, formatETB } from "@/lib/utils/currency";
import { toast } from "sonner";
import { useReplaceTaxBrackets, useTaxBrackets, type TaxBracket } from "../api";

/**
 * A row being edited. Only the upper bound, rate and deduction are editable —
 * each band's lower bound is derived from the previous band's upper bound, so
 * the ladder can never be submitted with a gap or an overlap.
 */
interface BracketRow {
  /** Upper bound in ETB; empty means open-ended (only valid on the last row). */
  maxEtb: string;
  rate: string;
  deductionEtb: string;
}

function toRows(brackets: TaxBracket[]): BracketRow[] {
  return brackets.map((b) => ({
    maxEtb:
      b.max_amount_cents === null ? "" : String(centsToETB(b.max_amount_cents)),
    rate: String(b.rate),
    deductionEtb: String(centsToETB(b.deduction_cents)),
  }));
}

/** Lower bound of each row, in cents: 0, then previous max + 1. */
function minCentsFor(rows: BracketRow[], index: number): number {
  if (index === 0) return 0;

  const previousMax = Number(rows[index - 1].maxEtb);
  if (rows[index - 1].maxEtb === "" || Number.isNaN(previousMax)) return 0;

  return etbToCents(previousMax) + 1;
}

function apiDetail(err: unknown): string | undefined {
  return (err as { response?: { data?: { detail?: string } } }).response?.data
    ?.detail;
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * A grid cell that can carry its own validation message.
 *
 * `FormField` is the wrong shape inside a table: it renders a `<Label>` above
 * the control, and a data grid already labels its columns in the header. The
 * ARIA contract is the same though — `aria-invalid` plus an `aria-describedby`
 * pointing at a `role="alert"` message — so a screen-reader user is told which
 * cell is wrong and why, rather than hearing an unchanged number field.
 */
function CellInput({
  id,
  value,
  onChange,
  error,
  label,
  ...inputProps
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
  label: string;
} & Omit<
  React.ComponentProps<typeof Input>,
  "id" | "value" | "onChange" | "aria-label"
>) {
  const errorId = error ? `${id}-error` : undefined;

  return (
    <div className="space-y-1">
      <Input
        {...inputProps}
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        aria-label={label}
        aria-invalid={error ? true : undefined}
        aria-describedby={errorId}
        className="font-mono"
      />
      {error && (
        <p
          id={errorId}
          role="alert"
          className="text-xs font-medium text-destructive"
        >
          {error}
        </p>
      )}
    </div>
  );
}

/**
 * Per-row validation errors, keyed by row index then field.
 *
 * Not `useZodForm`/`useFieldArray`: the ladder is not a fixed set of named
 * fields. Each row's *lower* bound is derived from the row above rather than
 * entered, and the last row is forced open-ended, so the useful rules are
 * relationships between rows — monotonicity, and a rate that only ever rises.
 * Those are checked here in one pass and reported against the exact cell that
 * breaks them.
 */
type BracketErrors = Record<number, Partial<Record<keyof BracketRow, string>>>;

function validateRows(
  rows: BracketRow[],
  t: (key: string, fallback?: string) => string,
): { errors: BracketErrors; summary: string | null } {
  const errors: BracketErrors = {};
  const set = (i: number, field: keyof BracketRow, message: string) => {
    errors[i] ??= {};
    errors[i][field] ??= message;
  };

  rows.forEach((row, i) => {
    const isLast = i === rows.length - 1;

    const rate = Number(row.rate);
    if (row.rate === "" || Number.isNaN(rate)) {
      set(i, "rate", t("validation.required", "This field is required"));
    } else if (rate < 0 || rate > 100) {
      set(
        i,
        "rate",
        t("payroll_config.rate_range", "Rate must be between 0 and 100"),
      );
    }

    const deduction = Number(row.deductionEtb);
    if (row.deductionEtb === "" || Number.isNaN(deduction) || deduction < 0) {
      set(i, "deductionEtb", t("validation.amount", "Enter a valid amount"));
    }

    if (isLast) return;

    const max = Number(row.maxEtb);
    if (row.maxEtb === "" || Number.isNaN(max)) {
      set(i, "maxEtb", t("validation.amount", "Enter a valid amount"));
      return;
    }

    // A band that does not end above where it starts is either empty or
    // overlaps the one below it. Either way the calculator would silently pick
    // the wrong band for salaries in the gap — the kind of defect that only
    // shows up as slightly wrong tax on somebody's payslip.
    if (etbToCents(max) <= minCentsFor(rows, i)) {
      set(
        i,
        "maxEtb",
        t(
          "payroll_config.band_not_ascending",
          "Each band must end above where it starts",
        ),
      );
    }
  });

  const count = Object.keys(errors).length;
  return {
    errors,
    summary:
      count === 0
        ? null
        : t(
            "payroll_config.bands_invalid",
            "Check the highlighted bands before saving.",
          ),
  };
}

export function TaxBracketsCard() {
  const { t } = useT();
  const query = useTaxBrackets();
  const replaceBrackets = useReplaceTaxBrackets();

  const [rows, setRows] = useState<BracketRow[] | null>(null);
  const [effectiveFrom, setEffectiveFrom] = useState(today());
  const [rowErrors, setRowErrors] = useState<BracketErrors>({});
  const [summary, setSummary] = useState<string | null>(null);

  // Adjusting state during render avoids an extra effect commit — see
  // https://react.dev/learn/you-might-not-need-an-effect.
  const brackets = query.data?.data;
  const [prevBrackets, setPrevBrackets] = useState(brackets);
  if (brackets && brackets !== prevBrackets) {
    setPrevBrackets(brackets);
    setRows(toRows(brackets));
  }

  function updateRow(index: number, patch: Partial<BracketRow>) {
    setRows((p) =>
      p ? p.map((row, i) => (i === index ? { ...row, ...patch } : row)) : p,
    );

    // Clear the edited cell's error as soon as it is touched. Monotonicity is
    // a relationship between rows, so one edit can fix or break a neighbour —
    // the whole ladder is re-checked on the next submit rather than
    // re-validated on every keystroke, which would move errors around under
    // the user's cursor while they are still typing a number.
    setRowErrors((prev) => {
      const forRow = prev[index];
      if (!forRow) return prev;
      const next = { ...prev, [index]: { ...forRow } };
      for (const field of Object.keys(patch) as (keyof BracketRow)[]) {
        delete next[index][field];
      }
      if (Object.keys(next[index]).length === 0) delete next[index];
      return next;
    });
  }

  function addRow() {
    setRows((p) => {
      if (!p) return p;
      // The current last row becomes bounded so the new one can be open-ended.
      const bounded = p.map((row, i) =>
        i === p.length - 1 && row.maxEtb === "" ? { ...row, maxEtb: "0" } : row,
      );
      return [...bounded, { maxEtb: "", rate: "0", deductionEtb: "0" }];
    });
  }

  function removeRow(index: number) {
    setRows((p) => {
      if (!p || p.length <= 1) return p;
      const next = p.filter((_, i) => i !== index);
      // Whatever ends up last must be the open-ended band.
      return next.map((row, i) =>
        i === next.length - 1 ? { ...row, maxEtb: "" } : row,
      );
    });
  }

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!rows) return;

    const { errors, summary } = validateRows(rows, t);
    setRowErrors(errors);
    setSummary(summary);
    if (summary) return;

    const payload = rows.map((row, i) => ({
      min_amount_cents: minCentsFor(rows, i),
      max_amount_cents:
        row.maxEtb === "" ? null : etbToCents(Number(row.maxEtb)),
      rate: Number(row.rate),
      deduction_cents: etbToCents(Number(row.deductionEtb)),
    }));

    replaceBrackets.mutate(
      { effective_from: effectiveFrom, brackets: payload },
      {
        onSuccess: () =>
          toast.success(t("payroll_config.tax_saved", "Tax brackets updated")),
        onError: (err) =>
          toast.error(
            apiDetail(err) ??
              t(
                "payroll_config.tax_save_failed",
                "Could not save tax brackets",
              ),
          ),
      },
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">
          {t("payroll_config.tax_brackets", "Income tax brackets")}
        </CardTitle>
        <p className="mt-1 text-sm text-muted-foreground">
          {t(
            "payroll_config.tax_brackets_desc",
            "Monthly taxable income bands. Each band starts where the previous one ends; the last band is open-ended.",
          )}
        </p>
      </CardHeader>

      <CardContent>
        <QueryBoundary query={query} isEmpty={() => false}>
          {() =>
            rows && (
              <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <FormErrorSummary message={summary} />
                <div className="overflow-x-auto">
                  <div className="min-w-[640px]">
                    <SimpleTable
                      caption={t(
                        "payroll_config.tax_brackets",
                        "Income tax brackets",
                      )}
                      headers={[
                        t("payroll_config.from", "From"),
                        t("payroll_config.to", "To"),
                        t("payroll_config.rate_percent", "Rate (%)"),
                        t("payroll_config.deduction_etb", "Deduction (ETB)"),
                      ]}
                      rows={rows.map((row, i) => {
                        const isLast = i === rows.length - 1;

                        return {
                          key: String(i),
                          cells: [
                            <span
                              key="from"
                              className="whitespace-nowrap font-mono text-muted-foreground"
                            >
                              {formatETB(minCentsFor(rows, i))}
                            </span>,
                            isLast ? (
                              <span key="to" className="text-muted-foreground">
                                {t("payroll_config.and_above", "and above")}
                              </span>
                            ) : (
                              <CellInput
                                key="to"
                                id={`bracket-${i}-max`}
                                type="number"
                                min="0"
                                step="0.01"
                                value={row.maxEtb}
                                onChange={(v) => updateRow(i, { maxEtb: v })}
                                error={rowErrors[i]?.maxEtb}
                                label={t(
                                  "payroll_config.band_upper_bound",
                                  "Band upper bound",
                                )}
                              />
                            ),
                            <CellInput
                              key="rate"
                              id={`bracket-${i}-rate`}
                              type="number"
                              min="0"
                              max="100"
                              step="0.01"
                              value={row.rate}
                              onChange={(v) => updateRow(i, { rate: v })}
                              error={rowErrors[i]?.rate}
                              label={t(
                                "payroll_config.rate_percent",
                                "Rate (%)",
                              )}
                            />,
                            <CellInput
                              key="ded"
                              id={`bracket-${i}-ded`}
                              type="number"
                              min="0"
                              step="0.01"
                              value={row.deductionEtb}
                              onChange={(v) =>
                                updateRow(i, { deductionEtb: v })
                              }
                              error={rowErrors[i]?.deductionEtb}
                              label={t(
                                "payroll_config.deduction_etb",
                                "Deduction (ETB)",
                              )}
                            />,
                          ],
                          actions: (
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="text-destructive-on-soft hover:bg-destructive-soft"
                              onClick={() => removeRow(i)}
                              disabled={rows.length <= 1}
                              aria-label={t(
                                "payroll_config.remove_band",
                                "Remove band",
                              )}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          ),
                        };
                      })}
                    />
                  </div>
                </div>

                <div className="flex flex-wrap items-end justify-between gap-3">
                  <div>
                    <Label htmlFor="tax_effective_from">
                      {t("payroll_config.effective_from", "Effective from")}
                    </Label>
                    <DualCalendarDateInput
                      id="tax_effective_from"
                      value={effectiveFrom}
                      onChange={setEffectiveFrom}
                      required
                      className="mt-1"
                    />
                  </div>

                  <div className="flex gap-2">
                    <Button type="button" variant="outline" onClick={addRow}>
                      <Plus className="mr-2 h-4 w-4" />
                      {t("payroll_config.add_band", "Add band")}
                    </Button>
                    <Button type="submit" disabled={replaceBrackets.isPending}>
                      {replaceBrackets.isPending && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      )}
                      {t("common.save", "Save")}
                    </Button>
                  </div>
                </div>
              </form>
            )
          }
        </QueryBoundary>
      </CardContent>
    </Card>
  );
}
