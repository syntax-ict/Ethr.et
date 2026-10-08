"use client";

import Link from "next/link";
import { Sparkles } from "lucide-react";
import { useT } from "@/lib/i18n/useT";

/**
 * Says a feature is not in the organisation's plan, where the action would
 * otherwise be offered and then refused with a 403 (audit N66). Links to
 * Billing, where the plan can be changed.
 */
export function PlanFeatureNotice({ className }: { className?: string }) {
  const { t } = useT();

  return (
    <div
      role="note"
      className={
        "flex flex-wrap items-center gap-2 rounded-lg border border-status-info/30 bg-status-info/5 px-3 py-2 text-sm text-foreground " +
        (className ?? "")
      }
    >
      <Sparkles
        className="h-4 w-4 shrink-0 text-status-info"
        aria-hidden="true"
      />
      <span>
        {t(
          "plan_feature.not_in_plan",
          "This is not included in your organisation's plan.",
        )}
      </span>
      <Link
        href="/billing"
        className="font-medium text-primary underline-offset-4 hover:underline"
      >
        {t("plan_feature.see_plans", "See plans")}
      </Link>
    </div>
  );
}
