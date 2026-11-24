"use client";

import { Fragment, useEffect } from "react";
import { ChevronRight } from "lucide-react";
import { useT } from "@/lib/i18n/useT";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { useLandingFeed } from "@/features/auth/landing-feed";
import { portalLinks, visibleSections } from "./portal-chrome";

/**
 * What every page inside an organisation's portal frame needs: who the host
 * is, the portal's links, its visible sections for the header and footer
 * menus (the feed the portal itself reads, cached, so the menus match what
 * the portal shows) and whether the platform has paused news.
 */
export function usePortalPage({
  current = "news",
}: { current?: "home" | "news" } = {}) {
  const host = useAuthHostContext();
  const links = portalLinks(host.tenantLanding, host.carryOrganisation, false);
  const feed = useLandingFeed(host.tenantRequest);
  const sections = visibleSections(host.tenantLanding, feed.data);
  // Switched off by the platform for now, not withdrawn by the organisation:
  // the page says it is temporary (owner decision D7).
  const paused = host.tenantLanding?.paused?.includes("announcements") ?? false;

  return {
    host,
    links,
    paused,
    /** Props for `PortalChrome`; a page may override `links`. */
    chrome: {
      name: host.tenantName,
      brand: host.tenantBrand,
      landing: host.tenantLanding,
      links,
      sections,
      current,
    },
  };
}

/**
 * The document's title for a portal page: "{title} · {organisation}" once
 * the organisation's name is known, and what it was before when the page
 * is left. Nothing while `title` is empty (a story still loading).
 */
export function usePortalTitle(
  title: string | null | undefined,
  organisation: string | null,
) {
  useEffect(() => {
    if (!title) return;
    const previous = document.title;
    document.title = organisation ? `${title} · ${organisation}` : title;
    return () => {
      document.title = previous;
    };
  }, [title, organisation]);
}

const CRUMB =
  "rounded-sm hover:text-foreground hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring";

/**
 * The trail above a portal page: the organisation, then each step. A step
 * with an `href` is a link; the last one without is the current page.
 */
export function PortalBreadcrumb({
  page,
  trail,
}: {
  page: Pick<ReturnType<typeof usePortalPage>, "host" | "links">;
  trail: Array<{ label: string; href?: string }>;
}) {
  const { t } = useT();

  return (
    <nav
      aria-label={t("news.breadcrumb", "Breadcrumb")}
      className="text-sm text-muted-foreground"
    >
      <ol className="flex flex-wrap items-center gap-1.5">
        <li>
          <a href={page.links.home} className={CRUMB}>
            {page.host.tenantName ?? t("news.home", "Home")}
          </a>
        </li>
        {trail.map((step) => (
          <Fragment key={step.label}>
            <li aria-hidden="true">
              <ChevronRight className="h-3.5 w-3.5" />
            </li>
            {step.href ? (
              <li>
                <a href={step.href} className={CRUMB}>
                  {step.label}
                </a>
              </li>
            ) : (
              <li aria-current="page" className="font-medium text-foreground">
                {step.label}
              </li>
            )}
          </Fragment>
        ))}
      </ol>
    </nav>
  );
}
