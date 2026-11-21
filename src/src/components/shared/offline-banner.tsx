"use client";

import { useEffect, useState } from "react";
import { WifiOff } from "lucide-react";
import { getPendingCount } from "@/lib/offline-queue";
import { readOfflineIdentity } from "@/features/auth/offline-identity";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import { useT } from "@/lib/i18n/useT";

/**
 * Persistent, global connectivity indicator. Deliberately does NOT trigger
 * a sync itself (OfflineSyncAgent, mounted beside it, does that). This just
 * reads state:
 * navigator.onLine plus the IndexedDB pending-record count.
 *
 * On a shared phone the count is the signed-in person's own, as the punch
 * screen shows it: the identity kept for offline start names them. With no
 * identity kept, every waiting punch is counted.
 */
export function OfflineBanner() {
  const { t } = useT();
  // Connectivity now comes from the shared hook rather than a copy of the
  // listener block that also lived in useOfflineSync — the two could briefly
  // disagree about whether the app was online.
  const { isOnline } = useOfflineStatus();
  const [pendingCount, setPendingCount] = useState(0);

  useEffect(() => {
    let cancelled = false;
    async function refresh() {
      try {
        const count = await getPendingCount(
          readOfflineIdentity()?.user.employee_public_id ?? null,
        );
        if (!cancelled) setPendingCount(count);
      } catch {
        // IndexedDB may not be available in some contexts
      }
    }
    refresh();
    const interval = setInterval(refresh, 10000);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, []);

  const visible = !isOnline || pendingCount > 0;

  // The live region stays mounted, so a screen reader hears "You're offline"
  // when it appears; a region that mounts with its text is not announced.
  return (
    <div role="status" aria-live="polite">
      {visible && (
        <div className="flex items-center justify-center gap-2 bg-warning-soft px-4 py-1.5 text-xs font-medium text-warning-on-soft">
          <WifiOff className="h-3.5 w-3.5" aria-hidden="true" />
          {!isOnline && <span>{t("offline.offline", "You're offline")}</span>}
          {pendingCount > 0 && (
            <span>
              {!isOnline && <>&middot; </>}
              {t("offline.pending_count", ":count records waiting to sync", {
                count: pendingCount,
              })}
            </span>
          )}
        </div>
      )}
    </div>
  );
}
