"use client";

import type { ReactNode } from "react";
import { X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

interface FilterBarProps {
  /** The page's own controls: a search box, selects, segmented choices. */
  children: ReactNode;
  /** How many filters are not at their default. Shows "Clear filters" when above zero. */
  activeCount: number;
  onClear: () => void;
  className?: string;
}

/**
 * The row of filters above a list, laid out the same on every page.
 *
 * Pages bring their own controls; this gives them one place to sit, wraps
 * them on a phone, labels the group for a screen reader, and offers a single
 * way back to the unfiltered list. Pair it with `useQueryFilters`, which keeps
 * the values in the address so a filtered view survives a reload and can be
 * shared as a link (FRONTEND_REDESIGN_PLAN.md, Phase 3).
 */
export function FilterBar({
  children,
  activeCount,
  onClear,
  className,
}: FilterBarProps) {
  const { t } = useT();

  return (
    <div
      role="group"
      aria-label={t("common.filters", "Filters")}
      className={cn("flex flex-wrap items-center gap-3", className)}
    >
      {children}
      {activeCount > 0 && (
        <Button variant="ghost" size="sm" onClick={onClear}>
          <X className="mr-1 h-3.5 w-3.5" aria-hidden="true" />
          {t("common.clear_filters", "Clear filters")}
        </Button>
      )}
    </div>
  );
}
