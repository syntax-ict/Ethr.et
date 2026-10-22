/**
 * Which of today's facts deserve the dashboard's "Next steps" rows, and in
 * what order. Pure: the component feeds it what the existing endpoints
 * returned and what the person dismissed, and it answers with at most
 * `limit` steps. No row is made from a guess: a step exists only when a
 * number behind it is greater than zero or a run is in a state that needs a
 * hand.
 *
 * Order is by what blocks what. Open integrity flags and a stuck payroll run
 * hold up pay for everyone, so they come before requests waiting on one
 * approver, which come before a count that is information rather than work.
 */

export interface NextStepsInput {
  /** `pending_approvals` from GET /dashboard/manager, for an approver. */
  approvals?: {
    leave: number;
    correction: number;
    profile_update: number;
    overtime?: number;
    total: number;
  };
  /** `team_attendance.absent` from the same endpoint. */
  teamAbsent?: number;
  /** `open` from GET /attendance/integrity/summary, for a reviewer. */
  integrityOpen?: number;
  /** Badges with held punches from GET /devices/unmatched-punches. */
  unmatchedBadges?: number;
  /** The newest payroll run, for someone who may process payroll. */
  payrollRun?: {
    public_id: string;
    period_label: string;
    status: string;
  } | null;
}

export type NextStepId =
  | "integrity"
  | "payroll_failed"
  | "payroll_approve"
  | "approvals"
  | "unmatched"
  | "team_absent";

export interface NextStep {
  id: NextStepId;
  href: string;
  /**
   * What the step is about, in a form that changes when the state behind it
   * changes. A dismissal is remembered against this, so a step comes back
   * when there is something new (three requests became five), and stays
   * away while nothing moved.
   */
  signature: string;
  count?: number;
  period?: string;
  tone: "warning" | "info";
}

/** What was dismissed, keyed by step id, holding the signature at the time. */
export type Dismissed = Partial<Record<NextStepId, string>>;

export function nextSteps(
  input: NextStepsInput,
  dismissed: Dismissed = {},
  limit = 3,
): NextStep[] {
  const steps: NextStep[] = [];

  if (input.integrityOpen !== undefined && input.integrityOpen > 0) {
    steps.push({
      id: "integrity",
      href: "/attendance/integrity",
      signature: String(input.integrityOpen),
      count: input.integrityOpen,
      tone: "warning",
    });
  }

  const run = input.payrollRun;
  if (run && run.status === "failed") {
    steps.push({
      id: "payroll_failed",
      href: `/payroll/${run.public_id}`,
      signature: `${run.public_id}:${run.status}`,
      period: run.period_label,
      tone: "warning",
    });
  } else if (run && run.status === "completed") {
    // Calculated and not yet approved: the engine's "completed" is the
    // moment the figures wait for a signature.
    steps.push({
      id: "payroll_approve",
      href: `/payroll/${run.public_id}`,
      signature: `${run.public_id}:${run.status}`,
      period: run.period_label,
      tone: "warning",
    });
  }

  if (input.approvals !== undefined && input.approvals.total > 0) {
    steps.push({
      id: "approvals",
      href: "/approvals",
      signature: String(input.approvals.total),
      count: input.approvals.total,
      tone: "warning",
    });
  }

  if (input.unmatchedBadges !== undefined && input.unmatchedBadges > 0) {
    steps.push({
      id: "unmatched",
      href: "/devices",
      signature: String(input.unmatchedBadges),
      count: input.unmatchedBadges,
      tone: "info",
    });
  }

  if (input.teamAbsent !== undefined && input.teamAbsent > 0) {
    steps.push({
      id: "team_absent",
      href: "/attendance/team",
      signature: String(input.teamAbsent),
      count: input.teamAbsent,
      tone: "info",
    });
  }

  return steps
    .filter((step) => dismissed[step.id] !== step.signature)
    .slice(0, limit);
}
