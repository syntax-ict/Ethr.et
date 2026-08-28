export type SemanticTone =
  "success" | "warning" | "error" | "info" | "primary" | "neutral";

const toneClasses: Record<
  SemanticTone,
  { bg: string; text: string; border: string; badge: string }
> = {
  success: {
    bg: "bg-status-success/10",
    text: "text-status-success",
    border: "border-status-success/30",
    badge: "bg-status-success/10 text-status-success border-0",
  },
  warning: {
    bg: "bg-status-warning/10",
    text: "text-status-warning",
    border: "border-status-warning/30",
    badge: "bg-status-warning/10 text-status-warning border-0",
  },
  error: {
    bg: "bg-status-error/10",
    text: "text-status-error",
    border: "border-status-error/30",
    badge: "bg-status-error/10 text-status-error border-0",
  },
  info: {
    bg: "bg-status-info/10",
    text: "text-status-info",
    border: "border-status-info/30",
    badge: "bg-status-info/10 text-status-info border-0",
  },
  primary: {
    bg: "bg-interactive-primary/8",
    text: "text-interactive-primary",
    border: "border-interactive-primary/30",
    badge: "bg-interactive-primary/10 text-interactive-primary border-0",
  },
  neutral: {
    bg: "bg-muted",
    text: "text-muted-foreground",
    border: "border-border",
    badge: "bg-muted text-muted-foreground border-0",
  },
};

export function statusTone(tone: SemanticTone) {
  return toneClasses[tone];
}

const statusToTone: Record<string, SemanticTone> = {
  active: "success",
  online: "success",
  healthy: "success",
  paid: "success",
  approved: "success",
  confirmed: "success",
  present: "success",

  trial: "warning",
  pending: "warning",
  probation: "warning",
  late: "warning",
  warning: "warning",

  error: "error",
  failed: "error",
  overdue: "error",
  rejected: "error",
  terminated: "error",
  absent: "error",
  suspended: "error",
  cancelled: "error",
  unhealthy: "error",

  draft: "info",
  processing: "info",
  hired: "info",
  created: "info",

  offline: "neutral",
  unknown: "neutral",
  retired: "neutral",
  resigned: "neutral",
};

export function statusToSemanticTone(status: string): SemanticTone {
  return statusToTone[status.toLowerCase()] ?? "neutral";
}

export function statusBadgeClass(status: string): string {
  return toneClasses[statusToSemanticTone(status)].badge;
}

export function statusTextClass(status: string): string {
  return toneClasses[statusToSemanticTone(status)].text;
}

export function statusDotClass(status: string): string {
  const tone = statusToSemanticTone(status);
  const map: Record<SemanticTone, string> = {
    success: "bg-status-success",
    warning: "bg-status-warning",
    error: "bg-status-error",
    info: "bg-status-info",
    primary: "bg-interactive-primary",
    neutral: "bg-muted-foreground/40",
  };
  return map[tone];
}
