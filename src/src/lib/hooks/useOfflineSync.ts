'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { apiClient } from '@/api/client';
import {
  getPendingRecords,
  markSynced,
  markSyncError,
  getPendingCount,
  type OfflineAttendanceRecord,
} from '@/lib/offline-queue';

export function useOfflineSync() {
  const [isOnline, setIsOnline] = useState(true);
  const [pendingCount, setPendingCount] = useState(0);
  const [syncing, setSyncing] = useState(false);
  const syncInProgress = useRef(false);

  useEffect(() => {
    if (typeof window === 'undefined') return;
    setIsOnline(navigator.onLine);

    function onOnline() { setIsOnline(true); }
    function onOffline() { setIsOnline(false); }

    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    return () => {
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []);

  const refreshCount = useCallback(async () => {
    try {
      const count = await getPendingCount();
      setPendingCount(count);
    } catch {
      // IndexedDB may not be available in some contexts
    }
  }, []);

  useEffect(() => {
    refreshCount();
    const interval = setInterval(refreshCount, 10000);
    return () => clearInterval(interval);
  }, [refreshCount]);

  const syncNow = useCallback(async (): Promise<{ synced: number; errors: number }> => {
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
        const { data } = await apiClient.post('/attendance/sync', { records: batchPayload });

        for (const result of data.results ?? []) {
          const matchingRecord = pending.find(
            (r: OfflineAttendanceRecord) => r.idempotency_key === result.idempotency_key,
          );
          if (!matchingRecord?.id) continue;

          if (result.status === 'created' || result.status === 'duplicate') {
            await markSynced(matchingRecord.id);
            synced++;
          } else {
            await markSyncError(matchingRecord.id, result.detail ?? 'Sync failed');
            errors++;
          }
        }
      } catch (err: unknown) {
        const e = err as { message?: string };
        for (const r of pending) {
          if (r.id) await markSyncError(r.id, e.message ?? 'Network error');
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

  return { isOnline, pendingCount, syncing, syncNow, refreshCount };
}
