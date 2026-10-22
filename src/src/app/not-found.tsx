"use client";

// This is the one page outside every route group, so no RootShell wraps it
// and nothing else loads the stylesheet: the exported 404.html rendered in
// browser default styling (observed live 2026-10-20). Fonts come from the
// stylesheet's fallbacks here; next/font variables live on the shells.
import "@/styles/globals.css";
import Link from "next/link";
import { ArrowLeft, Home } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";
// Registers Amharic as the app shells do. This page is outside them, so
// the browser had no Amharic to hydrate with while the exported HTML,
// built after other pages had loaded it, was in Amharic: React error 418
// and a title that flipped language (found on the :8081 rehearsal).
import { AmharicDictionary } from "@/lib/i18n/amharic-dictionary";

export default function NotFound() {
  return (
    <AmharicDictionary>
      <NotFoundContent />
    </AmharicDictionary>
  );
}

function NotFoundContent() {
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
