"use client";

import Link from "next/link";
import { ArrowLeft, Home } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

export default function NotFound() {
  const { t } = useT();

  return (
    <main className="flex min-h-screen flex-col items-center justify-center bg-background px-4 text-center">
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
