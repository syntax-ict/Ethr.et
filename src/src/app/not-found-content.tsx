"use client";

import { useSyncExternalStore } from "react";
import Link from "next/link";
import { ArrowLeft, Home } from "lucide-react";
import { Button } from "@/components/ui/button";
import { isAvailableLocale } from "@/lib/i18n/config";
import { RouteLocaleProvider } from "@/lib/i18n/route-locale";
import { useT } from "@/lib/i18n/useT";

const noSubscription = () => () => {};

/** The language an address names in its first segment (`/am/…`), or null. */
export function localeFromPath(pathname: string): string | null {
  const first = pathname.split("/")[1] ?? "";
  return isAvailableLocale(first) ? first : null;
}

/**
 * The 404 page; `not-found.tsx` registers its strings first.
 *
 * One exported 404 answers every address, so its language cannot be built
 * in. An address that names one (`/am/no-such-page`) is read in it, as every
 * marketing page is; anywhere else the reader's saved choice applies. Before,
 * `/am/no-such-page` was served in Amharic and switched to a saved English
 * after hydration (production QA 2026-11-13, PERF-009). Read after
 * hydration: the server snapshot is null, so the first render matches the
 * exported HTML.
 */
export function NotFoundContent() {
  const pathLocale = useSyncExternalStore(
    noSubscription,
    () => localeFromPath(window.location.pathname),
    () => null,
  );

  return pathLocale ? (
    <RouteLocaleProvider locale={pathLocale}>
      <NotFoundBody />
    </RouteLocaleProvider>
  ) : (
    <NotFoundBody />
  );
}

function NotFoundBody() {
  const { t } = useT();

  return (
    <main className="flex min-h-screen flex-col items-center justify-center bg-background px-4 text-center">
      {/* The exported 404 shipped with no <title> (production QA 2026-10-11,
          P14); React 19 hoists this into <head>. */}
      <title>{`${t("not_found.title", "Page not found")} | ETHR`}</title>
      <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-muted">
        <span className="text-3xl font-bold text-muted-foreground">?</span>
      </div>

      <h1 className="mt-6 text-7xl font-bold tracking-tighter text-foreground">
        404
      </h1>
      <p className="mt-3 text-xl font-medium text-foreground">
        {t("not_found.title", "Page not found")}
      </p>
      <p className="mt-2 max-w-sm text-sm text-muted-foreground">
        {t(
          "not_found.description",
          "The page you're looking for doesn't exist or has been moved.",
        )}
      </p>

      <div className="mt-8 flex flex-col items-center gap-3 sm:flex-row">
        <Button asChild>
          <Link href="/dashboard">
            <Home className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("not_found.go_dashboard", "Go to dashboard")}
          </Link>
        </Button>
        <Button variant="outline" asChild>
          <Link href="/">
            <ArrowLeft className="mr-2 h-4 w-4" aria-hidden="true" />
            {t("not_found.back_home", "Back to home")}
          </Link>
        </Button>
      </div>
    </main>
  );
}
