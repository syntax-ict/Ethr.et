import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { afterEach, describe, expect, it, vi } from "vitest";
import { clearApiCache } from "@/lib/api-cache";

/**
 * What the service worker keeps on the device, and what signing out removes.
 *
 * Console QA F3 (2026-10-12): every successful API GET was stored in Cache
 * Storage, the platform console's included, and nothing removed it — so the
 * audit log, tenants and platform settings outlived the session.
 */

type FetchListener = (event: {
  request: Request;
  respondWith: (response: unknown) => void;
}) => void;

/** Runs public/sw.js against a stub `self` and returns its fetch listener. */
function loadWorker(): FetchListener {
  const source = readFileSync(resolve(__dirname, "../../public/sw.js"), "utf8");
  const listeners: Record<string, FetchListener> = {};
  const self = {
    addEventListener: (type: string, listener: FetchListener) => {
      listeners[type] = listener;
    },
  };
  // The handler the worker picks runs at once, so it needs these to settle.
  const caches = {
    open: async () => ({ match: async () => undefined, put: async () => {} }),
    match: async () => undefined,
  };
  const fetch = async () => new Response("{}");
  new Function("self", "caches", "fetch", source)(self, caches, fetch);

  return listeners.fetch;
}

/** Whether the worker answers the GET itself (and so may cache it). */
function intercepts(path: string): boolean {
  const respondWith = vi.fn();
  loadWorker()({
    request: new Request(`https://admin.ethr.et${path}`),
    respondWith,
  });

  return respondWith.mock.calls.length > 0;
}

describe("service worker API caching", () => {
  it("never stores platform-console or auth responses", () => {
    expect(intercepts("/api/v1/admin/tenants")).toBe(false);
    expect(intercepts("/api/v1/admin/audit?page=1")).toBe(false);
    expect(intercepts("/api/v1/admin/platform-settings")).toBe(false);
    expect(intercepts("/api/v1/auth/me")).toBe(false);
  });

  it("still keeps ordinary API responses for offline use", () => {
    expect(intercepts("/api/v1/employees")).toBe(true);
    expect(intercepts("/api/v1/attendance/today")).toBe(true);
  });

  it("names its caches v3, so activation deletes the v1 and v2 copies already stored", () => {
    const source = readFileSync(
      resolve(__dirname, "../../public/sw.js"),
      "utf8",
    );
    expect(source).toContain("const CACHE_VERSION = 'ethr-v3';");
  });
});

describe("clearApiCache", () => {
  const originalCaches = globalThis.caches;

  afterEach(() => {
    Object.defineProperty(globalThis, "caches", {
      value: originalCaches,
      configurable: true,
      writable: true,
    });
  });

  it("deletes the API caches and leaves the static shell", async () => {
    const deleted: string[] = [];
    Object.defineProperty(globalThis, "caches", {
      value: {
        keys: async () => ["ethr-v1-api", "ethr-v2-api", "ethr-v2-static"],
        delete: async (key: string) => {
          deleted.push(key);
          return true;
        },
      },
      configurable: true,
      writable: true,
    });

    await clearApiCache();

    expect(deleted.sort()).toEqual(["ethr-v1-api", "ethr-v2-api"]);
  });

  it("does not throw where Cache Storage is unavailable", async () => {
    Object.defineProperty(globalThis, "caches", {
      value: undefined,
      configurable: true,
      writable: true,
    });

    await expect(clearApiCache()).resolves.toBeUndefined();
  });
});
