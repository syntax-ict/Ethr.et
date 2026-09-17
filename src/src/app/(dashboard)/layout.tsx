import type { Metadata } from "next";
import { baseMetadata, RootShell } from "../root-shell";
import { DEFAULT_LOCALE } from "@/lib/i18n/translations";
import { DashboardShell } from "./dashboard-shell";

export const metadata: Metadata = baseMetadata;

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
      <DashboardShell>{children}</DashboardShell>
    </RootShell>
  );
}
