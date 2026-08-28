"use client";

import { AppSidebar } from "@/components/layouts/app-sidebar";
import { AppHeader } from "@/components/layouts/app-header";
import { PageTitleBar } from "@/components/layouts/page-title-bar";
import { AppLayoutProvider } from "@/components/layouts/layout-context";
import { MobileBottomNav } from "@/components/layouts/mobile-bottom-nav";
import { OfflineBanner } from "@/components/shared/offline-banner";
import { AuthGuard } from "@/components/shared/auth-guard";
import { CommandPalette } from "@/components/shared/command-palette";
import { ErrorBoundary } from "@/components/shared/error-boundary";
import { TenantBrandingProvider } from "@/features/branding/TenantBrandingProvider";
import { ReverbProvider } from "@/components/providers/reverb-provider";
import { ImpersonationBanner } from "@/components/shared/impersonation-banner";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { useCurrentUser } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";
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
      <ReverbProvider userId={user?.public_id}>
        <AppLayoutProvider>
          <a
            href="#main-content"
            className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground focus:outline-none"
          >
            {t("a11y.skip_to_content", "Skip to main content")}
          </a>
          <CommandPalette />
          <ImpersonationBanner />
          <OfflineBanner />
          <div className="flex h-screen overflow-hidden bg-background">
            <AppSidebar />
            <div className="flex min-w-0 flex-1 flex-col">
              <AppHeader />
              <main
                id="main-content"
                className="flex-1 overflow-y-auto"
                role="main"
              >
                <PageTitleBar />
                <div className="p-4 pb-20 md:p-6 lg:pb-6">
                  <ErrorBoundary>{children}</ErrorBoundary>
                </div>
              </main>
            </div>
          </div>
          <MobileBottomNav />
        </AppLayoutProvider>
      </ReverbProvider>
    </CalendarProvider>
  );
}

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <AuthGuard>
      <TenantBrandingProvider>
        <DashboardInner>{children}</DashboardInner>
      </TenantBrandingProvider>
    </AuthGuard>
  );
}
