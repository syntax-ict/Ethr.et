"use client";

import { AppSidebar } from "@/components/layouts/app-sidebar";
import { AppHeader } from "@/components/layouts/app-header";
import { MobileBottomNav } from "@/components/layouts/mobile-bottom-nav";
import { OfflineBanner } from "@/components/shared/offline-banner";
import { AuthGuard } from "@/components/shared/auth-guard";
import { CommandPalette } from "@/components/shared/command-palette";
import { ErrorBoundary } from "@/components/shared/error-boundary";
import { TenantBrandingProvider } from "@/features/branding/TenantBrandingProvider";
import { ReverbProvider } from "@/components/providers/reverb-provider";
import { ImpersonationBanner } from "@/components/shared/impersonation-banner";
import { useCurrentUser } from "@/features/auth/api";

function DashboardInner({ children }: { children: React.ReactNode }) {
  const { data: user } = useCurrentUser();
  const token =
    typeof window !== "undefined"
      ? (localStorage.getItem("access_token") ?? undefined)
      : undefined;

  return (
    <ReverbProvider userId={user?.public_id} token={token}>
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-foreground focus:outline-none"
      >
        Skip to main content
      </a>
      <CommandPalette />
      <ImpersonationBanner />
      <OfflineBanner />
      <div className="flex min-h-screen bg-background">
        <AppSidebar />
        <div className="flex flex-1 flex-col">
          <AppHeader />
          <main
            id="main-content"
            className="flex-1 overflow-y-auto p-4 pb-20 md:p-6 lg:pb-6"
            role="main"
          >
            <ErrorBoundary>{children}</ErrorBoundary>
          </main>
        </div>
      </div>
      <MobileBottomNav />
    </ReverbProvider>
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
