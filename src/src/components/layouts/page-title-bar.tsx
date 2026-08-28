"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight } from "lucide-react";
import { getRouteMeta, routeI18nKey, sectionI18nKey } from "@/lib/route-meta";
import { useT } from "@/lib/i18n/useT";

// Pages that render their own page-level header. On /dashboard the personalised
// greeting block is the h1 — showing this bar too puts the word "Dashboard" on
// screen three times (header label, breadcrumb, greeting) before any data.
const HIDDEN_PATHS = ["/setup/guided", "/dashboard"];

export function PageTitleBar() {
  const pathname = usePathname();
  const { t } = useT();

  if (HIDDEN_PATHS.includes(pathname)) return null;

  const meta = getRouteMeta(pathname);
  if (!meta) return null;

  const label = t(routeI18nKey(pathname, "label"), meta.label);
  const description = meta.description
    ? t(routeI18nKey(pathname, "desc"), meta.description)
    : undefined;

  const crumbs: Array<{ label: string; href?: string }> = [];

  if (meta.section) {
    crumbs.push({ label: t(sectionI18nKey(meta.section), meta.section) });
  }
  if (meta.parent) {
    crumbs.push({
      label: t(routeI18nKey(meta.parent.href, "label"), meta.parent.label),
      href: meta.parent.href,
    });
  }
  crumbs.push({ label });

  return (
    <div className="border-b border-border bg-background px-4 pb-4 pt-4 md:px-6">
      {crumbs.length > 1 && (
        <nav aria-label={t("nav.breadcrumb", "Breadcrumb")} className="mb-1.5">
          <ol className="flex items-center gap-1">
            {crumbs.map((crumb, i) => {
              const isLast = i === crumbs.length - 1;
              return (
                <li key={i} className="flex items-center gap-1">
                  {i > 0 && (
                    <ChevronRight className="h-3 w-3 shrink-0 text-muted-foreground/40" />
                  )}
                  {crumb.href && !isLast ? (
                    <Link
                      href={crumb.href}
                      className="text-xs text-muted-foreground transition-colors hover:text-foreground"
                    >
                      {crumb.label}
                    </Link>
                  ) : (
                    <span
                      className={
                        isLast
                          ? "text-xs font-medium text-foreground"
                          : "text-xs text-muted-foreground"
                      }
                    >
                      {crumb.label}
                    </span>
                  )}
                </li>
              );
            })}
          </ol>
        </nav>
      )}

      <h1 className="text-xl font-semibold tracking-tight text-foreground md:text-2xl">
        {label}
      </h1>
      {description && (
        <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
      )}
    </div>
  );
}
