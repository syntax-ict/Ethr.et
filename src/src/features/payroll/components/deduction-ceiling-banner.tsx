"use client";

import Link from "next/link";
import { Scale } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { CurrencyDisplay } from "@/components/shared/currency-display";
import type { DeductionCeiling } from "../api";

/**
 * Payslips whose lateness, loan and cost-sharing deductions together pass a
 * third of basic pay, the ceiling Proclamation 1156/2019 Art. 59 sets. Lateness
 * is already held to what loans and cost sharing leave; this lists what is
 * over anyway (a loan above the third, or cost sharing on a larger gross) so
 * it is seen before approval. Nothing is changed.
 */
export function DeductionCeilingBanner({
  ceiling,
}: {
  ceiling: DeductionCeiling;
}) {
  const { t } = useT();
  if (ceiling.count === 0) return null;

  return (
    <section
      aria-label={t("payroll_deduction_ceiling.title")}
      className="rounded-lg border border-warning-edge bg-warning-soft p-4 text-sm text-warning-on-soft"
    >
      <div className="flex gap-2">
        <Scale className="mt-0.5 h-4 w-4 shrink-0" />
        <div className="space-y-2">
          <p className="font-medium">
            {t("payroll_deduction_ceiling.summary", undefined, {
              count: String(ceiling.count),
            })}
          </p>
          <p>{t("payroll_deduction_ceiling.hint")}</p>
          <ul className="list-inside list-disc space-y-0.5">
            {ceiling.employees.map((e, i) => (
              <li key={`${e.employee_public_id ?? i}`}>
                {e.employee_public_id ? (
                  <Link
                    href={`/employees/${e.employee_public_id}`}
                    className="font-medium underline underline-offset-2"
                  >
                    {e.employee_name}
                  </Link>
                ) : (
                  <span className="font-medium">{e.employee_name}</span>
                )}
                {": "}
                <CurrencyDisplay cents={e.deducted_cents} />
                {" / "}
                <CurrencyDisplay cents={e.ceiling_cents} />
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}
