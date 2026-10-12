import "fake-indexeddb/auto";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { enqueueOfflineRecord, getAllRecords } from "@/lib/offline-queue";

/**
 * Background Sync for punches recorded offline.
 *
 * The worker listened for `sync-attendance` but only messaged open windows,
 * and nothing registered the tag, so a punch queued with no signal waited
 * until the app was next opened. It now sends the queue itself when no ETHR
 * window is open: the same request the app makes, with the session cookie,
 * the CSRF token and the tenant header the page would have sent.
 */

type SyncListener = (event: {
  tag: string;
  waitUntil: (p: Promise<unknown>) => void;
}) => void;

function loadWorker(options: {
  clients?: { postMessage: (m: unknown) => void }[];
  fetch: (url: string, init: RequestInit) => Promise<Response>;
  xsrf?: string | null;
}) {
  const source = readFileSync(resolve(__dirname, "../../public/sw.js"), "utf8");
  const listeners: Record<string, SyncListener> = {};
  const showNotification = vi.fn(async () => {});
  const self = {
    addEventListener: (type: string, listener: SyncListener) => {
      listeners[type] = listener;
    },
    clients: { matchAll: async () => options.clients ?? [] },
    cookieStore:
      options.xsrf === null
        ? undefined
        : {
            get: async (name: string) =>
              name === "XSRF-TOKEN"
                ? { value: encodeURIComponent(options.xsrf ?? "tok=") }
                : null,
          },
    registration: { showNotification },
    Notification: { permission: "granted" },
  };
  const caches = { open: async () => ({}), match: async () => undefined };
  new Function("self", "caches", "fetch", "indexedDB", source)(
    self,
    caches,
    options.fetch,
    indexedDB,
  );

  async function fireSync() {
    let done: Promise<unknown> = Promise.resolve();
    listeners.sync({
      tag: "sync-attendance",
      waitUntil: (p) => {
        done = p;
      },
    });
    await done;
  }

  return { fireSync, showNotification };
}

async function clearQueue() {
  // Cleared rather than deleted: deleteDatabase waits for every open
  // connection to close, and the queue module keeps its own open.
  await new Promise<void>((done, fail) => {
    const open = indexedDB.open("ethr-offline", 1);
    open.onupgradeneeded = () => {
      const store = open.result.createObjectStore("attendance_queue", {
        keyPath: "id",
        autoIncrement: true,
      });
      store.createIndex("synced", "synced", { unique: false });
    };
    open.onsuccess = () => {
      const db = open.result;
      const tx = db.transaction("attendance_queue", "readwrite");
      tx.objectStore("attendance_queue").clear();
      tx.oncomplete = () => {
        db.close();
        done();
      };
      tx.onerror = () => fail(tx.error);
    };
    open.onerror = () => fail(open.error);
  });
}

describe("service worker background sync", () => {
  beforeEach(async () => {
    await clearQueue();
    await enqueueOfflineRecord({
      employee_public_id: "01HQZX3NDJKMNP4RSTVWXY5Z6A",
      type: "check_in",
      idempotency_key: "offline-1",
      offline_token: "offline-1",
      latitude: 9.01,
      longitude: 38.75,
      captured_at: "2026-10-13T05:20:00.000Z",
      tenant: "acme",
      locale: "en",
    });
  });

  it("sends the queue itself when no ETHR window is open", async () => {
    const fetch = vi.fn(async () =>
      Response.json({
        results: [{ idempotency_key: "offline-1", status: "created" }],
      }),
    );
    const { fireSync, showNotification } = loadWorker({ fetch });

    await fireSync();

    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe("/api/v1/attendance/sync");
    expect(init.credentials).toBe("include");
    const headers = init.headers as Record<string, string>;
    expect(headers["X-XSRF-TOKEN"]).toBe("tok=");
    expect(headers["X-Tenant"]).toBe("acme");
    const body = JSON.parse(String(init.body));
    expect(body.records[0]).toMatchObject({
      type: "check_in",
      timestamp: "2026-10-13T05:20:00.000Z",
      idempotency_key: "offline-1",
    });

    const [record] = await getAllRecords();
    expect(record.synced).toBe(1);
    expect(showNotification).toHaveBeenCalledOnce();
  });

  it("leaves the sending to the page when one is open", async () => {
    const fetch = vi.fn();
    const postMessage = vi.fn();
    const { fireSync } = loadWorker({ fetch, clients: [{ postMessage }] });

    await fireSync();

    expect(postMessage).toHaveBeenCalledWith({ type: "SYNC_ATTENDANCE" });
    expect(fetch).not.toHaveBeenCalled();
  });

  it("keeps the queue when signed out, for the app to send later", async () => {
    const fetch = vi.fn(async () => new Response("{}", { status: 401 }));
    const { fireSync, showNotification } = loadWorker({ fetch });

    await fireSync();

    const [record] = await getAllRecords();
    expect(record.synced).toBe(0);
    expect(showNotification).not.toHaveBeenCalled();
  });

  it("does not try without a CSRF token to send", async () => {
    const fetch = vi.fn();
    const { fireSync } = loadWorker({ fetch, xsrf: null });

    await fireSync();

    expect(fetch).not.toHaveBeenCalled();
  });
});
