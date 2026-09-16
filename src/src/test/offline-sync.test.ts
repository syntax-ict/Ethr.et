import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { renderHook, act, waitFor } from "@testing-library/react";
import { useOfflineSync } from "@/lib/hooks/useOfflineSync";
import { apiClient } from "@/api/client";
import * as offlineQueue from "@/lib/offline-queue";
import type { OfflineAttendanceRecord } from "@/lib/offline-queue";

/**
 * The offline attendance sync engine.
 *
 * ETHR is offline-first: a supervisor on a site with no signal punches
 * employees in, the record lands in IndexedDB, and this hook is what eventually
 * gets it to the server. Everything downstream — attendance, overtime, payroll —
 * depends on that journey completing.
 *
 * It had no tests at all, while `OfflineBanner`, the badge that *displays* the
 * pending count, sat at 97%. The UI that reports the queue was covered; the code
 * that empties it was not. This closes that inversion (audit/BASELINE.md §12f).
 *
 * Every case below is a way a punch is silently lost or double-counted, not a
 * rendering detail. The backend cannot compensate for any of them: a record that
 * never arrives cannot be reconciled server-side.
 */
function setNavigatorOnline(value: boolean) {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value,
  });
}

function record(
  overrides: Partial<OfflineAttendanceRecord> = {},
): OfflineAttendanceRecord {
  return {
    id: 1,
    employee_public_id: "01HEMP0000000000000000001",
    type: "check_in",
    idempotency_key: "key-1",
    offline_token: "tok-1",
    synced: false,
    ...overrides,
  } as OfflineAttendanceRecord;
}

describe("useOfflineSync", () => {
  beforeEach(() => {
    setNavigatorOnline(true);
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(0);
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([]);
    vi.spyOn(offlineQueue, "markSynced").mockResolvedValue(undefined);
    vi.spyOn(offlineQueue, "markSyncError").mockResolvedValue(undefined);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  it("marks a created record synced so it is not sent twice", async () => {
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({
      data: { results: [{ idempotency_key: "key-1", status: "created" }] },
    } as never);

    const { result } = renderHook(() => useOfflineSync());

    let outcome!: { synced: number; errors: number };
    await act(async () => {
      outcome = await result.current.syncNow();
    });

    expect(post).toHaveBeenCalledWith("/attendance/sync", {
      records: [
        expect.objectContaining({
          employee_public_id: "01HEMP0000000000000000001",
          idempotency_key: "key-1",
          offline_token: "tok-1",
        }),
      ],
    });
    expect(offlineQueue.markSynced).toHaveBeenCalledWith(1);
    expect(outcome).toEqual({ synced: 1, errors: 0 });
  });

  it("treats a duplicate as success, because a replay is proof it arrived", async () => {
    // Convention #10: every write carries an Idempotency-Key and a replay
    // returns was_duplicate rather than writing again. If this hook treated
    // `duplicate` as a failure it would leave the record pending forever and
    // retry it on every sync — the punch is already recorded server-side.
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    vi.spyOn(apiClient, "post").mockResolvedValue({
      data: { results: [{ idempotency_key: "key-1", status: "duplicate" }] },
    } as never);

    const { result } = renderHook(() => useOfflineSync());

    let outcome!: { synced: number; errors: number };
    await act(async () => {
      outcome = await result.current.syncNow();
    });

    expect(offlineQueue.markSynced).toHaveBeenCalledWith(1);
    expect(offlineQueue.markSyncError).not.toHaveBeenCalled();
    expect(outcome).toEqual({ synced: 1, errors: 0 });
  });

  it("records a per-record rejection as an error, not a success", async () => {
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    vi.spyOn(apiClient, "post").mockResolvedValue({
      data: {
        results: [
          {
            idempotency_key: "key-1",
            status: "error",
            detail: "Unknown employee",
          },
        ],
      },
    } as never);

    const { result } = renderHook(() => useOfflineSync());

    let outcome!: { synced: number; errors: number };
    await act(async () => {
      outcome = await result.current.syncNow();
    });

    expect(offlineQueue.markSyncError).toHaveBeenCalledWith(
      1,
      "Unknown employee",
    );
    expect(offlineQueue.markSynced).not.toHaveBeenCalled();
    expect(outcome).toEqual({ synced: 0, errors: 1 });
  });

  it("keeps a record pending when the network fails, rather than dropping it", async () => {
    // The case that matters most. A punch captured on a site with no signal must
    // survive a failed sync attempt; marking it synced here would delete the only
    // copy that exists.
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([
      record({ id: 7 }),
    ]);
    vi.spyOn(apiClient, "post").mockRejectedValue(new Error("Network Error"));

    const { result } = renderHook(() => useOfflineSync());

    let outcome!: { synced: number; errors: number };
    await act(async () => {
      outcome = await result.current.syncNow();
    });

    expect(offlineQueue.markSynced).not.toHaveBeenCalled();
    expect(offlineQueue.markSyncError).toHaveBeenCalledWith(7, "Network Error");
    expect(outcome).toEqual({ synced: 0, errors: 1 });
  });

  it("leaves a record alone when the server answers about a key it never sent", async () => {
    // A result whose idempotency_key matches nothing pending must not be applied
    // to some other record. Matching is by key, so a mismatch has to be a no-op
    // rather than falling through to the first record in the batch.
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    vi.spyOn(apiClient, "post").mockResolvedValue({
      data: {
        results: [{ idempotency_key: "some-other-key", status: "created" }],
      },
    } as never);

    const { result } = renderHook(() => useOfflineSync());

    let outcome!: { synced: number; errors: number };
    await act(async () => {
      outcome = await result.current.syncNow();
    });

    expect(offlineQueue.markSynced).not.toHaveBeenCalled();
    expect(offlineQueue.markSyncError).not.toHaveBeenCalled();
    expect(outcome).toEqual({ synced: 0, errors: 0 });
  });

  it("does not post at all when the browser is offline", async () => {
    setNavigatorOnline(false);
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    const post = vi.spyOn(apiClient, "post");

    const { result } = renderHook(() => useOfflineSync());

    let outcome!: { synced: number; errors: number };
    await act(async () => {
      outcome = await result.current.syncNow();
    });

    expect(post).not.toHaveBeenCalled();
    expect(outcome).toEqual({ synced: 0, errors: 0 });
  });

  it("does not run two syncs at once, which would send every record twice", async () => {
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);

    let release!: (value: unknown) => void;
    const inFlight = new Promise((resolve) => {
      release = resolve;
    });
    const post = vi.spyOn(apiClient, "post").mockImplementation(
      () =>
        inFlight.then(() => ({
          data: { results: [{ idempotency_key: "key-1", status: "created" }] },
        })) as never,
    );

    const { result } = renderHook(() => useOfflineSync());

    await act(async () => {
      const first = result.current.syncNow();
      const second = await result.current.syncNow();

      // The second call is refused by the in-progress guard and returns
      // immediately, before the first has been allowed to resolve.
      expect(second).toEqual({ synced: 0, errors: 0 });

      release(null);
      await first;
    });

    expect(post).toHaveBeenCalledTimes(1);
  });

  it("reports nothing to do when the queue is empty", async () => {
    const post = vi.spyOn(apiClient, "post");
    const { result } = renderHook(() => useOfflineSync());

    let outcome!: { synced: number; errors: number };
    await act(async () => {
      outcome = await result.current.syncNow();
    });

    expect(post).not.toHaveBeenCalled();
    expect(outcome).toEqual({ synced: 0, errors: 0 });
  });

  it("surfaces the pending count from the queue", async () => {
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(4);

    const { result } = renderHook(() => useOfflineSync());

    await waitFor(() => expect(result.current.pendingCount).toBe(4));
  });
});
