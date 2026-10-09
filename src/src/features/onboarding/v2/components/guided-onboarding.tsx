"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { Sparkles, Users, KeyRound, Rocket, Check } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Progress } from "@/components/ui/progress";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import { IndustryConfigStep } from "./industry-config-step";
import { MigrationStep } from "./migration-step";
import { AccessStep } from "./access-step";
import { ReadinessStep } from "./readiness-step";
import {
  GUIDED_STEP_IDS,
  useOnboardingStatus,
} from "@/features/onboarding/useOnboardingStatus";

const STEPS = [
  {
    key: "config",
    icon: Sparkles,
    titleKey: "setup2.step_config",
    title: "Configure",
  },
  {
    key: "migrate",
    icon: Users,
    titleKey: "setup2.step_migrate",
    title: "Workforce",
  },
  {
    key: "access",
    icon: KeyRound,
    titleKey: "setup2.step_access",
    title: "Access",
  },
  {
    key: "launch",
    icon: Rocket,
    titleKey: "setup2.step_launch",
    title: "Go live",
  },
] as const;

export function GuidedOnboarding() {
  const { t } = useT();
  const router = useRouter();
  const queryClient = useQueryClient();
  const status = useOnboardingStatus();
  // Steps finished in an earlier visit come back ticked, and the page opens on
  // the first one left. The ticks lived in component state alone, so a reload
  // showed a half-configured organisation an empty setup (2026-10-09).
  const fromServer = GUIDED_STEP_IDS.flatMap((id, i) =>
    status.completedSteps.includes(id) ? [i] : [],
  );
  const [doneHere, setDoneHere] = useState<number[]>([]);
  const done = new Set([...fromServer, ...doneHere]);

  // Null until the user moves; until then, the first step not yet done.
  const [chosen, setChosen] = useState<number | null>(null);
  const firstOpen = GUIDED_STEP_IDS.findIndex(
    (id) => !status.completedSteps.includes(id),
  );
  const step =
    chosen ?? (firstOpen === -1 ? STEPS.length - 1 : Math.max(firstOpen, 0));
  const setStep = (next: number) =>
    setChosen(Math.min(Math.max(next, 0), STEPS.length - 1));

  function complete(index: number) {
    setDoneHere((prev) => [...prev, index]);
    setStep(index + 1);
    // The sidebar's "N of 4" reads the same record.
    void queryClient.invalidateQueries({
      queryKey: ["onboarding", "progress"],
    });
  }

  const pct = Math.round((done.size / STEPS.length) * 100);

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="space-y-3">
        <Progress
          value={pct}
          className="h-1.5"
          label={t("onboarding.setup_progress", "Setup progress")}
        />
        <div className="flex justify-between">
          {STEPS.map((s, i) => {
            const Icon = s.icon;
            const isDone = done.has(i);
            const isActive = i === step;
            const canJump = isDone || i <= step;
            return (
              <button
                key={s.key}
                type="button"
                disabled={!canJump}
                onClick={() => canJump && setStep(i)}
                className="flex flex-col items-center gap-1 disabled:cursor-default"
              >
                <div
                  className={cn(
                    "flex h-8 w-8 items-center justify-center rounded-full border-2 transition-colors",
                    isDone
                      ? "border-primary bg-primary text-primary-foreground"
                      : isActive
                        ? "border-primary text-primary"
                        : "border-muted text-muted-foreground",
                  )}
                >
                  {isDone ? (
                    <Check className="h-4 w-4" />
                  ) : (
                    <Icon className="h-4 w-4" />
                  )}
                </div>
                <span
                  className={cn(
                    "hidden text-[11px] sm:block",
                    isActive
                      ? "font-medium text-foreground"
                      : "text-muted-foreground",
                  )}
                >
                  {t(s.titleKey, s.title)}
                </span>
              </button>
            );
          })}
        </div>
      </div>

      <Card>
        <CardContent className="p-6 sm:p-8">
          {step === 0 && <IndustryConfigStep onApplied={() => complete(0)} />}
          {step === 1 && <MigrationStep onCommitted={() => complete(1)} />}
          {step === 2 && <AccessStep onSaved={() => complete(2)} />}
          {step === 3 && (
            <ReadinessStep onLive={() => router.push("/dashboard")} />
          )}
        </CardContent>
      </Card>

      <div className="flex items-center justify-between">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setStep(step - 1)}
          disabled={step === 0}
        >
          {t("common.back", "Back")}
        </Button>
        {step < STEPS.length - 1 && (
          <Button variant="ghost" size="sm" onClick={() => setStep(step + 1)}>
            {t("setup2.skip_step", "Skip for now")}
          </Button>
        )}
      </div>
    </div>
  );
}
