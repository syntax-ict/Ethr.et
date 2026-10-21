"use client";

import { useEffect, useState } from "react";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { useLandingFeed } from "@/features/auth/landing-feed";
import { PortalView } from "./portal-view";
import { portalLinks } from "./portal-chrome";
import { VacancyDialog } from "./vacancy-dialog";

// quiet-on-error: the news and vacancy feed is optional content on a public page; when it fails the portal still renders its sign-in, which is the page's job, rather than an error to a visitor.

/**
 * The organisation portal at ethr.et/{slug}, the organisation's subdomain or
 * its own domain: `PortalView` drawn from the organisation's public context
 * and feed, with the shared sign-in form in its hero card. The settings
 * screen previews the same view from the form being edited.
 */
export function OrganisationPortal({
  children,
}: {
  children: React.ReactNode;
}) {
  const {
    tenantName,
    tenantBrand,
    tenantLanding,
    tenantContextLoading,
    tenantRequest,
    carryOrganisation,
  } = useAuthHostContext();
  const { data: feed, isLoading } = useLandingFeed(tenantRequest);
  const [vacancyId, setVacancyId] = useState<string | null>(null);
  // Stories open on their own pages (`/news/{id}`), which can be linked and
  // shared, rather than in a dialog over the sign-in page.
  const links = portalLinks(tenantLanding, carryOrganisation, true);

  // The square mark becomes the page's icon while the portal is open; the
  // links are put back when it closes, so ETHR's own pages keep theirs.
  const markUrl = tenantBrand?.mark_url ?? null;
  useEffect(() => {
    if (!markUrl || typeof document === "undefined") return;
    const links = Array.from(
      document.querySelectorAll<HTMLLinkElement>(
        'link[rel~="icon"], link[rel="apple-touch-icon"]',
      ),
    );
    const previous = links.map((link) => link.getAttribute("href"));
    const added: HTMLLinkElement[] = [];
    if (links.length === 0) {
      const link = document.createElement("link");
      link.rel = "icon";
      document.head.appendChild(link);
      links.push(link);
      previous.push(null);
      added.push(link);
    }
    links.forEach((link) => link.setAttribute("href", markUrl));
    return () => {
      links.forEach((link, i) => {
        const href = previous[i];
        if (added.includes(link)) link.remove();
        else if (href !== null) link.setAttribute("href", href);
      });
    };
  }, [markUrl]);

  return (
    <>
      <PortalView
        name={tenantName}
        brand={tenantBrand}
        landing={tenantLanding}
        feed={feed}
        feedLoading={isLoading && tenantRequest !== null}
        loading={tenantContextLoading}
        links={links}
        onOpenVacancy={setVacancyId}
      >
        {children}
      </PortalView>
      <VacancyDialog
        request={tenantRequest}
        publicId={vacancyId}
        onClose={() => setVacancyId(null)}
      />
    </>
  );
}
