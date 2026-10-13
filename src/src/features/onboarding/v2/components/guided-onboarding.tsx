"use client";

import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { Check } from "lucide-react";
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

// Numbered, not iconised: the four steps are a sequence, and a number says
// where you are in it. The sparkle and rocket icons said nothing a label
// did not, and read as decoration (2026-10-14).
const STEPS = [
  {
    key: "config",
    titleKey: "setup2.step_organisation",
    title: "Organisation",
  },
  { key: "migrate", titleKey: "setup2.step_people", title: "People" },
  { key: "access", titleKey: "setup2.step_signin", title: "Sign-in" },
  { key: "launch", titleKey: "setup2.step_launch", title: "Go live" },
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
      <nav aria-label={t("onboarding.setup_progress", "Setup progress")}>
        <ol className="flex items-center">
          {STEPS.map((s, i) => {
            const isDone = done.has(i);
            const isActive = i === step;
            const canJump = isDone || i <= step;
            const last = i === STEPS.length - 1;
            return (
              <li
                key={s.key}
                className={cn("flex items-center", !last && "flex-1")}
              >
                <button
                  type="button"
                  disabled={!canJump}
                  onClick={() => canJump && setStep(i)}
                  aria-current={isActive ? "step" : undefined}
                  className="flex items-center gap-2 rounded-md py-1 pr-1 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-default"
                >
                  <span
                    className={cn(
                      "flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold tabular-nums",
                      isDone
                        ? "border-primary bg-primary text-primary-foreground"
                        : isActive
                          ? "border-primary text-primary"
                          : "border-border text-muted-foreground",
                    )}
                  >
                    {isDone ? (
                      <Check className="h-3.5 w-3.5" aria-hidden="true" />
                    ) : (
                      i + 1
                    )}
                  </span>
                  <span
                    className={cn(
                      "hidden text-sm sm:inline",
                      isActive
                        ? "font-medium text-foreground"
                        : "text-muted-foreground",
                    )}
                  >
                    {t(s.titleKey, s.title)}
                  </span>
                </button>
                {!last && (
                  <span
                    aria-hidden="true"
                    className={cn(
                      "mx-3 h-px flex-1",
                      isDone ? "bg-primary" : "bg-border",
                    )}
                  />
                )}
              </li>
            );
          })}
        </ol>
        <div className="mt-3 flex items-center gap-3">
          <Progress
            value={pct}
            className="h-1 flex-1"
            label={t("onboarding.setup_progress", "Setup progress")}
          />
          <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
            {t("setup2.steps_done", ":done of :total done", {
              done: done.size,
              total: STEPS.length,
            })}
          </span>
        </div>
      </nav>

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
