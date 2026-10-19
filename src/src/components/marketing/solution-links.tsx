"use client";

import Link from "next/link";
import { useT } from "@/lib/i18n/useT";
import { useLocaleHref } from "@/lib/i18n/route-locale";
import { SOLUTIONS } from "@/components/marketing/solutions";

/**
 * The search-intent pages as a row of links, placed under the capabilities a
 * reader has just scanned. A link inside the content tells a crawler more
 * about the page it points to than the same link in a footer does.
 */
export function SolutionLinks() {
  const { t } = useT();
  const href = useLocaleHref();
  const label = t("marketing.solutions.in_detail", "In detail");

  return (
    <nav
      aria-label={label}
      className="mt-6 flex flex-wrap items-center justify-center gap-2"
    >
      <span className="text-sm text-muted-foreground">{label}:</span>
      {SOLUTIONS.map((solution) => (
        <Link
          key={solution.id}
          href={href(solution.path)}
          className="rounded-full border border-border/60 bg-card px-3 py-1 text-sm font-medium text-foreground transition-colors hover:border-border"
        >
          {t(`marketing.solutions.${solution.id}.link`)}
        </Link>
      ))}
    </nav>
  );
}
