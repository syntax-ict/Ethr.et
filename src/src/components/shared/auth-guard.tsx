"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { isAxiosError } from "axios";
import { useCurrentUser } from "@/features/auth/api";
import { clearOfflineIdentity } from "@/features/auth/offline-identity";
import { useT } from "@/lib/i18n/useT";
import { Loader2 } from "lucide-react";

/**
 * The server's answer that this browser is not signed in: 401, or 419 for an
 * expired session. Anything else, a network error above all, says nothing
 * about the session.
 */
function isSignedOut(error: unknown): boolean {
  if (!isAxiosError(error)) return false;
  const status = error.response?.status;
  return status === 401 || status === 419;
}

/**
 * The gate in front of every authenticated screen — it wraps the whole
 * `(dashboard)` layout.
 */
export function AuthGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { t } = useT();
  const { data: user, isLoading, error } = useCurrentUser();
  const signedOut = isSignedOut(error);

  useEffect(() => {
    if (isLoading) return;
    if (signedOut) clearOfflineIdentity();
    if (signedOut || !user) {
      router.replace("/login");
    }
  }, [isLoading, signedOut, user, router]);

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-background">
        <div className="flex flex-col items-center gap-3">
          <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-primary">
            <span className="text-lg font-bold text-primary-foreground">E</span>
          </div>
          <Loader2 className="h-6 w-6 animate-spin text-primary" />
          <p className="text-sm text-muted-foreground">
            {t("common.loading", "Loading...")}
          </p>
        </div>
      </div>
    );
  }

  // `signedOut` as well as `!user`, not just `!user`. TanStack keeps the last
  // successful `data` when a refetch fails, so a session that has just been
  // revoked or expired leaves `user` populated while the refetch answers 401 —
  // and checking only `!user` kept the dashboard on screen until the redirect
  // above landed, for exactly the user whose access had just been taken away.
  //
  // Only the server's verdict counts, not any error. Offline, /auth/me gets no
  // answer at all (the service worker never stores it), and treating that as
  // signed out sent a field worker who opened the installed app with no
  // signal, or kept it open past the five-minute refetch, to /login, where
  // nobody can sign in offline. A failure with no verdict keeps the last user.
  //
  // quiet-on-error: a failed /auth/me is either a verdict (signed out, so
  // /login) or no answer (keep the page), never an error screen of its own.
  if (signedOut || !user) {
    return null;
  }

  return <>{children}</>;
}
