import { Loader2 } from "lucide-react";

/**
 * The full-screen wait in front of the authenticated app.
 *
 * One component for two gates, because the prerendered dashboard HTML is this
 * screen and whichever gate is closed first in the browser must hydrate it
 * exactly: `DashboardShell` while the reader's dictionary loads, then
 * `AuthGuard` while `/auth/me` answers.
 */
export function AppLoadingScreen({ label }: { label: string }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background">
      <div className="flex flex-col items-center gap-3">
        <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
          <span className="text-lg font-bold text-primary-foreground">E</span>
        </div>
        <Loader2 className="h-6 w-6 animate-spin text-primary" />
        <p className="text-sm text-muted-foreground">{label}</p>
      </div>
    </div>
  );
}
