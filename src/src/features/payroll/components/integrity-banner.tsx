"use client";

import Link from "next/link";
import { ShieldAlert } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import type { PayrollIntegrity } from "../api";

/**
 * People in this run whose punches in the period are still waiting for a
 * decision in the integrity queue at a serious level. Pay was not changed
 * for any of it; deciding them first means the run is approved on hours a
 * person has looked at.
 */
export function IntegrityBanner({
  integrity,
}: {
  integrity: PayrollIntegrity;
}) {
  const { t } = useT();
  if (integrity.count === 0) return null;

  return (
    <section
      aria-label={t("payroll_integrity.title", "Punches waiting for review")}
      className="rounded-lg border border-warning-edge bg-warning-soft p-4 text-sm text-warning-on-soft"
    >
      <div className="flex gap-2">
        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
        <div className="space-y-2">
          <p className="font-medium">
            {t(
              "payroll_integrity.summary",
              ":count employee(s) have :flags serious finding(s) in this period still waiting for review.",
              { count: integrity.count, flags: integrity.flags },
            )}
          </p>
          <p>
            {t(
              "payroll_integrity.hint",
              "Pay was not changed for them. Decide them in Integrity before approving, so the run rests on hours someone has checked.",
            )}{" "}
            <Link
              href="/attendance/integrity"
              className="font-medium underline underline-offset-2"
            >
              {t("payroll_integrity.open", "Open Integrity")}
            </Link>
          </p>
          <ul className="list-inside list-disc space-y-0.5">
            {integrity.employees.map((e, i) => (
              <li key={`${e.employee_public_id ?? i}`}>
                <span className="font-medium">{e.employee_name}</span>
                {": "}
                {t("payroll_integrity.employee", ":flags finding(s)", {
                  flags: e.flags,
                })}
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}
