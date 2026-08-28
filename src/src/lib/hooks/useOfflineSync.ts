"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { apiClient } from "@/api/client";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import {
  getPendingRecords,
  markSynced,
  markSyncError,
  getPendingCount,
  type OfflineAttendanceRecord,
} from "@/lib/offline-queue";

export function useOfflineSync() {
  // Shared with OfflineBanner, which held an identical copy of the listener
  // block. Two independent copies of "am I online" could disagree.
  const { isOnline } = useOfflineStatus();
  const [pendingCount, setPendingCount] = useState(0);
  const [syncing, setSyncing] = useState(false);
  const syncInProgress = useRef(false);

  const refreshCount = useCallback(async () => {
    try {
      const count = await getPendingCount();
      setPendingCount(count);
    } catch {
      // IndexedDB may not be available in some contexts
    }
  }, []);

  useEffect(() => {
    // Genuinely synchronizing with an external system (IndexedDB) via polling,
    // not deriving state from props — the documented exception to this rule.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    refreshCount();
    const interval = setInterval(refreshCount, 10000);
    return () => clearInterval(interval);
  }, [refreshCount]);

  const syncNow = useCallback(async (): Promise<{
    synced: number;
    errors: number;
  }> => {
    if (syncInProgress.current || !navigator.onLine) {
      return { synced: 0, errors: 0 };
    }

    syncInProgress.current = true;
    setSyncing(true);

    let synced = 0;
    let errors = 0;

    try {
      const pending = await getPendingRecords();
      if (pending.length === 0) {
        return { synced: 0, errors: 0 };
      }

      const batchPayload = pending.map((r: OfflineAttendanceRecord) => ({
        employee_public_id: r.employee_public_id,
        type: r.type,
        idempotency_key: r.idempotency_key,
        offline_token: r.offline_token,
        latitude: r.latitude,
        longitude: r.longitude,
      }));

      try {
        const { data } = await apiClient.post("/attendance/sync", {
          records: batchPayload,
        });

        for (const result of data.results ?? []) {
          const matchingRecord = pending.find(
            (r: OfflineAttendanceRecord) =>
              r.idempotency_key === result.idempotency_key,
          );
          if (!matchingRecord?.id) continue;

          if (result.status === "created" || result.status === "duplicate") {
            await markSynced(matchingRecord.id);
            synced++;
          } else {
            await markSyncError(
              matchingRecord.id,
              result.detail ?? "Sync failed",
            );
            errors++;
          }
        }
      } catch (err: unknown) {
        const e = err as { message?: string };
        for (const r of pending) {
          if (r.id) await markSyncError(r.id, e.message ?? "Network error");
        }
        errors = pending.length;
      }
    } finally {
      syncInProgress.current = false;
      setSyncing(false);
      await refreshCount();
    }

    return { synced, errors };
  }, [refreshCount]);

  // Auto-sync when coming back online
  useEffect(() => {
    if (isOnline && pendingCount > 0) {
      syncNow();
    }
  }, [isOnline, pendingCount, syncNow]);

  // Respond to service worker background sync request
  useEffect(() => {
    if (typeof window === "undefined") return;
    function onSwSync() {
      syncNow();
    }
    window.addEventListener("ethr:sync-attendance", onSwSync);
    return () => window.removeEventListener("ethr:sync-attendance", onSwSync);
  }, [syncNow]);

  return { isOnline, pendingCount, syncing, syncNow, refreshCount };
}
