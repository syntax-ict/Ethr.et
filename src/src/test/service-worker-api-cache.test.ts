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

  it("never stores pay data, but keeps your own payslips for offline use", () => {
    expect(intercepts("/api/v1/payroll/runs")).toBe(false);
    expect(intercepts("/api/v1/payroll/payslips/EMP-1")).toBe(false);
    expect(intercepts("/api/v1/employees/abc/bank-details")).toBe(false);
    expect(intercepts("/api/v1/employees/abc/contracts")).toBe(false);
    expect(intercepts("/api/v1/employees/contracts/expiring")).toBe(false);
    expect(intercepts("/api/v1/reports/saved")).toBe(false);
    expect(intercepts("/api/v1/payroll/payslips/my")).toBe(true);
  });

  it("drops every stored API answer when the server says the session is over", async () => {
    const deleted: string[] = [];
    const source = readFileSync(
      resolve(__dirname, "../../public/sw.js"),
      "utf8",
    );
    const listeners: Record<string, FetchListener> = {};
    const self = {
      addEventListener: (type: string, listener: FetchListener) => {
        listeners[type] = listener;
      },
    };
    const caches = {
      open: async () => ({ match: async () => undefined, put: async () => {} }),
      match: async () => undefined,
      delete: async (name: string) => {
        deleted.push(name);
        return true;
      },
    };
    const fetch = async () => new Response("{}", { status: 401 });
    new Function("self", "caches", "fetch", source)(self, caches, fetch);

    let answered: Promise<Response> | undefined;
    listeners.fetch({
      request: new Request("https://acme.ethr.et/api/v1/employees"),
      respondWith: (response) => {
        answered = response as Promise<Response>;
      },
    });

    expect((await answered)?.status).toBe(401);
    expect(deleted).toEqual(["ethr-v5-api"]);
  });

  describe("offline answers expire after a week", () => {
    const DAY = 24 * 60 * 60 * 1000;
    const URL_TODAY = "https://acme.ethr.et/api/v1/attendance/today";

    /** Runs public/sw.js with the given cache and network; returns what it answers. */
    async function runWorker(
      cache: Record<string, unknown>,
      fetch: () => Promise<Response>,
    ): Promise<Response | undefined> {
      const source = readFileSync(
        resolve(__dirname, "../../public/sw.js"),
        "utf8",
      );
      const listeners: Record<string, FetchListener> = {};
      const self = {
        addEventListener: (type: string, listener: FetchListener) => {
          listeners[type] = listener;
        },
      };
      const caches = { open: async () => cache, match: async () => undefined };
      new Function("self", "caches", "fetch", source)(self, caches, fetch);

      let answered: Promise<Response> | undefined;
      listeners.fetch({
        request: new Request(URL_TODAY),
        respondWith: (response) => {
          answered = response as Promise<Response>;
        },
      });

      return answered;
    }

    /** Offline, with one stored answer stamped at `storedAt` (null: no stamp). */
    async function offlineAnswer(storedAt: number | null) {
      const deleted: string[] = [];
      const headers: Record<string, string> = {};
      if (storedAt !== null) headers["X-ETHR-Stored-At"] = String(storedAt);
      const response = await runWorker(
        {
          keys: async () => [],
          match: async () => new Response('{"data":[]}', { headers }),
          put: async () => {},
          delete: async (request: Request) => {
            deleted.push(new URL(request.url).pathname);
            return true;
          },
        },
        async () => {
          throw new TypeError("Failed to fetch");
        },
      );

      return { response, deleted };
    }

    it("serves an answer stored six days ago", async () => {
      const { response, deleted } = await offlineAnswer(Date.now() - 6 * DAY);
      expect(response?.status).toBe(200);
      expect(deleted).toEqual([]);
    });

    it("deletes an answer stored eight days ago instead of serving it", async () => {
      const { response, deleted } = await offlineAnswer(Date.now() - 8 * DAY);
      expect(response?.status).toBe(503);
      expect(deleted).toEqual(["/api/v1/attendance/today"]);
    });

    it("treats an answer stored before the limit existed as expired", async () => {
      const { response, deleted } = await offlineAnswer(null);
      expect(response?.status).toBe(503);
      expect(deleted).toEqual(["/api/v1/attendance/today"]);
    });

    it("stamps what it stores with the time it was stored", async () => {
      const put: Response[] = [];
      await runWorker(
        {
          keys: async () => [],
          match: async () => undefined,
          put: async (_request: Request, response: Response) => {
            put.push(response);
          },
        },
        async () => new Response('{"data":[]}'),
      );

      const storedAt = Number(put[0]?.headers.get("X-ETHR-Stored-At"));
      expect(Math.abs(Date.now() - storedAt)).toBeLessThan(5000);
      expect(await put[0]?.text()).toBe('{"data":[]}');
    });
  });

  it("still keeps ordinary API responses for offline use", () => {
    expect(intercepts("/api/v1/employees")).toBe(true);
    expect(intercepts("/api/v1/attendance/today")).toBe(true);
  });

  it("names its caches v5, so activation deletes the v1 to v4 copies already stored", () => {
    // v5 (2026-10-31): pay data copies stored by v4 must go.
    const source = readFileSync(
      resolve(__dirname, "../../public/sw.js"),
      "utf8",
    );
    expect(source).toContain("const CACHE_VERSION = 'ethr-v5';");
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
