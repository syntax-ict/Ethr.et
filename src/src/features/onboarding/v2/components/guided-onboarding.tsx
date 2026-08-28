"use client";

import { useState } from "react";
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
  const [step, setStep] = useState(0);
  const [done, setDone] = useState<Set<number>>(new Set());

  function complete(index: number) {
    setDone((prev) => new Set(prev).add(index));
    setStep((s) => Math.min(s + 1, STEPS.length - 1));
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
          onClick={() => setStep((s) => Math.max(s - 1, 0))}
          disabled={step === 0}
        >
          {t("common.back", "Back")}
        </Button>
        {step < STEPS.length - 1 && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setStep((s) => s + 1)}
          >
            {t("setup2.skip_step", "Skip for now")}
          </Button>
        )}
      </div>
    </div>
  );
}
