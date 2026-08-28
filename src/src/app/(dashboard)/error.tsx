"use client";

import { useEffect } from "react";
import Link from "next/link";
import * as Sentry from "@sentry/nextjs";
import { AlertTriangle, Home, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

/**
 * Error boundary for the authenticated app.
 *
 * Without a boundary at this level, a thrown render error inside any dashboard
 * route escalated to `app/error.tsx`, which replaces the *entire* document —
 * sidebar, header and navigation included. The user lost their place in the app
 * because one widget failed. Catching it here keeps the shell mounted so the
 * failure stays scoped to the content area and every other route is still one
 * click away.
 *
 * The digest is the only handle support has on a production stack trace, so it
 * is shown and reported rather than logged to a console nobody is reading.
 */
export default function DashboardError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const { t } = useT();

  useEffect(() => {
    Sentry.captureException(error, {
      tags: { boundary: "dashboard" },
      extra: { digest: error.digest },
    });
  }, [error]);

  return (
    <div className="flex min-h-[50vh] flex-col items-center justify-center px-4 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-destructive-soft">
        <AlertTriangle
          className="h-7 w-7 text-destructive-on-soft"
          aria-hidden="true"
        />
      </div>

      <h2 className="mt-5 text-xl font-semibold tracking-tight text-foreground">
        {t("error.title", "Something went wrong")}
      </h2>
      <p className="mt-2 max-w-sm text-sm text-muted-foreground">
        {t(
          "error.description",
          "This page failed to load. Your work elsewhere in the app is unaffected.",
        )}
      </p>

      {error.digest && (
        <p className="mt-3 font-mono text-xs text-muted-foreground">
          {t("error.reference", "Reference")}: {error.digest}
        </p>
      )}

      <div className="mt-7 flex flex-col items-center gap-3 sm:flex-row">
        <Button onClick={reset}>
          <RotateCcw className="mr-2 h-4 w-4" aria-hidden="true" />
          {t("common.retry", "Try again")}
        </Button>
        <Button variant="outline" asChild>
          <Link href="/dashboard">
            <Home className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("error.go_dashboard", "Go to dashboard")}
          </Link>
        </Button>
      </div>
    </div>
  );
}
