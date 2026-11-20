"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { apiClient } from "@/api/client";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import { splitIntoBatches, toSyncPayload } from "@/lib/offline-batch";
import {
  getPendingRecords,
  getRefusedRecords,
  markRefused,
  markSynced,
  markSyncError,
  getPendingCount,
  removeRecord,
  type OfflineAttendanceRecord,
} from "@/lib/offline-queue";

/** What the sync endpoint says about one punch (see OfflineSyncResult). */
interface SyncResult {
  idempotency_key: string;
  status: "created" | "duplicate" | "error";
  detail?: string;
  /** On an error: true when this exact punch will never be accepted. */
  final?: boolean;
}

function statusOf(err: unknown): number | null {
  const status = (err as { response?: { status?: number } })?.response?.status;
  return typeof status === "number" ? status : null;
}

/**
 * Sends the signed-in person's punches saved on this phone.
 *
 * A shared phone can hold other people's punches too. Those are neither sent,
 * counted nor shown here: they stay for their owner, who sends them when they
 * sign in. With no one signed in (`null`) nothing is sent.
 */
export function useOfflineSync(employeePublicId: string | null) {
  // Shared with OfflineBanner, which held an identical copy of the listener
  // block. Two independent copies of "am I online" could disagree.
  const { isOnline } = useOfflineStatus();
  const [pendingCount, setPendingCount] = useState(0);
  const [refused, setRefused] = useState<OfflineAttendanceRecord[]>([]);
  const [syncing, setSyncing] = useState(false);
  const syncInProgress = useRef(false);

  const refreshCount = useCallback(async () => {
    if (!employeePublicId) {
      setPendingCount(0);
      setRefused([]);
      return;
    }
    try {
      setPendingCount(await getPendingCount(employeePublicId));
      setRefused(await getRefusedRecords(employeePublicId));
    } catch {
      // IndexedDB may not be available in some contexts
    }
  }, [employeePublicId]);

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
    if (!employeePublicId || syncInProgress.current || !navigator.onLine) {
      return { synced: 0, errors: 0 };
    }

    syncInProgress.current = true;
    setSyncing(true);

    let synced = 0;
    let errors = 0;

    // Sends one batch. "stop" means the rest of the run should not be tried:
    // the server did not answer, or answered that nothing will work for now.
    async function sendBatch(
      batch: OfflineAttendanceRecord[],
    ): Promise<"continue" | "stop"> {
      let results: SyncResult[];
      try {
        const { data } = await apiClient.post("/attendance/sync", {
          records: batch.map((r) => toSyncPayload(r, new Date().toISOString())),
        });
        results = data?.results ?? [];
      } catch (err: unknown) {
        const status = statusOf(err);
        if (status === 413 || status === 422) {
          // Too large or not valid as a group. One at a time finds the punch
          // at fault, and the others still go.
          if (batch.length > 1) {
            for (const r of batch) {
              if ((await sendBatch([r])) === "stop") return "stop";
            }
            return "continue";
          }
          // Alone and still refused: it can never be sent.
          const [only] = batch;
          if (only?.id) await markRefused(only.id);
          errors++;
          return "continue";
        }
        // No answer, signed out, a stale token, a rate limit or a server
        // fault: all may pass. Keep the records and leave the later batches
        // (a check-out must not overtake its check-in).
        const e = err as { message?: string };
        for (const r of batch) {
          if (r.id) await markSyncError(r.id, e.message ?? "Network error");
        }
        errors += batch.length;
        return "stop";
      }

      for (const result of results) {
        const matchingRecord = batch.find(
          (r) => r.idempotency_key === result.idempotency_key,
        );
        if (!matchingRecord?.id) continue;

        if (result.status === "created" || result.status === "duplicate") {
          await markSynced(matchingRecord.id);
          synced++;
        } else if (result.final === true) {
          await markRefused(matchingRecord.id, result.detail ?? null);
          errors++;
        } else {
          await markSyncError(
            matchingRecord.id,
            result.detail ?? "Sync failed",
          );
          errors++;
        }
      }
      return "continue";
    }

    try {
      const pending = (await getPendingRecords(employeePublicId)).filter(
        (r) => r.employee_public_id === employeePublicId,
      );
      if (pending.length === 0) {
        return { synced: 0, errors: 0 };
      }

      const clock = new Date().toISOString();
      const batches = splitIntoBatches(
        pending,
        (r) => JSON.stringify(toSyncPayload(r, clock)).length,
      );
      for (const batch of batches) {
        if ((await sendBatch(batch)) === "stop") break;
      }
    } finally {
      syncInProgress.current = false;
      setSyncing(false);
      await refreshCount();
    }

    return { synced, errors };
  }, [employeePublicId, refreshCount]);

  /** The person has read why a punch was refused and wants it gone. */
  const removeRefused = useCallback(
    async (id: number) => {
      try {
        await removeRecord(id);
      } catch {
        // Left in place; it is shown again.
      }
      await refreshCount();
    },
    [refreshCount],
  );

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

  return {
    isOnline,
    pendingCount,
    refused,
    syncing,
    syncNow,
    refreshCount,
    removeRefused,
  };
}
