import type { Metadata, Viewport } from "next";
import { baseMetadata, RootShell, baseViewport } from "../root-shell";
import { DEFAULT_LOCALE } from "@/lib/i18n/translations";
import { AVAILABLE_LOCALES } from "@/lib/i18n/config";
import { serverTranslate } from "@/lib/i18n/public-dictionary";
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
 *
 * No dictionary is registered here. Every page below renders, before the
 * browser knows who is reading, one loading screen with one string (and that
 * string in each available language, for an inline swap to the stored
 * preference before hydration), so that is all the server sends; `DashboardShell` then loads the reader's own
 * language before it renders anything else (2026-10-31).
 */
export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <RootShell lang={DEFAULT_LOCALE}>
      <DashboardShell
        loadingLabel={serverTranslate(
          DEFAULT_LOCALE,
          "common.loading",
          "Loading...",
        )}
        loadingLabels={Object.fromEntries(
          AVAILABLE_LOCALES.map((locale) => [
            locale,
            serverTranslate(locale, "common.loading", "Loading..."),
          ]),
        )}
      >
        {children}
      </DashboardShell>
    </RootShell>
  );
}
