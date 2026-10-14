"use client";

import Link from "next/link";
import { AlertTriangle } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import type { PensionExemptions } from "../api";

const REASONS = [
  "non_national",
  "ethiopian_origin_unconfirmed",
  "min_service",
] as const;

/**
 * Everyone this run exempted from pension, and why, before anyone approves
 * it. An exemption applied in error leaves the employer owing the
 * contributions plus 5 % a month, so each name links to the profile where
 * the nationality is set.
 */
export function PensionExemptionsBanner({
  exemptions,
}: {
  exemptions: PensionExemptions;
}) {
  const { t } = useT();
  if (exemptions.count === 0) return null;

  const reasonLabel = (reason: string) =>
    ({
      non_national: t("pension_exemptions.reason_non_national"),
      ethiopian_origin_unconfirmed: t(
        "pension_exemptions.reason_ethiopian_origin",
      ),
      min_service: t("pension_exemptions.reason_min_service"),
    })[reason] ?? reason;

  const breakdown = REASONS.filter((r) => (exemptions.by_reason[r] ?? 0) > 0)
    .map((r) => `${exemptions.by_reason[r]} ${reasonLabel(r)}`)
    .join(", ");

  return (
    <section
      aria-label={t("pension_exemptions.title")}
      className="rounded-lg border border-warning-edge bg-warning-soft p-4 text-sm text-warning-on-soft"
    >
      <div className="flex gap-2">
        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
        <div className="space-y-2">
          <p className="font-medium">
            {t("pension_exemptions.summary", undefined, {
              count: exemptions.count,
              breakdown,
            })}
          </p>
          <p>{t("pension_exemptions.hint")}</p>
          <ul className="list-inside list-disc space-y-0.5">
            {exemptions.employees.map((e, i) => (
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
                )}{" "}
                — {reasonLabel(e.reason)}
              </li>
            ))}
          </ul>
        </div>
      </div>
    </section>
  );
}
