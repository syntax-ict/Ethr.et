"use client";

import { useCallback, useRef, useState, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { useCurrentUser, useLogout } from "@/features/auth/api";
import { queuedVisits } from "@/lib/attendance/visit-queue";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import { useT } from "@/lib/i18n/useT";
import { getPendingCount } from "@/lib/offline-queue";

/** How long the pending-punch read may take before sign out goes ahead. */
export const SIGN_OUT_GUARD_READ_MS = 1000;

interface Pending {
  punches: number;
  visits: number;
  offline: boolean;
}

/** A read that neither answers nor fails counts as nothing waiting. */
async function readPunches(employeePublicId: string | null): Promise<number> {
  if (!employeePublicId) return 0;
  let timer: ReturnType<typeof setTimeout> | undefined;
  try {
    return await Promise.race([
      getPendingCount(employeePublicId),
      new Promise<number>((resolve) => {
        timer = setTimeout(() => resolve(0), SIGN_OUT_GUARD_READ_MS);
      }),
    ]);
  } catch {
    return 0;
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Field visits have no owner on the phone (the queue stores the request body,
 * which names no person), so this counts every visit waiting on this phone.
 */
function readVisits(): number {
  try {
    return queuedVisits().length;
  } catch {
    return 0;
  }
}

/**
 * Signing out of a phone that still holds unsent punches or visits, or that has
 * no connection, is worth one sentence first: the work is kept and sent at the
 * next sign in, but sign in needs the server. Anything else signs out at once.
 *
 * Render `signOutDialog` once, next to whatever calls `requestSignOut`.
 */
export function useGuardedSignOut(): {
  requestSignOut: () => void;
  signOutDialog: ReactNode;
} {
  const { t } = useT();
  const { data: user } = useCurrentUser();
  const logout = useLogout();
  const { isOffline } = useOfflineStatus();
  const [pending, setPending] = useState<Pending | null>(null);
  const checking = useRef(false);
  const cancelRef = useRef<HTMLButtonElement>(null);

  const employeePublicId =
    (user as { employee_public_id?: string | null } | undefined)
      ?.employee_public_id ?? null;

  const requestSignOut = useCallback(() => {
    if (checking.current) return;
    checking.current = true;
    void (async () => {
      let found: Pending = { punches: 0, visits: 0, offline: false };
      try {
        found = {
          punches: await readPunches(employeePublicId),
          visits: readVisits(),
          offline: isOffline,
        };
      } catch {
        // Never let the check stand between a person and signing out.
      } finally {
        checking.current = false;
      }
      if (found.punches > 0 || found.visits > 0 || found.offline) {
        setPending(found);
      } else {
        logout.mutate();
      }
    })();
  }, [employeePublicId, isOffline, logout]);

  const count = (
    n: number,
    oneKey: string,
    oneFallback: string,
    manyKey: string,
    manyFallback: string,
  ) =>
    n === 1
      ? t(oneKey, oneFallback, { count: n })
      : t(manyKey, manyFallback, { count: n });

  const signOutDialog = (
    <Dialog
      open={pending !== null}
      onOpenChange={(o) => !o && setPending(null)}
    >
      <DialogContent
        className="sm:max-w-md"
        onOpenAutoFocus={(e) => {
          e.preventDefault();
          cancelRef.current?.focus();
        }}
      >
        <DialogHeader>
          <DialogTitle>
            {t("auth.signout_guard.title", "Sign out of ETHR?")}
          </DialogTitle>
          <DialogDescription className="sr-only">
            {t("auth.signout_guard.title", "Sign out of ETHR?")}
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-2 text-sm text-muted-foreground">
          {pending && pending.punches > 0 && (
            <p>
              {count(
                pending.punches,
                "auth.signout_guard.punches_one",
                ":count punch on this phone has not been sent yet. It stays on this phone and is sent when you sign in again here.",
                "auth.signout_guard.punches_many",
                ":count punches on this phone have not been sent yet. They stay on this phone and are sent when you sign in again here.",
              )}
            </p>
          )}
          {pending && pending.visits > 0 && (
            <p>
              {count(
                pending.visits,
                "auth.signout_guard.visits_one",
                ":count field visit on this phone has not been sent yet. It stays on this phone and is sent when you sign in again here.",
                "auth.signout_guard.visits_many",
                ":count field visits on this phone have not been sent yet. They stay on this phone and are sent when you sign in again here.",
              )}
            </p>
          )}
          {pending?.offline && (
            <p>
              {t(
                "auth.signout_guard.offline",
                "You are offline. Until this phone is connected again, you cannot sign back in or punch on it.",
              )}
            </p>
          )}
        </div>
        <DialogFooter>
          <Button
            ref={cancelRef}
            variant="outline"
            onClick={() => setPending(null)}
          >
            {t("auth.signout_guard.stay", "Stay signed in")}
          </Button>
          <Button
            variant="destructive"
            onClick={() => {
              setPending(null);
              logout.mutate();
            }}
          >
            {t("auth.signout_guard.confirm", "Sign out anyway")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );

  return { requestSignOut, signOutDialog };
}
