"use client";

import { useEffect, useState } from "react";
import { AppSidebar } from "@/components/layouts/app-sidebar";
import { AppHeader } from "@/components/layouts/app-header";
import { PageTitleBar } from "@/components/layouts/page-title-bar";
import { PageActionsSlotProvider } from "@/components/layouts/page-actions-slot";
import { AppLayoutProvider } from "@/components/layouts/layout-context";
import { MobileBottomNav } from "@/components/layouts/mobile-bottom-nav";
import { OfflineBanner } from "@/components/shared/offline-banner";
import { PausedFeaturesBanner } from "@/components/shared/paused-features-banner";
import { AuthGuard } from "@/components/shared/auth-guard";
import { CommandPalette } from "@/components/shared/command-palette";
import { KeyboardShortcuts } from "@/components/shared/keyboard-shortcuts";
import { ErrorBoundary } from "@/components/shared/error-boundary";
import { TenantBrandingProvider } from "@/features/branding/TenantBrandingProvider";
import { ReverbProvider } from "@/components/providers/reverb-provider";
import { ImpersonationBanner } from "@/components/shared/impersonation-banner";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { CalendarPreferenceSync } from "@/components/shared/calendar-preference-sync";
import { LocalePreferenceSync } from "@/components/shared/locale-preference-sync";
import { useCurrentUser } from "@/features/auth/api";
import { PunchReminders } from "@/features/attendance/components/punch-reminders";
import { useT } from "@/lib/i18n/useT";
import { getLocale, preloadLocale } from "@/lib/i18n/translations";
import { AppLoadingScreen } from "@/components/shared/app-loading-screen";
import { useDocumentTitle } from "@/lib/hooks/useDocumentTitle";

function DashboardInner({ children }: { children: React.ReactNode }) {
  const { data: user } = useCurrentUser();
  const { t } = useT();

  // Sets the browser tab title per route from ROUTE_META — client components
  // cannot export Next `metadata`, so every dashboard page otherwise inherited
  // the root layout's single title.
  useDocumentTitle();

  return (
    <CalendarProvider>
      <CalendarPreferenceSync />
      <LocalePreferenceSync />
      <ReverbProvider userId={user?.public_id}>
        <AppLayoutProvider>
          <a
            href="#main-content"
            className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground focus:outline-none"
          >
            {t("a11y.skip_to_content", "Skip to main content")}
          </a>
          <CommandPalette />
          <KeyboardShortcuts />
          <PunchReminders />
          <ImpersonationBanner />
          <OfflineBanner />
          <PausedFeaturesBanner />
          <div className="flex h-screen overflow-hidden bg-background">
            <AppSidebar />
            <div className="flex min-w-0 flex-1 flex-col">
              <AppHeader />
              <main
                id="main-content"
                className="flex-1 overflow-y-auto"
                role="main"
              >
                <PageActionsSlotProvider>
                  <PageTitleBar />
                  <div className="p-4 pb-[calc(5rem+env(safe-area-inset-bottom))] md:p-6 md:pb-[calc(5rem+env(safe-area-inset-bottom))] lg:pb-6">
                    <ErrorBoundary>{children}</ErrorBoundary>
                  </div>
                </PageActionsSlotProvider>
              </main>
            </div>
          </div>
          <MobileBottomNav />
        </AppLayoutProvider>
      </ReverbProvider>
    </CalendarProvider>
  );
}

// Started when this module evaluates, before React hydrates, so the reader's
// dictionary downloads alongside the rest of the page rather than after it.
if (typeof window !== "undefined") void preloadLocale(getLocale());

/**
 * True once the reader's own dictionary is in memory, and never false again.
 *
 * This shell used to register the whole of `am.json` before rendering, so an
 * English reader downloaded 514 KB of Amharic (89 KB brotli) on every visit and
 * then `en.json` as well. Now it loads only the reader's language, through the
 * same lazy import every other locale uses. `preloadLocale` also settles when
 * the import fails, offline with nothing cached, and the English fallbacks
 * render then, as they always did for an English reader.
 *
 * Only the first opening waits: a later language switch re-renders from the
 * store as before and never takes the app down to this screen.
 */
function useReaderDictionary(): boolean {
  const [ready, setReady] = useState(false);

  useEffect(() => {
    let cancelled = false;
    preloadLocale(getLocale()).then(() => {
      if (!cancelled) setReady(true);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  return ready;
}

/**
 * The authenticated application shell.
 *
 * Split out of `layout.tsx` when `app/layout.tsx` was removed: this tree is now
 * its own root, and a root layout has to render `<html>`/`<body>` and export
 * `metadata`, neither of which a `"use client"` module can do. The layout file
 * is therefore a thin server component and everything below is unchanged.
 *
 * `loadingLabel` is the loading screen's text in Amharic, from the server. The
 * prerendered HTML of every dashboard page is that screen, and until a
 * dictionary arrives the browser has no other way to render it identically.
 */
export function DashboardShell({
  children,
  loadingLabel,
}: {
  children: React.ReactNode;
  loadingLabel: string;
}) {
  const ready = useReaderDictionary();

  if (!ready) return <AppLoadingScreen label={loadingLabel} />;

  return (
    <AuthGuard>
      <TenantBrandingProvider>
        <DashboardInner>{children}</DashboardInner>
      </TenantBrandingProvider>
    </AuthGuard>
  );
}
