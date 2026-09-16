"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useCurrentUser } from "@/features/auth/api";
import { useT } from "@/lib/i18n/useT";
import { Loader2 } from "lucide-react";

/**
 * The gate in front of every authenticated screen — it wraps the whole
 * `(dashboard)` layout.
 */
export function AuthGuard({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { t } = useT();
  const { data: user, isLoading, isError } = useCurrentUser();

  useEffect(() => {
    if (!isLoading && (isError || !user)) {
      router.replace("/login");
    }
  }, [isLoading, isError, user, router]);

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

  // `isError` as well as `!user`, not just `!user`. TanStack keeps the last
  // successful `data` when a refetch fails, so a session that has just been
  // revoked or expired leaves `user` populated while `isError` flips true —
  // and checking only `!user` kept the dashboard on screen until the redirect
  // above landed, for exactly the user whose access had just been taken away.
  if (isError || !user) {
    return null;
  }

  return <>{children}</>;
}
