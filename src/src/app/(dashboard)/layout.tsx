'use client';

import { AppSidebar } from '@/components/layouts/app-sidebar';
import { AppHeader } from '@/components/layouts/app-header';
import { AuthGuard } from '@/components/shared/auth-guard';
import { ErrorBoundary } from '@/components/shared/error-boundary';
import { TenantBrandingProvider } from '@/features/branding/TenantBrandingProvider';
import { ReverbProvider } from '@/components/providers/reverb-provider';
import { ImpersonationBanner } from '@/components/shared/impersonation-banner';
import { useCurrentUser } from '@/features/auth/api';

function DashboardInner({ children }: { children: React.ReactNode }) {
  const { data: user } = useCurrentUser();
  const token = typeof window !== 'undefined' ? (localStorage.getItem('access_token') ?? undefined) : undefined;

  return (
    <ReverbProvider userId={user?.public_id} token={token}>
      <ImpersonationBanner />
      <div className="flex min-h-screen bg-background">
        <AppSidebar />
        <div className="flex flex-1 flex-col">
          <AppHeader />
          <main className="flex-1 overflow-y-auto p-4 md:p-6">
            <ErrorBoundary>{children}</ErrorBoundary>
          </main>
        </div>
      </div>
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
