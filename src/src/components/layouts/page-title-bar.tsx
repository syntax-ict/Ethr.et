"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight } from "lucide-react";
import {
  getRouteMeta,
  routeI18nKey,
  routeI18nPath,
  sectionI18nKey,
} from "@/lib/route-meta";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";
import {
  useCurrentEntityTitle,
  useRegisterPageActionsSlot,
} from "./page-actions-slot";

// Pages that render their own page-level header. On /dashboard the personalised
// greeting block is the h1 — showing this bar too puts the word "Dashboard" on
// screen three times (header label, breadcrumb, greeting) before any data.
const HIDDEN_PATHS = ["/setup/guided", "/dashboard"];

export function PageTitleBar() {
  const pathname = usePathname();
  const { t } = useT();
  const registerSlot = useRegisterPageActionsSlot();
  const entity = useCurrentEntityTitle();

  if (HIDDEN_PATHS.includes(pathname)) return null;

  const meta = getRouteMeta(pathname);
  if (!meta) return null;

  const keyPath = routeI18nPath(pathname, meta);
  const label = t(routeI18nKey(keyPath, "label"), meta.label);
  const description = meta.description
    ? t(routeI18nKey(keyPath, "desc"), meta.description)
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
  if (entity) {
    // A detail page: the list becomes a link back to it, the record ends the
    // trail, and the page's own header carries the heading.
    crumbs.push({
      label,
      href: pathname.replace(/\/[^/]+\/?$/, "") || "/",
    });
    crumbs.push({ label: entity });
  } else {
    crumbs.push({ label });
  }

  return (
    <div
      className={
        entity
          ? "flex flex-col gap-3 border-b border-border bg-background px-4 py-3 sm:flex-row sm:items-center sm:justify-between md:px-6"
          : "flex flex-col gap-3 border-b border-border bg-background px-4 py-4 sm:flex-row sm:items-end sm:justify-between md:px-6 md:py-5"
      }
    >
      <div className="min-w-0">
        {crumbs.length > 1 && (
          <nav
            aria-label={t("nav.breadcrumb", "Breadcrumb")}
            className={entity ? undefined : "mb-1.5"}
          >
            <ol className="flex min-w-0 flex-wrap items-center gap-1">
              {crumbs.map((crumb, i) => {
                const isLast = i === crumbs.length - 1;
                return (
                  <li key={i} className="flex items-center gap-1">
                    {i > 0 && (
                      <ChevronRight
                        className="h-3 w-3 shrink-0 text-muted-foreground/60"
                        aria-hidden="true"
                      />
                    )}
                    {crumb.href && !isLast ? (
                      <Link
                        href={crumb.href}
                        className={
                          entity
                            ? "text-sm text-muted-foreground transition-colors hover:text-foreground"
                            : "text-xs text-muted-foreground transition-colors hover:text-foreground"
                        }
                      >
                        {crumb.label}
                      </Link>
                    ) : (
                      <span
                        aria-current={isLast ? "page" : undefined}
                        className={cn(
                          entity ? "text-sm" : "text-xs",
                          isLast
                            ? "font-medium text-foreground"
                            : "text-muted-foreground",
                        )}
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

        {!entity && (
          <>
            <h1 className="text-xl font-semibold tracking-tight text-foreground md:text-2xl">
              {label}
            </h1>
            {description && (
              <p className="mt-1 text-sm text-muted-foreground">
                {description}
              </p>
            )}
          </>
        )}
      </div>

      {/* The page's own actions (PageActions) are portalled in here, beside
          the title, instead of taking a row of their own below it. */}
      <div
        ref={registerSlot}
        className="flex min-w-0 shrink-0 flex-wrap items-center gap-2 empty:hidden sm:max-w-[60%] sm:justify-end"
      />
    </div>
  );
}
