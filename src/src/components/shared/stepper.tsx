"use client";

import type { ReactNode } from "react";
import { Check, X } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

export type StepState = "complete" | "current" | "upcoming" | "error";

export interface Step {
  key: string;
  label: string;
  /** A line under the label: when it happened, or what happens next. */
  description?: ReactNode;
  state: StepState;
}

/**
 * Where a multi-step process stands: a payroll run is calculated, then
 * reviewed, then approved (FRONTEND_REDESIGN_PLAN.md, Phase 3). Steps read
 * across from the small breakpoint up and down a phone.
 *
 * Each step says its state in words for a screen reader, and the current one
 * carries `aria-current="step"`; colour and the check mark are for the eye.
 */
export function Stepper({
  steps,
  label,
  className,
}: {
  steps: Step[];
  /** What the steps are of: "Payroll run progress". */
  label: string;
  className?: string;
}) {
  const { t } = useT();
  const stateText: Record<StepState, string> = {
    complete: t("common.step_complete", "Done"),
    current: t("common.step_current", "Current step"),
    upcoming: t("common.step_upcoming", "Not started"),
    error: t("common.step_failed", "Failed"),
  };

  return (
    <ol
      aria-label={label}
      className={cn(
        "flex flex-col gap-3 sm:flex-row sm:items-start sm:gap-0",
        className,
      )}
    >
      {steps.map((step, i) => (
        <li
          key={step.key}
          aria-current={step.state === "current" ? "step" : undefined}
          className="flex min-w-0 flex-1 items-start gap-3 sm:flex-col sm:gap-2"
        >
          <div className="flex items-center sm:w-full">
            <span
              aria-hidden="true"
              className={cn(
                "flex h-7 w-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold",
                step.state === "complete" &&
                  "border-transparent bg-primary text-primary-foreground",
                step.state === "current" &&
                  "border-primary bg-primary-soft text-primary-on-soft",
                step.state === "upcoming" &&
                  "border-border bg-card text-muted-foreground",
                step.state === "error" &&
                  "border-transparent bg-destructive text-destructive-foreground",
              )}
            >
              {step.state === "complete" ? (
                <Check className="h-4 w-4" />
              ) : step.state === "error" ? (
                <X className="h-4 w-4" />
              ) : (
                i + 1
              )}
            </span>
            {i < steps.length - 1 && (
              <span
                aria-hidden="true"
                className={cn(
                  "mx-2 hidden h-px flex-1 sm:block",
                  step.state === "complete" ? "bg-primary" : "bg-border",
                )}
              />
            )}
          </div>
          <div className="min-w-0 sm:pr-4">
            <p
              className={cn(
                "text-sm font-medium",
                step.state === "upcoming"
                  ? "text-muted-foreground"
                  : "text-foreground",
                step.state === "error" && "text-destructive",
              )}
            >
              {step.label}
              <span className="sr-only">. {stateText[step.state]}</span>
            </p>
            {step.description && (
              <p className="mt-0.5 text-xs text-muted-foreground">
                {step.description}
              </p>
            )}
          </div>
        </li>
      ))}
    </ol>
  );
}
