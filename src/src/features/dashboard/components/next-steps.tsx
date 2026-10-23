"use client";

import { useCallback, useMemo, useState } from "react";
import Link from "next/link";
import {
  ArrowRight,
  CalendarClock,
  CheckSquare,
  Fingerprint,
  type LucideIcon,
  ShieldAlert,
  UserX,
  Wallet,
  X,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { useManagerDashboard } from "@/features/dashboard/api";
import { useIntegritySummary } from "@/features/attendance/api";
import { usePayrollRuns } from "@/features/payroll/api";
import { useUnmatchedBadges } from "@/features/devices/api";
import { useExpiringContracts } from "@/features/employees/api";
import { useCurrentUser } from "@/features/auth/api";
import { usePermissions } from "@/lib/hooks/usePermissions";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import {
  type Dismissed,
  type NextStep,
  type NextStepId,
  nextSteps,
} from "../lib/next-steps";

// quiet-on-error: every source here has its own page with its own error state; a source that failed to load simply contributes no row.

const STORAGE_PREFIX = "ethr.next-steps.dismissed.";

function readDismissed(key: string): Dismissed {
  if (typeof window === "undefined") return {};
  try {
    const raw = window.localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as Dismissed) : {};
  } catch {
    return {};
  }
}

const ICON: Record<NextStepId, LucideIcon> = {
  integrity: ShieldAlert,
  payroll_failed: Wallet,
  payroll_approve: Wallet,
  approvals: CheckSquare,
  contracts_ending: CalendarClock,
  unmatched: Fingerprint,
  team_absent: UserX,
};

/**
 * "What needs me first", as at most three rows above the work queue.
 *
 * Every row is made from a count an existing endpoint returned for this
 * person, under the same gate its own page uses, and says why it is here
 * and what opening it does. A row can be put away; it returns only when the
 * number behind it changes (`lib/next-steps.ts`). The choice is kept in this
 * browser, per user: there is no preference store to put it in, and a
 * dismissal is not worth a schema.
 *
 * The absent integrity prompt that used to sit in the side column lives
 * here now, so the same flags are not announced twice on one page.
 */
export function NextSteps() {
  const { t } = useT();
  const { can, isSupervisor, isSuperAdmin } = usePermissions();
  const { data: user } = useCurrentUser();

  // Each query is gated on the ability its endpoint checks, so no role fires
  // a request the API would refuse.
  const approver = isSupervisor && !isSuperAdmin;
  const manager = useManagerDashboard(approver);
  const integrity = useIntegritySummary(can.viewAttendanceConflicts);
  const payroll = usePayrollRuns(undefined, { enabled: can.processPayroll });
  const canHoldPunches = can.viewDevices && can.manageAttendance;
  const unmatched = useUnmatchedBadges(canHoldPunches);
  // Only the count is read, so one row is enough.
  const contracts = useExpiringContracts(30, {
    enabled: can.manageEmployees,
    perPage: 1,
  });

  const storageKey = `${STORAGE_PREFIX}${user?.public_id ?? "anonymous"}`;
  const [dismissed, setDismissed] = useState<{ key: string; value: Dismissed }>(
    () => ({ key: storageKey, value: readDismissed(storageKey) }),
  );
  // The user arrives after the first render; re-read for their key once.
  const current =
    dismissed.key === storageKey ? dismissed.value : readDismissed(storageKey);

  const dismiss = useCallback(
    (step: NextStep) => {
      const next: Dismissed = {
        ...readDismissed(storageKey),
        [step.id]: step.signature,
      };
      try {
        window.localStorage.setItem(storageKey, JSON.stringify(next));
      } catch {
        // Private windows may refuse; the row still goes for this visit.
      }
      setDismissed({ key: storageKey, value: next });
    },
    [storageKey],
  );

  const steps = useMemo(
    () =>
      nextSteps(
        {
          approvals: approver ? manager.data?.pending_approvals : undefined,
          teamAbsent: approver
            ? manager.data?.team_attendance?.absent
            : undefined,
          integrityOpen: can.viewAttendanceConflicts
            ? integrity.data?.open
            : undefined,
          contractsEnding: can.manageEmployees
            ? contracts.data?.meta.total
            : undefined,
          unmatchedBadges: canHoldPunches ? unmatched.data?.length : undefined,
          payrollRun: can.processPayroll
            ? (payroll.data?.data[0] ?? null)
            : undefined,
        },
        current,
      ),
    [
      approver,
      manager.data,
      can.viewAttendanceConflicts,
      integrity.data,
      can.manageEmployees,
      contracts.data,
      canHoldPunches,
      unmatched.data,
      can.processPayroll,
      payroll.data,
      current,
    ],
  );

  if (steps.length === 0) return null;

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold">
          {t("dashboard.next_steps", "Next steps")}
        </CardTitle>
        <p className="text-xs text-muted-foreground">
          {t(
            "dashboard.next_steps_hint",
            "What needs you first, from today's figures.",
          )}
        </p>
      </CardHeader>
      <CardContent>
        <ul className="space-y-2">
          {steps.map((step) => (
            <NextStepRow
              key={step.id}
              step={step}
              onDismiss={() => dismiss(step)}
            />
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}

function NextStepRow({
  step,
  onDismiss,
}: {
  step: NextStep;
  onDismiss: () => void;
}) {
  const { t } = useT();
  const Icon = ICON[step.id];
  const { title, reason, action } = describe(step, t);

  return (
    <li className="flex items-center gap-3 rounded-xl border border-border/60 p-3.5">
      <span
        className={cn(
          "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
          step.tone === "warning"
            ? "bg-warning-soft text-warning-on-soft"
            : "bg-info-soft text-info-on-soft",
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
      <Button variant="outline" size="sm" className="h-8 shrink-0" asChild>
        <Link href={step.href}>
          {action}
          <ArrowRight className="ml-1 h-3 w-3" aria-hidden="true" />
        </Link>
      </Button>
      <button
        type="button"
        onClick={onDismiss}
        aria-label={`${t("dashboard.next_steps_dismiss", "Put away")}: ${title}`}
        className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        <X className="h-4 w-4" aria-hidden="true" />
      </button>
    </li>
  );
}

type Translate = (
  key: string,
  fallback?: string,
  replacements?: Record<string, string | number>,
) => string;

/** The three sentences a row carries: what, why now, and what opening it does. */
function describe(
  step: NextStep,
  t: Translate,
): { title: string; reason: string; action: string } {
  const count = step.count ?? 0;
  const period = step.period ?? "";
  switch (step.id) {
    case "integrity":
      return {
        title:
          count === 1
            ? t("integrity.prompt_one", "1 punch to review")
            : t("integrity.prompt", ":count punches to review", { count }),
        reason: t(
          "integrity.prompt_hint",
          "Accept or reject before payroll is run.",
        ),
        action: t("dashboard.next_steps.review", "Review"),
      };
    case "payroll_failed":
      return {
        title: t(
          "dashboard.next_steps.payroll_failed",
          "The :period payroll run failed",
          { period },
        ),
        reason: t(
          "dashboard.next_steps.payroll_failed_reason",
          "Open it to see what stopped it, then reprocess.",
        ),
        action: t("dashboard.next_steps.open_run", "Open run"),
      };
    case "payroll_approve":
      return {
        title: t(
          "dashboard.next_steps.payroll_approve",
          "The :period payroll run is waiting for approval",
          { period },
        ),
        reason: t(
          "dashboard.next_steps.payroll_approve_reason",
          "Calculated and not yet approved. Nothing is paid until it is.",
        ),
        action: t("dashboard.next_steps.open_run", "Open run"),
      };
    case "approvals":
      return {
        title:
          count === 1
            ? t(
                "dashboard.next_steps.approvals_one",
                "1 request is waiting for your decision",
              )
            : t(
                "dashboard.next_steps.approvals",
                ":count requests are waiting for your decision",
                { count },
              ),
        reason: t(
          "dashboard.next_steps.approvals_reason",
          "Leave, corrections and profile changes from the people you approve for.",
        ),
        action: t("dashboard.next_steps.decide", "Decide"),
      };
    case "contracts_ending":
      return {
        title:
          count === 1
            ? t(
                "dashboard.next_steps.contracts_ending_one",
                "1 contract ends within 30 days or has ended",
              )
            : t(
                "dashboard.next_steps.contracts_ending",
                ":count contracts end within 30 days or have ended",
                { count },
              ),
        reason: t(
          "dashboard.next_steps.contracts_ending_reason",
          "Each needs a renewal or an end on the person's Contracts tab.",
        ),
        action: t("dashboard.next_steps.review", "Review"),
      };
    case "unmatched":
      return {
        title:
          count === 1
            ? t(
                "dashboard.next_steps.unmatched_one",
                "1 badge punched with no employee behind it",
              )
            : t(
                "dashboard.next_steps.unmatched",
                ":count badges punched with no employee behind them",
                { count },
              ),
        reason: t(
          "dashboard.next_steps.unmatched_reason",
          "Their punches are held until you assign them to someone or dismiss them.",
        ),
        action: t("dashboard.next_steps.assign", "Assign"),
      };
    case "team_absent":
      return {
        title:
          count === 1
            ? t(
                "dashboard.next_steps.team_absent_one",
                "1 of your team is absent today",
              )
            : t(
                "dashboard.next_steps.team_absent",
                ":count of your team are absent today",
                { count },
              ),
        reason: t(
          "dashboard.next_steps.team_absent_reason",
          "As the devices and check-ins reported it so far today.",
        ),
        action: t("dashboard.next_steps.see_team", "See team"),
      };
  }
}
