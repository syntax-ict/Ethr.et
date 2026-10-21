"use client";

import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { useT } from "@/lib/i18n/useT";
import {
  statusLabelKeyOf,
  statusToneOf,
  toneClasses,
  type StatusDomain,
} from "@/lib/status/registry";

interface StatusBadgeProps {
  status: string;
  /** For a status whose meaning depends on what it describes, such as a payroll run's `completed`. */
  domain?: StatusDomain;
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
export function StatusBadge({ status, domain, className }: StatusBadgeProps) {
  const { t } = useT();
  // One table for every status colour in the app: lib/status/registry.ts.
  const tone = toneClasses(statusToneOf(status, domain));
  const dot = tone.dot;
  const humanized = status.replace(/_/g, " ");

  return (
    <Badge
      variant="outline"
      className={cn(
        "border-0 gap-1.5 font-medium capitalize",
        tone.badge,
        className,
      )}
    >
      {dot && (
        <span
          className={cn("inline-block h-1.5 w-1.5 shrink-0 rounded-full", dot)}
          aria-hidden
        />
      )}
      {t(statusLabelKeyOf(status, domain), humanized)}
    </Badge>
  );
}
