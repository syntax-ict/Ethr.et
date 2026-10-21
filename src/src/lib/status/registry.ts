/**
 * The one answer to "what colour is this status".
 *
 * Until 2026-10-20 there were two, and they disagreed: `StatusBadge` drew a
 * suspended employee amber and `statusBadgeClass()` drew a suspended
 * organisation red; cancelled was grey in one and red in the other; draft grey
 * and blue. A payroll run that was calculated and still waiting for approval
 * shared its green with one that had been approved
 * (FRONTEND_REDESIGN_PLAN.md, problem 5).
 *
 * The rules this table follows, so the next status lands in the right row:
 * - success: done and good (approved, paid, present, active).
 * - warning: needs someone's attention or action, or is reversible trouble
 *   (pending, late, probation, suspended, trial).
 * - error: failed, refused or terminal and bad (rejected, failed, terminated).
 * - info: in motion, nothing wrong (processing, hired, on leave).
 * - neutral: closed or not started, no judgement (draft, cancelled, retired).
 *
 * Every tone renders with a soft pair from `globals.css`, which
 * `design-token-contrast.test.ts` holds to AA in all three themes. The old
 * `statusBadgeClass()` drew coloured text on a 10% tint of itself, which
 * nothing measured.
 */

export type StatusTone =
  "success" | "warning" | "error" | "info" | "primary" | "neutral";

/** A status whose meaning depends on what it is the status of. */
export type StatusDomain = "payroll_run";

const TONES: Record<string, StatusTone> = {
  active: "success",
  approved: "success",
  completed: "success",
  confirmed: "success",
  healthy: "success",
  online: "success",
  paid: "success",
  present: "success",

  early_leave: "warning",
  late: "warning",
  pending: "warning",
  probation: "warning",
  suspended: "warning",
  trial: "warning",
  warning: "warning",

  absent: "error",
  error: "error",
  failed: "error",
  overdue: "error",
  rejected: "error",
  terminated: "error",
  unhealthy: "error",
  voided: "error",

  created: "info",
  hired: "info",
  on_leave: "info",
  processing: "info",

  cancelled: "neutral",
  draft: "neutral",
  offline: "neutral",
  resigned: "neutral",
  retired: "neutral",
  unknown: "neutral",
};

const DOMAIN_TONES: Record<StatusDomain, Record<string, StatusTone>> = {
  // A completed run is calculated and waiting for someone to approve it, so
  // it reads like `pending`, not like the approved run beside it. A completed
  // loan is repaid, which is why this is per domain and not in TONES.
  payroll_run: { completed: "warning" },
};

export interface ToneClasses {
  /** Background and text for a badge or pill. */
  badge: string;
  /** The small dot drawn before the label; empty for neutral. */
  dot: string;
  bg: string;
  text: string;
  border: string;
}

const CLASSES: Record<StatusTone, ToneClasses> = {
  success: {
    badge: "bg-success-soft text-success-on-soft",
    dot: "bg-status-success",
    bg: "bg-success-soft",
    text: "text-success-on-soft",
    border: "border-success-edge",
  },
  warning: {
    badge: "bg-warning-soft text-warning-on-soft",
    dot: "bg-status-warning",
    bg: "bg-warning-soft",
    text: "text-warning-on-soft",
    border: "border-warning-edge",
  },
  error: {
    badge: "bg-destructive-soft text-destructive-on-soft",
    dot: "bg-status-error",
    bg: "bg-destructive-soft",
    text: "text-destructive-on-soft",
    border: "border-destructive-edge",
  },
  info: {
    badge: "bg-info-soft text-info-on-soft",
    dot: "bg-status-info",
    bg: "bg-info-soft",
    text: "text-info-on-soft",
    border: "border-info-edge",
  },
  primary: {
    badge: "bg-primary-soft text-primary-on-soft",
    dot: "bg-interactive-primary",
    bg: "bg-primary-soft",
    text: "text-primary-on-soft",
    border: "border-primary-edge",
  },
  neutral: {
    badge: "bg-neutral-soft text-neutral-on-soft",
    dot: "",
    bg: "bg-neutral-soft",
    text: "text-neutral-on-soft",
    border: "border-neutral-edge",
  },
};

/**
 * A status whose word depends on what it describes. A payroll run's
 * `completed` means calculated and waiting for approval; "Completed" read as
 * finished, beside an approved run that is.
 */
const DOMAIN_LABEL_KEYS: Record<StatusDomain, Record<string, string>> = {
  payroll_run: { completed: "status.payroll_run.completed" },
};

/** The translation key for a status's label. */
export function statusLabelKeyOf(
  status: string,
  domain?: StatusDomain,
): string {
  return (
    (domain && DOMAIN_LABEL_KEYS[domain][status.toLowerCase()]) ||
    `status.${status}`
  );
}

export function statusToneOf(
  status: string,
  domain?: StatusDomain,
): StatusTone {
  const key = status.toLowerCase();
  return (domain && DOMAIN_TONES[domain][key]) || TONES[key] || "neutral";
}

export function toneClasses(tone: StatusTone): ToneClasses {
  return CLASSES[tone];
}

/** Every status the table names, for tests and documentation. */
export const KNOWN_STATUSES: readonly string[] = Object.keys(TONES);
