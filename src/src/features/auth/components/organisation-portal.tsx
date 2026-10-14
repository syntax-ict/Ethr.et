"use client";

import { useEffect, useState } from "react";
import { useAuthHostContext } from "@/lib/auth/use-auth-host-context";
import { useLandingFeed } from "@/features/auth/landing-feed";
import { PortalView } from "./portal-view";
import { VacancyDialog } from "./vacancy-dialog";
import { AnnouncementDialog } from "./announcement-dialog";

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
  } = useAuthHostContext();
  const { data: feed, isLoading } = useLandingFeed(tenantRequest);
  const [announcementId, setAnnouncementId] = useState<string | null>(null);
  const [vacancyId, setVacancyId] = useState<string | null>(null);

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
        onOpenAnnouncement={setAnnouncementId}
        onOpenVacancy={setVacancyId}
      >
        {children}
      </PortalView>
      <AnnouncementDialog
        request={tenantRequest}
        publicId={announcementId}
        onClose={() => setAnnouncementId(null)}
      />
      <VacancyDialog
        request={tenantRequest}
        publicId={vacancyId}
        onClose={() => setVacancyId(null)}
      />
    </>
  );
}
