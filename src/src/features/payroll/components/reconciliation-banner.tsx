"use client";

import Link from "next/link";
import { ClipboardCheck } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import type { RegisterReconciliation } from "../api";

/**
 * Where the run's attendance reconciliation stands, for an organisation that
 * has switched it on. In `required` mode the run cannot be approved while
 * anything is open or pending, so the banner says that is why. Nothing shows
 * when reconciliation is off, as it is by default.
 */
export function ReconciliationBanner({
  reconciliation,
}: {
  reconciliation: RegisterReconciliation;
}) {
  const { t } = useT();
  if (reconciliation.mode === "off") return null;

  const { blocking, open, pending } = reconciliation.totals;
  const required = reconciliation.mode === "required";
  const settled = blocking === 0;

  return (
    <section
      aria-label={t("payroll_reconciliation.title")}
      className={
        settled
          ? "rounded-lg border border-success-edge bg-success-soft p-4 text-sm text-success-on-soft"
          : "rounded-lg border border-warning-edge bg-warning-soft p-4 text-sm text-warning-on-soft"
      }
    >
      <div className="flex gap-2">
        <ClipboardCheck className="mt-0.5 h-4 w-4 shrink-0" />
        <div className="space-y-1">
          <p className="font-medium">
            {settled
              ? t("payroll_reconciliation.settled")
              : t("payroll_reconciliation.summary", undefined, {
                  open: String(open),
                  pending: String(pending),
                })}
          </p>
          {!settled && (
            <p>
              {required
                ? t("payroll_reconciliation.required_hint")
                : t("payroll_reconciliation.advisory_hint")}{" "}
              <Link
                href="/payroll?tab=reconcile"
                className="font-medium underline underline-offset-2"
              >
                {t("payroll_reconciliation.open_reconcile")}
              </Link>
            </p>
          )}
        </div>
      </div>
    </section>
  );
}
