import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { renderHook, act, waitFor } from "@testing-library/react";
import {
  resetOfflineSyncForTests,
  useOfflineSync,
} from "@/lib/hooks/useOfflineSync";
import { apiClient } from "@/api/client";
import * as offlineQueue from "@/lib/offline-queue";
import type { OfflineAttendanceRecord } from "@/lib/offline-queue";
import {
  getSyncActivity,
  resetSyncActivityForTests,
} from "@/lib/offline-sync-activity";

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
const EMP = "01HEMP0000000000000000001";

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
    captured_at: "2026-10-07T06:30:00.000Z",
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
    vi.spyOn(offlineQueue, "markRefused").mockResolvedValue(undefined);
    vi.spyOn(offlineQueue, "getRefusedRecords").mockResolvedValue([]);
    vi.spyOn(offlineQueue, "removeRecord").mockResolvedValue(undefined);
  });

  afterEach(() => {
    resetOfflineSyncForTests();
    resetSyncActivityForTests();
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  it("tells the page it sent the punch, so the banner can say so", async () => {
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    vi.spyOn(apiClient, "post").mockResolvedValue({
      data: { results: [{ idempotency_key: "key-1", status: "created" }] },
    } as never);
    const { result } = renderHook(() => useOfflineSync(EMP));

    await act(async () => {
      await result.current.syncNow();
    });

    const activity = getSyncActivity();
    expect(activity.sendingPunches).toBe(0);
    expect(activity.lastSent).toMatchObject({ punches: 1, visits: 0 });
  });

  it("reports nothing sent when the network fails", async () => {
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    vi.spyOn(apiClient, "post").mockRejectedValue(new Error("Network Error"));
    const { result } = renderHook(() => useOfflineSync(EMP));

    await act(async () => {
      await result.current.syncNow();
    });

    const activity = getSyncActivity();
    expect(activity.sendingPunches).toBe(0);
    expect(activity.lastSent).toBeNull();
    // And says why the punch is still here, so the banner can.
    expect(activity.punchesWaitingBecause).toBe("unreachable");
  });

  it.each([
    [500, "server"],
    [429, "busy"],
  ] as const)("says the server answered %i", async (status, why) => {
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    vi.spyOn(apiClient, "post").mockRejectedValue(
      Object.assign(new Error("failed"), { response: { status } }),
    );
    const { result } = renderHook(() => useOfflineSync(EMP));

    await act(async () => {
      await result.current.syncNow();
    });

    expect(getSyncActivity().punchesWaitingBecause).toBe(why);
  });

  it("clears the reason when the next run sends the punch", async () => {
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    const post = vi
      .spyOn(apiClient, "post")
      .mockRejectedValueOnce(new Error("Network Error"));
    const { result } = renderHook(() => useOfflineSync(EMP));
    await act(async () => {
      await result.current.syncNow();
    });
    expect(getSyncActivity().punchesWaitingBecause).toBe("unreachable");

    post.mockResolvedValue({
      data: { results: [{ idempotency_key: "key-1", status: "created" }] },
    } as never);
    await act(async () => {
      await result.current.syncNow();
    });

    expect(getSyncActivity().punchesWaitingBecause).toBeNull();
  });

  it("marks a created record synced so it is not sent twice", async () => {
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    const post = vi.spyOn(apiClient, "post").mockResolvedValue({
      data: { results: [{ idempotency_key: "key-1", status: "created" }] },
    } as never);

    const { result } = renderHook(() => useOfflineSync(EMP));

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
          // Required by OfflineSyncRequest; without it every batch was a
          // 422 and no offline punch ever arrived (N49). This assertion did
          // not check it, which is how that went unnoticed.
          timestamp: "2026-10-07T06:30:00.000Z",
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

    const { result } = renderHook(() => useOfflineSync(EMP));

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

    const { result } = renderHook(() => useOfflineSync(EMP));

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

    const { result } = renderHook(() => useOfflineSync(EMP));

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

    const { result } = renderHook(() => useOfflineSync(EMP));

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

    const { result } = renderHook(() => useOfflineSync(EMP));

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

    const { result } = renderHook(() => useOfflineSync(EMP));

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
    const { result } = renderHook(() => useOfflineSync(EMP));

    let outcome!: { synced: number; errors: number };
    await act(async () => {
      outcome = await result.current.syncNow();
    });

    expect(post).not.toHaveBeenCalled();
    expect(outcome).toEqual({ synced: 0, errors: 0 });
  });

  it("surfaces the pending count from the queue", async () => {
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(4);

    const { result } = renderHook(() => useOfflineSync(EMP));

    await waitFor(() => expect(result.current.pendingCount).toBe(4));
  });

  describe("in batches", () => {
    function many(n: number) {
      return Array.from({ length: n }, (_, i) =>
        record({ id: i + 1, idempotency_key: `k-${i + 1}` }),
      );
    }
    type Body = { records: { idempotency_key: string }[] };
    const keysOfBody = (body: unknown) =>
      (body as Body).records.map((r) => r.idempotency_key);
    const created = (keys: string[]) =>
      ({
        data: {
          results: keys.map((k) => ({ idempotency_key: k, status: "created" })),
        },
      }) as never;

    it("sends the queue twenty at a time, in order", async () => {
      vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue(many(45));
      const post = vi
        .spyOn(apiClient, "post")
        .mockImplementation(async (_url, body) => created(keysOfBody(body)));

      const { result } = renderHook(() => useOfflineSync(EMP));
      let outcome!: { synced: number; errors: number };
      await act(async () => {
        outcome = await result.current.syncNow();
      });

      expect(post.mock.calls.map((c) => keysOfBody(c[1]).length)).toEqual([
        20, 20, 5,
      ]);
      expect(post.mock.calls.flatMap((c) => keysOfBody(c[1]))).toEqual(
        many(45).map((r) => r.idempotency_key),
      );
      expect(outcome).toEqual({ synced: 45, errors: 0 });
    });

    it("stops at the first batch that gets no answer, and keeps the rest", async () => {
      vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue(many(45));
      const post = vi
        .spyOn(apiClient, "post")
        .mockRejectedValue(new Error("Network Error"));

      const { result } = renderHook(() => useOfflineSync(EMP));
      let outcome!: { synced: number; errors: number };
      await act(async () => {
        outcome = await result.current.syncNow();
      });

      expect(post).toHaveBeenCalledTimes(1);
      expect(offlineQueue.markSyncError).toHaveBeenCalledTimes(20);
      expect(offlineQueue.markSynced).not.toHaveBeenCalled();
      expect(outcome).toEqual({ synced: 0, errors: 20 });
    });

    it.each([401, 419, 429, 500, 503])(
      "stops the run on a %i and leaves every record pending",
      async (status) => {
        vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue(many(30));
        const post = vi
          .spyOn(apiClient, "post")
          .mockRejectedValue({ message: "no", response: { status } });

        const { result } = renderHook(() => useOfflineSync(EMP));
        await act(async () => {
          await result.current.syncNow();
        });

        expect(post).toHaveBeenCalledTimes(1);
        expect(offlineQueue.markRefused).not.toHaveBeenCalled();
        expect(offlineQueue.markSyncError).toHaveBeenCalledTimes(20);
      },
    );

    it("sends a batch one at a time when it is too large, and carries on after", async () => {
      vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue(many(3));
      const post = vi
        .spyOn(apiClient, "post")
        .mockImplementation(async (_url, body) => {
          const keys = keysOfBody(body);
          if (keys.length > 1) {
            throw { message: "big", response: { status: 413 } };
          }
          return created(keys);
        });

      const { result } = renderHook(() => useOfflineSync(EMP));
      let outcome!: { synced: number; errors: number };
      await act(async () => {
        outcome = await result.current.syncNow();
      });

      expect(post.mock.calls.map((c) => keysOfBody(c[1]))).toEqual([
        ["k-1", "k-2", "k-3"],
        ["k-1"],
        ["k-2"],
        ["k-3"],
      ]);
      expect(outcome).toEqual({ synced: 3, errors: 0 });
    });

    it("refuses the one record that is still refused alone, and sends the others", async () => {
      vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue(many(3));
      vi.spyOn(apiClient, "post").mockImplementation(async (_url, body) => {
        const keys = keysOfBody(body);
        if (keys.length > 1) {
          throw { message: "no", response: { status: 422 } };
        }
        if (keys[0] === "k-2") {
          throw { message: "no", response: { status: 413 } };
        }
        return created(keys);
      });

      const { result } = renderHook(() => useOfflineSync(EMP));
      let outcome!: { synced: number; errors: number };
      await act(async () => {
        outcome = await result.current.syncNow();
      });

      expect(offlineQueue.markRefused).toHaveBeenCalledTimes(1);
      expect(offlineQueue.markRefused).toHaveBeenCalledWith(2);
      expect(offlineQueue.markSynced).toHaveBeenCalledWith(1);
      expect(offlineQueue.markSynced).toHaveBeenCalledWith(3);
      expect(offlineQueue.markSyncError).not.toHaveBeenCalled();
      expect(outcome).toEqual({ synced: 2, errors: 1 });
    });

    it("moves a punch the server calls final out of the pending list", async () => {
      vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue(many(2));
      vi.spyOn(apiClient, "post").mockResolvedValue({
        data: {
          results: [
            {
              idempotency_key: "k-1",
              status: "error",
              detail: "This day is closed.",
              final: true,
            },
            { idempotency_key: "k-2", status: "created" },
          ],
        },
      } as never);

      const { result } = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await result.current.syncNow();
      });

      expect(offlineQueue.markRefused).toHaveBeenCalledWith(
        1,
        "This day is closed.",
      );
      expect(offlineQueue.markSyncError).not.toHaveBeenCalled();
      expect(offlineQueue.markSynced).toHaveBeenCalledWith(2);
    });

    it("keeps a punch pending when the error is not final", async () => {
      vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue(many(2));
      vi.spyOn(apiClient, "post").mockResolvedValue({
        data: {
          results: [
            {
              idempotency_key: "k-1",
              status: "error",
              detail: "Try again",
              final: false,
            },
            { idempotency_key: "k-2", status: "error", detail: "Unmarked" },
          ],
        },
      } as never);

      const { result } = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await result.current.syncNow();
      });

      expect(offlineQueue.markRefused).not.toHaveBeenCalled();
      expect(offlineQueue.markSyncError).toHaveBeenCalledWith(1, "Try again");
      expect(offlineQueue.markSyncError).toHaveBeenCalledWith(2, "Unmarked");
    });
  });

  describe("on a phone shared by two people", () => {
    it("sends only the signed-in person's punches", async () => {
      vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([
        record({ id: 1, idempotency_key: "mine" }),
        record({
          id: 2,
          idempotency_key: "theirs",
          employee_public_id: "01HOTHER00000000000000002",
        }),
      ]);
      const post = vi.spyOn(apiClient, "post").mockResolvedValue({
        data: { results: [{ idempotency_key: "mine", status: "created" }] },
      } as never);

      const { result } = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await result.current.syncNow();
      });

      const sent = (
        post.mock.calls[0][1] as { records: { idempotency_key: string }[] }
      ).records;
      expect(sent.map((r) => r.idempotency_key)).toEqual(["mine"]);
    });

    it("sends nothing while nobody is known to be signed in", async () => {
      vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
      const post = vi.spyOn(apiClient, "post");

      const { result } = renderHook(() => useOfflineSync(null));
      await act(async () => {
        await result.current.syncNow();
      });

      expect(post).not.toHaveBeenCalled();
      expect(result.current.pendingCount).toBe(0);
    });

    it("counts and lists only the signed-in person's, and removes a refused one", async () => {
      vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(2);
      vi.spyOn(offlineQueue, "getRefusedRecords").mockResolvedValue([
        record({ id: 9, synced: 2, sync_error: "This day is closed." }),
      ]);

      const { result } = renderHook(() => useOfflineSync(EMP));
      await waitFor(() => expect(result.current.refused).toHaveLength(1));
      expect(offlineQueue.getPendingCount).toHaveBeenCalledWith(EMP);
      expect(offlineQueue.getRefusedRecords).toHaveBeenCalledWith(EMP);

      await act(async () => {
        await result.current.removeRefused(9);
      });
      expect(offlineQueue.removeRecord).toHaveBeenCalledWith(9);
    });
  });
});

describe("useOfflineSync, from any page and again by itself", () => {
  const NETWORK = Object.assign(new Error("Network Error"), {});
  const created = {
    data: { results: [{ idempotency_key: "key-1", status: "created" }] },
  } as never;

  beforeEach(() => {
    setNavigatorOnline(true);
    vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(1);
    vi.spyOn(offlineQueue, "getPendingRecords").mockResolvedValue([record()]);
    vi.spyOn(offlineQueue, "markSynced").mockResolvedValue(undefined);
    vi.spyOn(offlineQueue, "markSyncError").mockResolvedValue(undefined);
    vi.spyOn(offlineQueue, "markRefused").mockResolvedValue(undefined);
    vi.spyOn(offlineQueue, "getRefusedRecords").mockResolvedValue([]);
    vi.spyOn(offlineQueue, "clearSyncedRecords").mockResolvedValue(undefined);
  });

  afterEach(() => {
    resetOfflineSyncForTests();
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  it("sends once when two instances are mounted, the shell's agent and the punch screen", async () => {
    let release!: (v: unknown) => void;
    const post = vi
      .spyOn(apiClient, "post")
      .mockImplementation(() => new Promise((r) => (release = r)) as never);

    const a = renderHook(() => useOfflineSync(EMP));
    const b = renderHook(() => useOfflineSync(EMP));
    await act(async () => {
      void a.result.current.syncNow();
      void b.result.current.syncNow();
    });

    expect(post).toHaveBeenCalledTimes(1);

    await act(async () => {
      release(created);
    });
  });

  it("tells the other instance the queue changed", async () => {
    vi.spyOn(apiClient, "post").mockResolvedValue(created);
    const count = vi.spyOn(offlineQueue, "getPendingCount");

    const a = renderHook(() => useOfflineSync(EMP));
    renderHook(() => useOfflineSync(EMP));
    await waitFor(() => expect(count).toHaveBeenCalled());
    count.mockClear();

    await act(async () => {
      await a.result.current.syncNow();
    });

    // a reads once itself and once for the event; b reads once for the event.
    expect(count.mock.calls.length).toBeGreaterThanOrEqual(3);
  });

  it("clears what is already on the server after a run that sent something", async () => {
    vi.spyOn(apiClient, "post").mockResolvedValue(created);

    const { result } = renderHook(() => useOfflineSync(EMP));
    await act(async () => {
      await result.current.syncNow();
    });

    expect(offlineQueue.clearSyncedRecords).toHaveBeenCalled();
  });

  it("does not clear anything after a run that sent nothing", async () => {
    vi.spyOn(apiClient, "post").mockRejectedValue(NETWORK);

    const { result } = renderHook(() => useOfflineSync(EMP));
    await act(async () => {
      await result.current.syncNow();
    });

    expect(offlineQueue.clearSyncedRecords).not.toHaveBeenCalled();
  });

  describe("retrying after a failed send", () => {
    beforeEach(() => {
      // Nothing counted, so the mount does not start a run of its own and the
      // test alone decides when one happens.
      vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(0);
    });

    async function tick(ms: number) {
      await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
      });
    }

    it("tries again after 30 s, 60 s, 2 min, 5 min, then every 5 min", async () => {
      vi.useFakeTimers();
      const post = vi.spyOn(apiClient, "post").mockRejectedValue(NETWORK);

      const { result } = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await result.current.syncNow();
      });
      const base = post.mock.calls.length;

      for (const [wait, expected] of [
        [30_000, 1],
        [60_000, 2],
        [120_000, 3],
        [300_000, 4],
        [300_000, 5],
      ] as const) {
        await tick(wait - 1);
        expect(post.mock.calls.length).toBe(base + expected - 1);
        await tick(1);
        expect(post.mock.calls.length).toBe(base + expected);
      }
    });

    it("starts the schedule over after a run that sent something", async () => {
      vi.useFakeTimers();
      const post = vi
        .spyOn(apiClient, "post")
        .mockRejectedValueOnce(NETWORK)
        .mockRejectedValueOnce(NETWORK)
        .mockResolvedValueOnce(created)
        .mockRejectedValue(NETWORK);

      const { result } = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await result.current.syncNow();
      });
      await tick(30_000);
      await tick(60_000);
      expect(post.mock.calls.length).toBeGreaterThanOrEqual(3);

      await act(async () => {
        await result.current.syncNow();
      });
      const before = post.mock.calls.length;
      await tick(29_999);
      expect(post.mock.calls.length).toBe(before);
      await tick(1);
      expect(post.mock.calls.length).toBe(before + 1);
    });

    it("does not retry a punch the server refused for good", async () => {
      vi.useFakeTimers();
      const post = vi.spyOn(apiClient, "post").mockResolvedValue({
        data: {
          results: [
            {
              idempotency_key: "key-1",
              status: "error",
              final: true,
              detail: "This day is closed.",
            },
          ],
        },
      } as never);

      const { result } = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await result.current.syncNow();
      });
      const base = post.mock.calls.length;
      await tick(600_000);

      expect(post.mock.calls.length).toBe(base);
    });

    it("does not retry while the browser is offline", async () => {
      vi.useFakeTimers();
      const post = vi.spyOn(apiClient, "post").mockRejectedValue(NETWORK);

      const { result } = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await result.current.syncNow();
      });
      const base = post.mock.calls.length;

      setNavigatorOnline(false);
      await act(async () => {
        window.dispatchEvent(new Event("offline"));
      });
      await tick(600_000);

      expect(post.mock.calls.length).toBe(base);
    });

    it("stops retrying when the page unmounts", async () => {
      vi.useFakeTimers();
      const post = vi.spyOn(apiClient, "post").mockRejectedValue(NETWORK);

      const { result, unmount } = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await result.current.syncNow();
      });
      const base = post.mock.calls.length;
      unmount();
      await tick(600_000);

      expect(post.mock.calls.length).toBe(base);
    });

    it("leaves no timer behind when the page unmounts during a send", async () => {
      vi.useFakeTimers();
      let fail!: (e: unknown) => void;
      const post = vi
        .spyOn(apiClient, "post")
        .mockImplementationOnce(
          () => new Promise((_, reject) => (fail = reject)) as never,
        )
        .mockRejectedValue(NETWORK);

      const { result, unmount } = renderHook(() => useOfflineSync(EMP));
      let run!: Promise<unknown>;
      await act(async () => {
        run = result.current.syncNow();
      });
      unmount();
      await act(async () => {
        fail(NETWORK);
        await run;
      });
      await tick(600_000);

      expect(post).toHaveBeenCalledTimes(1);
    });

    it("keeps the backoff when another instance mounts with punches waiting", async () => {
      vi.useFakeTimers();
      const post = vi.spyOn(apiClient, "post").mockRejectedValue(NETWORK);

      const a = renderHook(() => useOfflineSync(EMP));
      await act(async () => {
        await a.result.current.syncNow();
      });
      const base = post.mock.calls.length;

      // The punch screen opens and finds a punch waiting.
      vi.spyOn(offlineQueue, "getPendingCount").mockResolvedValue(1);
      renderHook(() => useOfflineSync(EMP));
      await tick(29_999);
      expect(post.mock.calls.length).toBe(base);

      await tick(1);
      expect(post.mock.calls.length).toBe(base + 1);
    });
  });
});
