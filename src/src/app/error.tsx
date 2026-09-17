"use client";

import { useEffect } from "react";
import * as Sentry from "@sentry/nextjs";
import { AlertTriangle, Home, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

/**
 * Root error boundary — the last resort, reached when a failure escapes the
 * route-group boundaries (e.g. `(dashboard)/error.tsx`).
 *
 * Uses a plain `<a>` rather than `next/link`: at this level the router itself
 * may be the thing that failed, and a full document load is the reliable way
 * out.
 */
export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const { t } = useT();

  useEffect(() => {
    Sentry.captureException(error, {
      tags: { boundary: "root" },
      extra: { digest: error.digest },
    });
  }, [error]);

  return (
    <main className="flex min-h-[60vh] flex-col items-center justify-center px-4 text-center">
      <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-destructive-soft">
        <AlertTriangle
          className="h-8 w-8 text-destructive-on-soft"
          aria-hidden="true"
        />
      </div>

      <h1 className="mt-6 text-2xl font-bold tracking-tight text-foreground">
        {t("error.title", "Something went wrong")}
      </h1>
      <p className="mt-2 max-w-sm text-sm text-muted-foreground">
        {t(
          "error.description_global",
          "An unexpected error occurred. Please try again or return to the dashboard.",
        )}
      </p>

      {error.digest && (
        <p className="mt-3 font-mono text-xs text-muted-foreground">
          {t("error.reference", "Reference")}: {error.digest}
        </p>
      )}

      <div className="mt-8 flex flex-col items-center gap-3 sm:flex-row">
        <Button onClick={reset}>
          <RotateCcw className="mr-2 h-4 w-4" aria-hidden="true" />
          {t("common.retry", "Try again")}
        </Button>
        <Button variant="outline" asChild>
          {/* eslint-disable-next-line @next/next/no-html-link-for-pages --
              deliberate, for the reason in the docblock above: at this level
              the router may be what failed, so a document load is the reliable
              way out. The rule only started firing here when the public site
              gained a top-level `[locale]` segment: it turns any bracketed
              segment into a catch-all regex, so `/dashboard/` now looks to it
              like a page this `[locale]` route serves. It is not — static
              segments win, and `dynamicParams = false` 404s anything that is
              not `am` or `en`. Confirmed against the build, which still
              prerenders /dashboard from `(dashboard)/dashboard/page.tsx`. */}
          <a href="/dashboard">
            <Home className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("error.go_dashboard", "Go to dashboard")}
          </a>
        </Button>
      </div>
    </main>
  );
}
