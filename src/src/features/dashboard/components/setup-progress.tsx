"use client";

import Link from "next/link";
import { ArrowRight, ListChecks } from "lucide-react";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { useOnboardingStatus } from "@/features/onboarding/useOnboardingStatus";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";

/**
 * "Finish setting up" on the dashboard, from the same guided-setup progress
 * the sidebar shows. It used to guess from record counts - departments meant
 * two steps done, a branch counted as shifts - so the dashboard read 80%
 * beside a sidebar reading 0 of 4 (QA 2026-10-13).
 */
export function SetupProgress() {
  const { t } = useT();
  const { can } = usePermissions();
  const onboarding = useOnboardingStatus(can.manageSettings);

  if (!can.manageSettings || onboarding.isLoading || onboarding.isComplete) {
    return null;
  }

  return (
    <section
      aria-labelledby="setup-progress-heading"
      className="rounded-xl border border-border bg-card p-5 shadow-xs"
    >
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-muted text-foreground">
          <ListChecks className="h-5 w-5" aria-hidden="true" />
        </span>
        <div className="min-w-0 flex-1">
          <h2
            id="setup-progress-heading"
            className="text-sm font-semibold text-foreground"
          >
            {t("dashboard.complete_setup", "Complete Setup")}
          </h2>
          <p className="mt-0.5 text-sm text-muted-foreground">
            {t("sidebar.steps_completed", ":done of :total completed", {
              done: onboarding.completedSteps.length,
              total: onboarding.totalSteps,
            })}
          </p>
          <Progress
            value={onboarding.progress}
            className="mt-3 h-1.5 max-w-md"
            label={t("setup.progress", "Progress")}
          />
        </div>
        <Button
          size="sm"
          className="shrink-0 self-start sm:self-center"
          asChild
        >
          <Link href="/setup/guided">
            {t("dashboard.finish_onboarding", "Finish Setup")}
            <ArrowRight className="ml-1.5 h-3.5 w-3.5" aria-hidden="true" />
          </Link>
        </Button>
      </div>
    </section>
  );
}
