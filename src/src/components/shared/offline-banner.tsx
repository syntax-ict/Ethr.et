"use client";

import { useEffect, useState } from "react";
import { WifiOff } from "lucide-react";
import { getPendingCount } from "@/lib/offline-queue";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import { useT } from "@/lib/i18n/useT";

/**
 * Persistent, global connectivity indicator. Deliberately does NOT trigger
 * a sync itself (that stays owned by useOfflineSync on the attendance
 * check-in page) — mounting a second live sync loop here would race with
 * that page's own auto-sync-on-reconnect effect. This just reads state:
 * navigator.onLine plus the IndexedDB pending-record count.
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
        const count = await getPendingCount();
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

  if (isOnline && pendingCount === 0) return null;

  return (
    <div className="flex items-center justify-center gap-2 bg-warning-soft px-4 py-1.5 text-xs font-medium text-warning-on-soft">
      <WifiOff className="h-3.5 w-3.5" />
      {!isOnline && <span>{t("offline.offline", "You're offline")}</span>}
      {pendingCount > 0 && (
        <span>
          {!isOnline && <>&middot; </>}
          {pendingCount}{" "}
          {t("offline.pending_sync", "record(s) waiting to sync")}
        </span>
      )}
    </div>
  );
}
