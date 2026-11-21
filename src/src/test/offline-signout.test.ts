import "fake-indexeddb/auto";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  enqueueOfflineRecord,
  getAllRecords,
  markRefused,
  markSynced,
} from "@/lib/offline-queue";
import * as offlineQueue from "@/lib/offline-queue";
import {
  clearOfflineOnSignOut,
  SIGN_OUT_CLEAR_WAIT_MS,
} from "@/lib/offline-signout";
import { queueVisit, queuedVisits } from "@/lib/attendance/visit-queue";

/**
 * Signing out takes what is already on the server off the phone, and the
 * offline grants. It must not take a punch or a visit that is not on the
 * server yet: that is a person's work, and nothing else holds a copy.
 */
const EMP = "01HEMP0000000000000000001";

function punch(key: string) {
  return {
    employee_public_id: EMP,
    type: "check_in" as const,
    idempotency_key: key,
    offline_token: key,
    photo: "data:image/jpeg;base64,AAAA",
    captured_at: "2026-11-20T06:00:00.000Z",
  };
}

async function emptyStore() {
  for (const r of await getAllRecords()) {
    await new Promise<void>((resolve, reject) => {
      const open = indexedDB.open("ethr-offline", 1);
      open.onsuccess = () => {
        const tx = open.result.transaction("attendance_queue", "readwrite");
        tx.objectStore("attendance_queue").delete(r.id as number);
        tx.oncomplete = () => {
          open.result.close();
          resolve();
        };
        tx.onerror = () => reject(tx.error);
      };
    });
  }
}

describe("clearOfflineOnSignOut", () => {
  beforeEach(async () => {
    localStorage.clear();
    await emptyStore();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("removes every offline grant and nothing else in storage", async () => {
    localStorage.setItem("ethr.offline_grant.A", "{}");
    localStorage.setItem("ethr.offline_grant.B", "{}");
    localStorage.setItem("locale", "am");

    await clearOfflineOnSignOut();

    expect(localStorage.getItem("ethr.offline_grant.A")).toBeNull();
    expect(localStorage.getItem("ethr.offline_grant.B")).toBeNull();
    expect(localStorage.getItem("locale")).toBe("am");
  });

  it("deletes sent punches and keeps waiting and refused ones", async () => {
    const sent = await enqueueOfflineRecord(punch("sent"));
    await enqueueOfflineRecord(punch("waiting"));
    const refused = await enqueueOfflineRecord(punch("refused"));
    await markSynced(sent);
    await markRefused(refused, "This day is closed.");

    await clearOfflineOnSignOut();

    const left = (await getAllRecords())
      .map((r) => `${r.idempotency_key}:${r.synced}`)
      .sort();
    expect(left).toEqual(["refused:2", "waiting:0"]);
  });

  it("keeps the field visits that are not on the server yet", async () => {
    queueVisit({ idempotency_key: "v1" } as never);

    await clearOfflineOnSignOut();

    expect(queuedVisits()).toHaveLength(1);
  });

  it("completes when IndexedDB throws", async () => {
    localStorage.setItem("ethr.offline_grant.A", "{}");
    vi.spyOn(offlineQueue, "clearSyncedRecords").mockRejectedValue(
      new Error("IndexedDB is missing"),
    );

    await expect(clearOfflineOnSignOut()).resolves.toBeUndefined();
    // The next step still ran.
    expect(localStorage.getItem("ethr.offline_grant.A")).toBeNull();
  });

  it("does not wait on a store that never answers", async () => {
    vi.useFakeTimers();
    try {
      localStorage.setItem("ethr.offline_grant.A", "{}");
      vi.spyOn(offlineQueue, "clearSyncedRecords").mockReturnValue(
        new Promise<void>(() => {}),
      );

      const done = clearOfflineOnSignOut();
      await vi.advanceTimersByTimeAsync(SIGN_OUT_CLEAR_WAIT_MS);

      await expect(done).resolves.toBeUndefined();
      expect(localStorage.getItem("ethr.offline_grant.A")).toBeNull();
    } finally {
      vi.useRealTimers();
    }
  });

  it("completes when storage refuses", async () => {
    vi.spyOn(Storage.prototype, "key").mockImplementation(() => {
      throw new Error("blocked");
    });

    await expect(clearOfflineOnSignOut()).resolves.toBeUndefined();
  });
});
