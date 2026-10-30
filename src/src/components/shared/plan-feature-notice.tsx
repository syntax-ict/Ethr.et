"use client";

import Link from "next/link";
import { PauseCircle, Sparkles } from "lucide-react";
import { useFeatureOffReason } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";

/**
 * Says a feature is not in the organisation's plan, where the action would
 * otherwise be offered and then refused with a 403 (audit N66). Links to
 * Billing, where the plan can be changed.
 *
 * When ETHR switched the feature off rather than the plan leaving it out, it
 * says that instead and offers no plans: an upgrade does not undo a switch
 * (docs/FEATURE-CONTROL-CENTER-DESIGN.md §5).
 */
export function PlanFeatureNotice({
  feature,
  className,
}: {
  feature: string;
  className?: string;
}) {
  const { t } = useT();
  const reason = useFeatureOffReason(feature);
  const switchedOff = reason !== null && reason !== "plan";
  const Icon = switchedOff ? PauseCircle : Sparkles;

  return (
    <div
      role="note"
      className={
        "flex flex-wrap items-center gap-2 rounded-lg border border-status-info/30 bg-status-info/5 px-3 py-2 text-sm text-foreground " +
        (className ?? "")
      }
    >
      <Icon className="h-4 w-4 shrink-0 text-status-info" aria-hidden="true" />
      {reason === "platform_disabled" ? (
        <span>
          {t(
            "plan_feature.paused",
            "ETHR has switched this off for now. Your records are kept, and nothing needs doing on your side.",
          )}
        </span>
      ) : switchedOff ? (
        <span>
          {t(
            "plan_feature.unavailable",
            "This is not available to your organisation right now. ETHR support can tell you why.",
          )}
        </span>
      ) : (
        <>
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
        </>
      )}
    </div>
  );
}
