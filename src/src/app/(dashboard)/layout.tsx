import type { Metadata, Viewport } from "next";
import { baseMetadata, RootShell, baseViewport } from "../root-shell";
import { DEFAULT_LOCALE } from "@/lib/i18n/translations";
import { AmharicDictionary } from "@/lib/i18n/amharic-dictionary";
import { DashboardShell } from "./dashboard-shell";

export const viewport: Viewport = baseViewport;

// The marketing title is for search results. Behind the login it was what every
// tab read until `useDocumentTitle` ran, and what an organisation's page in the
// console kept reading after a client navigation (production, 2026-10-28).
export const metadata: Metadata = { ...baseMetadata, title: "ETHR" };

/**
 * Root layout for the authenticated application.
 *
 * `DEFAULT_LOCALE`, not a route locale: dashboard URLs carry no language
 * segment and are not prerendered per locale, so the stored preference is the
 * only signal — applied after hydration by `HtmlLangSync`, exactly as before.
 * Adding a `/am/dashboard` prefix would mean 64 more prerendered routes to
 * serve pages that are behind a login and never crawled.
 */
export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RootShell lang={DEFAULT_LOCALE}>
      <AmharicDictionary>
        <DashboardShell>{children}</DashboardShell>
      </AmharicDictionary>
    </RootShell>
  );
}
