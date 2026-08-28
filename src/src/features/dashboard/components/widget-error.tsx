"use client";

import { AlertTriangle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { useT } from "@/lib/i18n/useT";

/**
 * Failure state for a dashboard widget.
 *
 * Every widget here previously either returned null or fell back to `?? []` on
 * error, so a failed request rendered as "No announcements" / "All caught up!"
 * or as nothing at all. Both are worse than an error: the user reads a false
 * statement about their data and has no way to retry (Nielsen #1 and #9, and
 * the four-state QueryBoundary rule in CLAUDE.md).
 */
export function WidgetError({
  title,
  onRetry,
}: {
  title: string;
  onRetry: () => void;
}) {
  const { t } = useT();

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold">{title}</CardTitle>
      </CardHeader>
      <CardContent>
        <div
          role="alert"
          className="flex flex-col items-center py-6 text-center"
        >
          <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-status-error/10">
            <AlertTriangle className="h-6 w-6 text-status-error" />
          </div>
          <p className="mt-3 text-sm font-medium text-foreground">
            {t("common.load_failed", "Couldn't load this")}
          </p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {t(
              "common.load_failed_hint",
              "The data may be out of date. Try again.",
            )}
          </p>
          <Button
            variant="outline"
            size="sm"
            className="mt-4"
            onClick={onRetry}
          >
            <RefreshCw className="mr-2 h-3 w-3" />
            {t("common.retry", "Try again")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
