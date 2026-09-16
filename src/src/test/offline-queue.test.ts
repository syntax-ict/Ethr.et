import "fake-indexeddb/auto";
import { describe, it, expect, beforeEach } from "vitest";
import {
  enqueueOfflineRecord,
  getPendingRecords,
  getAllRecords,
  markSynced,
  markSyncError,
  clearSyncedRecords,
  getPendingCount,
} from "@/lib/offline-queue";

/**
 * The IndexedDB store that holds attendance punches captured with no signal.
 *
 * This is the layer beneath `useOfflineSync`, and the last copy of a punch that
 * exists anywhere. If a record leaves this store without reaching the server,
 * the employee worked a shift the system has no record of — and nothing
 * server-side can notice, because the punch never arrived.
 *
 * It sat at 2.7% while the banner displaying its count sat at 97%
 * (audit/BASELINE.md §12f). The tests below are about the queue keeping or
 * losing records, not about IndexedDB working.
 */
/**
 * Empty the store between tests rather than deleting the database.
 *
 * `offline-queue.ts` opens a fresh connection on every call and never closes
 * one, so by the second test several handles are open. `deleteDatabase()` waits
 * for all of them to close, fires `onblocked`, and hangs — which is exactly what
 * happened on the first run here: one test passed and the other eight timed out
 * in the hook. Clearing the object store needs no version change and is not
 * blocked by open connections.
 */
async function resetDatabase() {
  const db = await new Promise<IDBDatabase>((resolve, reject) => {
    const req = indexedDB.open("ethr-offline", 1);
    req.onupgradeneeded = () => {
      const database = req.result;
      if (!database.objectStoreNames.contains("attendance_queue")) {
        const store = database.createObjectStore("attendance_queue", {
          keyPath: "id",
          autoIncrement: true,
        });
        store.createIndex("synced", "synced", { unique: false });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });

  await new Promise<void>((resolve, reject) => {
    const tx = db.transaction("attendance_queue", "readwrite");
    const req = tx.objectStore("attendance_queue").clear();
    req.onsuccess = () => resolve();
    req.onerror = () => reject(req.error);
  });

  db.close();
}

function punch(overrides: Record<string, unknown> = {}) {
  return {
    employee_public_id: "01HEMP0000000000000000001",
    type: "check_in" as const,
    idempotency_key: "key-1",
    offline_token: "tok-1",
    captured_at: "2026-09-16T06:00:00.000Z",
    ...overrides,
  };
}

describe("offline queue", () => {
  beforeEach(async () => {
    await resetDatabase();
  });

  it("stores a punch as pending and returns its id", async () => {
    const id = await enqueueOfflineRecord(punch());

    expect(id).toBeGreaterThan(0);

    const all = await getAllRecords();
    expect(all).toHaveLength(1);
    expect(all[0]).toMatchObject({
      employee_public_id: "01HEMP0000000000000000001",
      idempotency_key: "key-1",
      sync_error: null,
    });
  });

  it("stores `synced` as a number, because IndexedDB cannot index booleans", async () => {
    // Not pedantry. getPendingRecords() reads through the `synced` index with
    // getAll(0). Storing a boolean here would make that index lookup match
    // nothing, getPendingRecords() would return [], and every queued punch
    // would be invisible to the sync engine while still sitting in the store.
    await enqueueOfflineRecord(punch());

    const [stored] = await getAllRecords();
    expect(typeof stored.synced).toBe("number");
    expect(stored.synced).toBe(0);
  });

  it("lists only unsynced records as pending", async () => {
    const first = await enqueueOfflineRecord(punch({ idempotency_key: "a" }));
    await enqueueOfflineRecord(punch({ idempotency_key: "b" }));

    await markSynced(first);

    const pending = await getPendingRecords();
    expect(pending).toHaveLength(1);
    expect(pending[0].idempotency_key).toBe("b");
    expect(await getPendingCount()).toBe(1);
  });

  it("keeps a failed record pending, so the next sync retries it", async () => {
    // The property that prevents data loss. A sync error must record *why* it
    // failed without removing the punch from the queue — if markSyncError also
    // set synced, a transient network failure would discard the only copy.
    const id = await enqueueOfflineRecord(punch());

    await markSyncError(id, "Network Error");

    const pending = await getPendingRecords();
    expect(pending).toHaveLength(1);
    expect(pending[0].sync_error).toBe("Network Error");
    expect(pending[0].synced).toBe(0);
  });

  it("clears the error when a retry succeeds", async () => {
    const id = await enqueueOfflineRecord(punch());
    await markSyncError(id, "Network Error");

    await markSynced(id);

    const [stored] = await getAllRecords();
    expect(stored.synced).toBe(1);
    expect(stored.sync_error).toBeNull();
    expect(await getPendingCount()).toBe(0);
  });

  it("never deletes an unsynced punch when clearing synced ones", async () => {
    // clearSyncedRecords is housekeeping, and housekeeping that can delete an
    // unsent punch is data loss on a timer.
    const sent = await enqueueOfflineRecord(punch({ idempotency_key: "sent" }));
    await enqueueOfflineRecord(punch({ idempotency_key: "unsent" }));
    await markSynced(sent);

    await clearSyncedRecords();

    const all = await getAllRecords();
    expect(all).toHaveLength(1);
    expect(all[0].idempotency_key).toBe("unsent");
    expect(all[0].synced).toBe(0);
  });

  it("does nothing, rather than throwing, for an id that is not there", async () => {
    // useOfflineSync calls markSynced with an id it matched from a server
    // response. A rejected promise here would abort the loop and leave the rest
    // of the batch unprocessed.
    await expect(markSynced(9999)).resolves.toBeUndefined();
    await expect(markSyncError(9999, "gone")).resolves.toBeUndefined();
  });

  it("reports an empty queue as zero rather than failing", async () => {
    expect(await getPendingCount()).toBe(0);
    expect(await getPendingRecords()).toEqual([]);
    expect(await getAllRecords()).toEqual([]);
  });

  it("keeps several punches independent", async () => {
    const a = await enqueueOfflineRecord(punch({ idempotency_key: "a" }));
    const b = await enqueueOfflineRecord(punch({ idempotency_key: "b" }));
    await enqueueOfflineRecord(punch({ idempotency_key: "c" }));

    await markSynced(a);
    await markSyncError(b, "Unknown employee");

    const pending = await getPendingRecords();
    expect(pending.map((r) => r.idempotency_key).sort()).toEqual(["b", "c"]);

    const errored = pending.find((r) => r.idempotency_key === "b");
    expect(errored?.sync_error).toBe("Unknown employee");
  });
});
