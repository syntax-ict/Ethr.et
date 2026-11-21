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
  matchAllFails?: boolean;
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
    clients: {
      matchAll: async () => {
        if (options.matchAllFails) throw new Error("no clients");
        return options.clients ?? [];
      },
    },
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

  async function firePush(payload: Record<string, unknown>) {
    let done: Promise<unknown> = Promise.resolve();
    (listeners.push as unknown as (e: unknown) => void)({
      data: { json: () => payload, text: () => "" },
      waitUntil: (p: Promise<unknown>) => {
        done = p;
      },
    });
    await done;
  }

  return { fireSync, firePush, showNotification };
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

  describe("in batches", () => {
    type Body = { records: { idempotency_key: string }[] };
    const keysOf = (init: RequestInit) =>
      (JSON.parse(String(init.body)) as Body).records.map(
        (r) => r.idempotency_key,
      );
    const callKeys = (fetch: { mock: { calls: unknown[][] } }) =>
      fetch.mock.calls.map((c) => keysOf(c[1] as RequestInit));

    async function queue(n: number, from = 2) {
      for (let i = 0; i < n; i++) {
        await enqueueOfflineRecord({
          employee_public_id: "01HQZX3NDJKMNP4RSTVWXY5Z6A",
          type: "check_in",
          idempotency_key: `offline-${from + i}`,
          offline_token: "t",
          captured_at: "2026-10-13T05:20:00.000Z",
          tenant: "acme",
          locale: "en",
        });
      }
    }

    it("sends twenty at a time, in order", async () => {
      await queue(24); // 25 with the one queued before each test
      const fetch = vi.fn(async (_url: string, init: RequestInit) =>
        Response.json({
          results: keysOf(init).map((k) => ({
            idempotency_key: k,
            status: "created",
          })),
        }),
      );
      const { fireSync } = loadWorker({ fetch });

      await fireSync();

      const sent = callKeys(fetch);
      expect(sent.map((k) => k.length)).toEqual([20, 5]);
      expect(sent.flat()).toEqual([
        "offline-1",
        ...Array.from({ length: 24 }, (_, i) => `offline-${i + 2}`),
      ]);
      expect((await getAllRecords()).every((r) => r.synced === 1)).toBe(true);
    });

    it("sends a refused batch one at a time and refuses a punch that is still refused alone", async () => {
      await queue(2);
      const fetch = vi.fn(async (_url: string, init: RequestInit) => {
        const keys = keysOf(init);
        if (keys.length > 1) return new Response("{}", { status: 413 });
        if (keys[0] === "offline-2") return new Response("{}", { status: 422 });
        return Response.json({
          results: [{ idempotency_key: keys[0], status: "created" }],
        });
      });
      const { fireSync } = loadWorker({ fetch });

      await fireSync();

      expect(callKeys(fetch).map((k) => k.length)).toEqual([3, 1, 1, 1]);
      const byKey = Object.fromEntries(
        (await getAllRecords()).map((r) => [r.idempotency_key, r]),
      );
      expect(byKey["offline-1"].synced).toBe(1);
      expect(byKey["offline-3"].synced).toBe(1);
      expect(byKey["offline-2"]).toMatchObject({
        synced: 2,
        refused_locally: true,
      });
    });

    it("refuses a punch the server calls final and keeps one it does not", async () => {
      await queue(1);
      const fetch = vi.fn(async () =>
        Response.json({
          results: [
            {
              idempotency_key: "offline-1",
              status: "error",
              detail: "This day is closed.",
              final: true,
            },
            {
              idempotency_key: "offline-2",
              status: "error",
              detail: "Try again",
              final: false,
            },
          ],
        }),
      );
      const { fireSync, showNotification } = loadWorker({ fetch });

      await fireSync();

      const byKey = Object.fromEntries(
        (await getAllRecords()).map((r) => [r.idempotency_key, r]),
      );
      expect(byKey["offline-1"]).toMatchObject({
        synced: 2,
        sync_error: "This day is closed.",
      });
      expect(byKey["offline-2"]).toMatchObject({
        synced: 0,
        sync_error: "Try again",
      });
      // Nothing was sent, so nothing is announced as sent.
      expect(showNotification).not.toHaveBeenCalled();
    });

    it("throws on a server fault so the browser retries, and stops after the first batch", async () => {
      await queue(24);
      const fetch = vi.fn(async () => new Response("{}", { status: 503 }));
      const { fireSync } = loadWorker({ fetch });

      await expect(fireSync()).rejects.toThrow();

      expect(fetch).toHaveBeenCalledTimes(1);
      expect((await getAllRecords()).every((r) => r.synced === 0)).toBe(true);
    });

    it.each([419, 429])(
      "ends quietly on a %i, leaving the queue",
      async (status) => {
        await queue(24);
        const fetch = vi.fn(async () => new Response("{}", { status }));
        const { fireSync } = loadWorker({ fetch });

        await fireSync();

        expect(fetch).toHaveBeenCalledTimes(1);
        expect((await getAllRecords()).every((r) => r.synced === 0)).toBe(true);
      },
    );
  });

  it("does not try without a CSRF token to send", async () => {
    const fetch = vi.fn();
    const { fireSync } = loadWorker({ fetch, xsrf: null });

    await fireSync();

    expect(fetch).not.toHaveBeenCalled();
  });
});

describe("service worker push", () => {
  it("tells every open window which tag arrived, and shows the notification", async () => {
    const posted = vi.fn();
    const { firePush, showNotification } = loadWorker({
      fetch: vi.fn(),
      clients: [{ postMessage: posted }, { postMessage: posted }],
    });

    await firePush({ title: "ETHR", tag: "presence-check" });

    expect(posted).toHaveBeenCalledTimes(2);
    expect(posted).toHaveBeenCalledWith({
      type: "ethr-push",
      tag: "presence-check",
    });
    expect(showNotification).toHaveBeenCalledOnce();
  });

  it("still shows the notification when a window cannot be told", async () => {
    const { firePush, showNotification } = loadWorker({
      fetch: vi.fn(),
      clients: [
        {
          postMessage: () => {
            throw new Error("gone");
          },
        },
      ],
    });
    await firePush({ title: "ETHR", tag: "presence-check" });
    expect(showNotification).toHaveBeenCalledOnce();

    const failing = loadWorker({ fetch: vi.fn(), matchAllFails: true });
    await failing.firePush({ title: "ETHR" });
    expect(failing.showNotification).toHaveBeenCalledOnce();
  });
});

describe("service worker, a punch refused for good", () => {
  const SELFIE = "data:image/jpeg;base64,AAAA";

  async function queueWithSelfie(key: string) {
    await clearQueue();
    await enqueueOfflineRecord({
      employee_public_id: "01HQZX3NDJKMNP4RSTVWXY5Z6A",
      type: "check_in",
      idempotency_key: key,
      offline_token: key,
      photo: SELFIE,
      captured_at: "2026-10-13T05:20:00.000Z",
      tenant: "acme",
      locale: "en",
    });
  }

  it("drops the selfie when the server calls the punch final", async () => {
    await queueWithSelfie("p-final");
    const fetch = vi.fn(async () =>
      Response.json({
        results: [
          {
            idempotency_key: "p-final",
            status: "error",
            detail: "This day is closed.",
            final: true,
          },
        ],
      }),
    );
    await loadWorker({ fetch }).fireSync();

    const [record] = await getAllRecords();
    expect(record.synced).toBe(2);
    expect(record.photo ?? null).toBeNull();
  });

  it("drops the selfie when the punch is refused alone as too large", async () => {
    await queueWithSelfie("p-big");
    const fetch = vi.fn(async () => new Response("{}", { status: 413 }));
    await loadWorker({ fetch }).fireSync();

    const [record] = await getAllRecords();
    expect(record.synced).toBe(2);
    expect(record.photo ?? null).toBeNull();
  });

  it("keeps the selfie of a punch that only failed this time", async () => {
    await queueWithSelfie("p-later");
    const fetch = vi.fn(async () => new Response("{}", { status: 401 }));
    await loadWorker({ fetch }).fireSync();

    const [record] = await getAllRecords();
    expect(record.synced).toBe(0);
    expect(record.photo).toBe(SELFIE);
  });
});
