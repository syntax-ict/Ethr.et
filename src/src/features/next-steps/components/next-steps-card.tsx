"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowRight, BellOff, ChevronDown, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { useCurrentUser } from "@/features/auth/api";
import { useOptionalCalendar } from "@/lib/calendar/calendar-context";
import { storedPeriodLabel } from "@/lib/calendar/stored-period-label";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import {
  type NextStep,
  useDismissStep,
  useNextSteps,
  useRestoreSteps,
} from "../api";
import {
  isProfileItem,
  PROFILE_ITEM_HREF,
  STEP_DEFS,
  stepKey,
} from "../registry";

// quiet-on-error: every step here has its own page with its own error state; a list that failed to load simply shows no card.

/** How many steps show before "Show more". */
const VISIBLE = 3;

/** How long a blocker is snoozed for. The API allows at most 7 days. */
const BLOCKER_SNOOZE_DAYS = 7;

/**
 * "What needs me, for the work I do here", from `GET /next-steps`.
 *
 * The list is built on the server from who the person is: an employee is
 * shown how to finish their own profile, HR the employee records that are
 * incomplete, an administrator the setup that is genuinely unfinished, and an
 * approver the requests waiting on them. This component only draws it; it
 * decides nothing about who sees what.
 *
 * A step can be put away and returns when the state behind it changes. A step
 * that stops someone working (a blocker) can only be snoozed, never put away
 * for good.
 */
export function NextStepsCard() {
  const { t } = useT();
  const { isSuperAdmin } = usePermissions();
  const { data: user } = useCurrentUser();
  // Not before the role is known: until /auth/me answers, a platform super
  // admin looks like anyone else.
  const { data } = useNextSteps(user !== undefined && !isSuperAdmin);
  const dismiss = useDismissStep();
  const restore = useRestoreSteps();
  const [expanded, setExpanded] = useState(false);
  // Only a person who put something away this visit is offered the way back.
  const [putAway, setPutAway] = useState(false);

  const steps = data?.steps ?? [];

  if (steps.length === 0 && !putAway) return null;

  const shown = expanded ? steps : steps.slice(0, VISIBLE);
  const hidden = steps.length - shown.length;

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold">
          {t("next_steps.title")}
        </CardTitle>
        <p className="text-xs text-muted-foreground">{t("next_steps.hint")}</p>
        {data?.payroll && <PayrollReadiness readiness={data.payroll} />}
      </CardHeader>
      <CardContent>
        {steps.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {t("next_steps.all_put_away")}
          </p>
        ) : (
          <ul className="space-y-2">
            {shown.map((step) => (
              <NextStepRow
                key={step.id}
                step={step}
                onPutAway={() => {
                  setPutAway(true);
                  dismiss.mutate({
                    step,
                    snoozeDays:
                      step.severity === "blocker"
                        ? BLOCKER_SNOOZE_DAYS
                        : undefined,
                  });
                }}
              />
            ))}
          </ul>
        )}

        {(hidden > 0 || expanded || putAway) && (
          <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1">
            {(hidden > 0 || (expanded && steps.length > VISIBLE)) && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-touch px-2 text-xs sm:h-8"
                aria-expanded={expanded}
                onClick={() => setExpanded((open) => !open)}
              >
                {expanded
                  ? t("next_steps.show_fewer")
                  : t("next_steps.show_more", undefined, { count: hidden })}
                <ChevronDown
                  className={cn(
                    "ml-1 h-3 w-3 transition-transform",
                    expanded && "rotate-180",
                  )}
                  aria-hidden="true"
                />
              </Button>
            )}
            {putAway && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="h-touch px-2 text-xs sm:h-8"
                disabled={restore.isPending}
                onClick={() =>
                  restore.mutate(undefined, {
                    onSuccess: () => setPutAway(false),
                  })
                }
              >
                {t("next_steps.restore")}
              </Button>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

const TONE: Record<NextStep["severity"], string> = {
  blocker: "bg-destructive-soft text-destructive-on-soft",
  warning: "bg-warning-soft text-warning-on-soft",
  info: "bg-info-soft text-info-on-soft",
};

function NextStepRow({
  step,
  onPutAway,
}: {
  step: NextStep;
  onPutAway: () => void;
}) {
  const { t, locale } = useT();
  const calendar = useOptionalCalendar()?.calendar ?? "gregorian";
  const def = STEP_DEFS[step.id];
  const Icon = def.icon;
  const blocker = step.severity === "blocker";

  // A step with a total is a checklist: how much is done, out of how many.
  const total = step.total ?? 0;
  const done = Math.max(total - step.count, 0);
  // A payroll row's subject is the run's stored English month; the reader
  // sees it as /payroll names it (ጥቅምት 2019 on the Ethiopian calendar).
  const period =
    step.subject && def.subject === "payroll_period"
      ? storedPeriodLabel(step.subject, calendar, locale)
      : (step.subject ?? "");
  const replacements = {
    count: step.count,
    period,
    done,
    total,
  };

  const title = t(
    stepKey(step.id, def.plural && step.count === 1 ? "title_one" : "title"),
    undefined,
    replacements,
  );
  const reason = t(stepKey(step.id, "reason"), undefined, replacements);
  const action = t(stepKey(step.id, "cta"));
  const hasProgress = step.total != null && step.total > 0;
  const items = (step.items ?? []).filter(isProfileItem);

  // On a phone the controls take a row of their own under the text: with the
  // action and the put-away button beside it, a 375 px screen left the title
  // one word per line (dashboard review, 2026-11-03). Anything tapped there is
  // 44 px tall (WCAG 2.5.8 comfortable size); from `sm` up the row is one line
  // and the controls keep their desktop size.
  return (
    <li className="rounded-xl border border-border/60 p-3.5">
      <div className="flex flex-wrap items-center gap-3 sm:flex-nowrap">
        <span
          className={cn(
            "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
            TONE[step.severity],
          )}
        >
          <Icon className="h-4 w-4" aria-hidden="true" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-medium text-foreground">
            {title}
          </span>
          <span className="block text-xs text-muted-foreground">{reason}</span>
        </span>
        <div
          data-testid="next-step-controls"
          className="flex basis-full items-center justify-between gap-2 pl-12 sm:basis-auto sm:justify-start sm:pl-0"
        >
          <Button
            variant="outline"
            size="sm"
            className="h-touch shrink-0 sm:h-8"
            asChild
          >
            <Link href={step.href}>
              {action}
              <ArrowRight className="ml-1 h-3 w-3" aria-hidden="true" />
            </Link>
          </Button>
          <button
            type="button"
            onClick={onPutAway}
            aria-label={`${t(blocker ? "next_steps.snooze" : "next_steps.put_away")}: ${title}`}
            title={t(blocker ? "next_steps.snooze" : "next_steps.put_away")}
            className="flex h-touch w-touch shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:h-8 sm:w-8"
          >
            {blocker ? (
              <BellOff className="h-4 w-4" aria-hidden="true" />
            ) : (
              <X className="h-4 w-4" aria-hidden="true" />
            )}
          </button>
        </div>
      </div>

      {hasProgress && (
        <div className="mt-3 space-y-2 sm:pl-12">
          <Progress
            value={Math.round((done / total) * 100)}
            label={title}
            className="h-1.5"
          />
          {items.length > 0 && (
            <ul className="flex flex-wrap gap-1.5">
              {items.map((item) => (
                <li key={item}>
                  <Link
                    href={PROFILE_ITEM_HREF[item]}
                    className="inline-flex min-h-touch items-center rounded-full border border-border px-3 text-sm text-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:min-h-0 sm:px-2.5 sm:py-1 sm:text-xs"
                  >
                    {t(`next_steps.profile_item.${item}`)}
                  </Link>
                </li>
              ))}
            </ul>
          )}
        </div>
      )}
    </li>
  );
}

/**
 * "Ready for first payroll": how many of the payroll checks pass, computed by
 * the server from what is stored now. Shown only to someone who can set them.
 */
function PayrollReadiness({
  readiness,
}: {
  readiness: { done: number; total: number; ready: boolean };
}) {
  const { t } = useT();
  const percent =
    readiness.total > 0
      ? Math.round((readiness.done / readiness.total) * 100)
      : 0;

  return (
    <div className="mt-3 space-y-1.5" data-testid="payroll-readiness">
      <p className="text-xs font-medium text-foreground">
        {readiness.ready
          ? t("next_steps.payroll_ready_done", "Ready for first payroll")
          : t(
              "next_steps.payroll_ready_progress",
              "Ready for first payroll: :done of :total",
              { done: readiness.done, total: readiness.total },
            )}
      </p>
      <Progress
        value={percent}
        className="h-1.5"
        aria-label={t(
          "next_steps.payroll_ready_label",
          "First payroll readiness",
        )}
      />
    </div>
  );
}
