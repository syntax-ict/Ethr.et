"use client";

import { AlertTriangle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

interface ErrorStateProps {
  /** Re-runs the failed request. Usually the query's `refetch`. */
  onRetry?: () => void;
  /** For a card or chart: smaller icon and padding, one line of text. */
  compact?: boolean;
  title?: string;
  hint?: string;
  className?: string;
}

/**
 * What a screen shows when its data did not load.
 *
 * Until 2026-10-21 this lived privately inside `QueryBoundary`, so the 41
 * screens that render their own skeleton and read `isLoading` never showed
 * it. When their request failed they fell through to their empty branch.
 * The dashboard said "Not Checked In" and "No attendance recorded this
 * month yet" for a request that had simply failed, which is a wrong answer,
 * not a missing one (FRONTEND_REDESIGN_PLAN.md, problem 1).
 *
 * `role="alert"` so a screen reader hears it without hunting for it.
 */
export function ErrorState({
  onRetry,
  compact = false,
  title,
  hint,
  className,
}: ErrorStateProps) {
  const { t } = useT();

  return (
    <div
      role="alert"
      className={cn(
        "flex flex-col items-center justify-center text-center",
        compact ? "gap-2 p-4" : "py-12",
        className,
      )}
    >
      <AlertTriangle
        className={cn("text-destructive", compact ? "h-6 w-6" : "h-10 w-10")}
        aria-hidden="true"
      />
      <p
        className={cn(
          "font-medium text-foreground",
          compact ? "text-sm" : "mt-3 text-sm",
        )}
      >
        {title ?? t("common.load_failed", "Couldn't load this")}
      </p>
      {!compact && (
        <p className="mt-1 text-xs text-muted-foreground">
          {hint ??
            t(
              "common.load_failed_hint",
              "The data may be out of date. Try again.",
            )}
        </p>
      )}
      {onRetry && (
        <Button
          variant="outline"
          size="sm"
          className={compact ? undefined : "mt-4"}
          onClick={onRetry}
        >
          <RefreshCw className="mr-2 h-3 w-3" aria-hidden="true" />
          {t("common.retry", "Try again")}
        </Button>
      )}
    </div>
  );
}
