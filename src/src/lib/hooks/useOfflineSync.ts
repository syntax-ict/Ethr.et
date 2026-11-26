"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { apiClient } from "@/api/client";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import { splitIntoBatches, toSyncPayload } from "@/lib/offline-batch";
import {
  syncFinished,
  syncStarted,
  type SyncFailure,
} from "@/lib/offline-sync-activity";
import {
  clearSyncedRecords,
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

/**
 * One send at a time on this page, however many components mount the hook (the
 * shell's agent and the punch screen both do). A second send would post the
 * same punches again; the idempotency key makes that harmless, but it is two
 * requests for nothing.
 */
let syncInProgress = false;

/** Dispatched after a run so every mounted instance re-reads the queue. */
export const QUEUE_CHANGED_EVENT = "ethr:offline-queue-changed";

/** Wait before the next automatic try after a failed send, in order. */
export const RETRY_DELAYS_MS = [30_000, 60_000, 120_000, 300_000];

/**
 * One retry timer per page, like the guard above. While it is set, a send that
 * would start on its own (the pending count changing) waits for it instead, so
 * the backoff holds however many instances are mounted. Reconnecting, the
 * service worker and a tap on the button do not wait.
 */
let retryTimer: ReturnType<typeof setTimeout> | null = null;
let retryStep = 0;

/** The mounted instances; the timer runs one of them, never an unmounted one. */
const mountedRunners = new Set<{ run: () => Promise<unknown> }>();

function clearRetry() {
  if (retryTimer) clearTimeout(retryTimer);
  retryTimer = null;
}

function scheduleRetry() {
  clearRetry();
  if (mountedRunners.size === 0) return;
  const delay =
    RETRY_DELAYS_MS[Math.min(retryStep, RETRY_DELAYS_MS.length - 1)];
  retryStep++;
  retryTimer = setTimeout(() => {
    retryTimer = null;
    const [runner] = mountedRunners;
    if (runner) void runner.run();
  }, delay);
}

/** For tests: forget the page's timer and backoff between cases. */
export function resetOfflineSyncForTests() {
  clearRetry();
  retryStep = 0;
  syncInProgress = false;
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
  const syncNowRef = useRef<() => Promise<unknown>>(async () => undefined);
  const wasOnline = useRef(isOnline);

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
    if (!employeePublicId || syncInProgress || !navigator.onLine) {
      return { synced: 0, errors: 0 };
    }

    syncInProgress = true;
    setSyncing(true);

    let synced = 0;
    let errors = 0;
    // Errors that may pass on a later try. A punch refused for good is not one.
    let retryable = 0;
    let foundNothing = false;
    // Whether this run told the page it is sending, so it also says it ended.
    let announced = false;
    // Why punches were left behind, when it is the connection or the server.
    let failure: SyncFailure | null = null;

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
        failure =
          status === null
            ? "unreachable"
            : status === 429
              ? "busy"
              : status >= 500
                ? "server"
                : null;
        for (const r of batch) {
          if (r.id) await markSyncError(r.id, e.message ?? "Network error");
        }
        errors += batch.length;
        retryable += batch.length;
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
          retryable++;
        }
      }
      return "continue";
    }

    try {
      const pending = (await getPendingRecords(employeePublicId)).filter(
        (r) => r.employee_public_id === employeePublicId,
      );
      if (pending.length === 0) {
        foundNothing = true;
        return { synced: 0, errors: 0 };
      }

      syncStarted("punches", pending.length);
      announced = true;

      const clock = new Date().toISOString();
      const batches = splitIntoBatches(
        pending,
        (r) => JSON.stringify(toSyncPayload(r, clock)).length,
      );
      for (const batch of batches) {
        if ((await sendBatch(batch)) === "stop") break;
      }
    } finally {
      // The guard stays closed until the end: the count read below can start
      // another send through the auto-sync effect, and that one must see the
      // retry timer first.
      if (synced > 0) {
        // Already on the server: nothing to keep, and a selfie is the
        // heaviest thing on the phone.
        try {
          await clearSyncedRecords();
        } catch {
          // Left for the next run.
        }
      }
      if (synced > 0 || foundNothing) retryStep = 0;
      if (retryable > 0 && navigator.onLine) {
        scheduleRetry();
      } else {
        clearRetry();
      }
      await refreshCount();
      try {
        window.dispatchEvent(new Event(QUEUE_CHANGED_EVENT));
      } catch {
        // No window to tell.
      }
      syncInProgress = false;
      setSyncing(false);
      if (announced) {
        syncFinished("punches", synced, retryable > 0 ? failure : null);
      }
    }

    return { synced, errors };
  }, [employeePublicId, refreshCount]);

  useEffect(() => {
    syncNowRef.current = syncNow;
  }, [syncNow]);

  useEffect(() => {
    const runner = { run: () => syncNowRef.current() };
    mountedRunners.add(runner);
    return () => {
      mountedRunners.delete(runner);
      if (mountedRunners.size === 0) clearRetry();
    };
  }, []);

  // Another instance (or the shell's agent) changed the queue.
  useEffect(() => {
    if (typeof window === "undefined") return;
    function onChanged() {
      void refreshCount();
    }
    window.addEventListener(QUEUE_CHANGED_EVENT, onChanged);
    return () => window.removeEventListener(QUEUE_CHANGED_EVENT, onChanged);
  }, [refreshCount]);

  // Reconnecting starts the backoff over; going offline stops the timer.
  // Mounting is neither, so opening a page leaves the backoff as it is.
  useEffect(() => {
    if (wasOnline.current === isOnline) return;
    wasOnline.current = isOnline;
    retryStep = 0;
    clearRetry();
  }, [isOnline]);

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

  // Auto-sync when coming back online, or when punches are waiting and no
  // retry is already timed.
  useEffect(() => {
    if (isOnline && pendingCount > 0 && retryTimer === null) {
      // Starting a send is synchronizing with an external system (the server).
      // eslint-disable-next-line react-hooks/set-state-in-effect
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
