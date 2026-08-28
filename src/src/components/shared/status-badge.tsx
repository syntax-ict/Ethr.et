"use client";

import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";

const statusStyles: Record<string, string> = {
  active: "bg-success-soft text-success-on-soft",
  confirmed: "bg-success-soft text-success-on-soft",
  hired: "bg-info-soft text-info-on-soft",
  probation: "bg-warning-soft text-warning-on-soft",
  suspended: "bg-warning-soft text-warning-on-soft",
  terminated: "bg-destructive-soft text-destructive-on-soft",
  resigned: "bg-muted text-muted-foreground",
  retired: "bg-muted text-muted-foreground",
  pending: "bg-warning-soft text-warning-on-soft",
  approved: "bg-success-soft text-success-on-soft",
  rejected: "bg-destructive-soft text-destructive-on-soft",
  cancelled: "bg-muted text-muted-foreground",
  completed: "bg-success-soft text-success-on-soft",
  draft: "bg-muted text-muted-foreground",
  processing: "bg-info-soft text-info-on-soft",
  present: "bg-success-soft text-success-on-soft",
  late: "bg-warning-soft text-warning-on-soft",
  absent: "bg-destructive-soft text-destructive-on-soft",
  paid: "bg-success-soft text-success-on-soft",
  voided: "bg-destructive-soft text-destructive-on-soft",
  early_leave: "bg-warning-soft text-warning-on-soft",
  on_leave: "bg-info-soft text-info-on-soft",
  failed: "bg-destructive-soft text-destructive-on-soft",
};

const statusDot: Record<string, string> = {
  active: "bg-status-success",
  confirmed: "bg-status-success",
  present: "bg-status-success",
  approved: "bg-status-success",
  completed: "bg-status-success",
  paid: "bg-status-success",
  pending: "bg-status-warning",
  probation: "bg-status-warning",
  suspended: "bg-status-warning",
  late: "bg-status-warning",
  early_leave: "bg-status-warning",
  processing: "bg-status-info",
  hired: "bg-status-info",
  on_leave: "bg-status-info",
  terminated: "bg-status-error",
  rejected: "bg-status-error",
  absent: "bg-status-error",
  voided: "bg-status-error",
  failed: "bg-status-error",
};

interface StatusBadgeProps {
  status: string;
  className?: string;
}

/**
 * Statuses arrive as raw enum values from the API (`on_leave`, `terminated`), and
 * this rendered them verbatim — so every status badge in the product read English
 * regardless of locale, across 16 pages. There is no `t()` call at the call sites
 * to notice, which is why the i18n gate could not see it either.
 *
 * Translating here fixes all of them without touching a single caller. The
 * humanised value stays as the fallback, so a status with no key yet still renders
 * readably instead of leaking a bare key like `status.foo` into the UI.
 */
export function StatusBadge({ status, className }: StatusBadgeProps) {
  const { t } = useT();
  const dot = statusDot[status];
  const humanized = status.replace(/_/g, " ");

  return (
    <Badge
      variant="outline"
      className={cn(
        "border-0 gap-1.5 font-medium capitalize",
        statusStyles[status] ?? "bg-muted text-muted-foreground",
        className,
      )}
    >
      {dot && (
        <span
          className={cn("inline-block h-1.5 w-1.5 shrink-0 rounded-full", dot)}
          aria-hidden
        />
      )}
      {t(`status.${status}`, humanized)}
    </Badge>
  );
}
