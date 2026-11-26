import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * The service worker's static cache is pruned, not kept forever.
 *
 * Every release adds hashed chunks under /_next/static and nothing deleted the
 * old ones, so a phone's storage grew with each release. A chunk goes only when
 * no stored page or page payload names it and it has been idle for 30 days.
 */

const DAY = 24 * 60 * 60 * 1000;
const STAMP = "X-ETHR-Stored-At";
const ORIGIN = "https://acme.ethr.et";

type Listener = (event: Record<string, unknown>) => void;

function stamped(body: string, ageMs: number | null): Response {
  const headers: Record<string, string> = {};
  if (ageMs !== null) headers[STAMP] = String(Date.now() - ageMs);
  return new Response(body, { headers });
}

function page(...assets: string[]): string {
  return `<html>${assets.map((a) => `<script src="${a}"></script>`).join("")}</html>`;
}

/** public/sw.js against an in-memory cache that keys on Requests, as the real one does. */
function loadWorker(network: Record<string, string | null> = {}) {
  const source = readFileSync(resolve(__dirname, "../../public/sw.js"), "utf8");
  const listeners: Record<string, Listener> = {};
  const store = new Map<string, Response>();

  const key = (input: RequestInfo | URL) =>
    new URL(
      typeof input === "string"
        ? input
        : input instanceof URL
          ? input.href
          : input.url,
      ORIGIN,
    ).pathname;

  const fetch = async (input: RequestInfo | URL) => {
    const body = network[key(input)];
    if (body === undefined || body === null)
      throw new TypeError("Failed to fetch");
    return new Response(body, { status: 200 });
  };

  const cache = {
    match: async (input: RequestInfo | URL) => store.get(key(input))?.clone(),
    put: async (input: RequestInfo | URL, response: Response) => {
      store.set(key(input), response);
    },
    delete: async (input: RequestInfo | URL) => store.delete(key(input)),
    keys: async () =>
      [...store.keys()].map((path) => new Request(ORIGIN + path)),
  };
  const caches = {
    open: async () => cache,
    match: cache.match,
    keys: async () => [],
    delete: async () => true,
  };
  const self = {
    addEventListener: (type: string, listener: Listener) => {
      listeners[type] = listener;
    },
    skipWaiting: async () => {},
    clients: { claim: async () => {} },
  };
  new Function("self", "caches", "fetch", source)(self, caches, fetch);

  async function dispatch(type: string, event: Record<string, unknown> = {}) {
    const pending: Promise<unknown>[] = [];
    let responded: Promise<unknown> | undefined;
    listeners[type]({
      ...event,
      waitUntil: (p: Promise<unknown>) => pending.push(p),
      respondWith: (p: Promise<unknown>) => {
        responded = p;
        pending.push(p);
      },
    });
    for (let i = 0; i < pending.length; i++) {
      await pending[i].catch(() => {});
    }
    return responded as Promise<Response | undefined> | undefined;
  }

  const storedAt = (path: string) =>
    Number(store.get(path)?.headers.get(STAMP));

  return { store, dispatch, storedAt };
}

const CHUNK = "/_next/static/chunks/";

describe("service worker static cache pruning", () => {
  it("deletes an unnamed chunk stamped 31 days ago", async () => {
    const worker = loadWorker();
    worker.store.set(`${CHUNK}old.js`, stamped("old", 31 * DAY));

    await worker.dispatch("activate");

    expect(worker.store.has(`${CHUNK}old.js`)).toBe(false);
  });

  it("keeps an unnamed chunk stamped 2 days ago", async () => {
    const worker = loadWorker();
    worker.store.set(`${CHUNK}recent.js`, stamped("recent", 2 * DAY));

    await worker.dispatch("activate");

    expect(worker.store.has(`${CHUNK}recent.js`)).toBe(true);
  });

  it("keeps a chunk a stored page names, however old its stamp", async () => {
    const worker = loadWorker();
    worker.store.set("/dashboard", stamped(page(`${CHUNK}named.js`), 0));
    worker.store.set(`${CHUNK}named.js`, stamped("named", 60 * DAY));

    await worker.dispatch("activate");

    expect(worker.store.has(`${CHUNK}named.js`)).toBe(true);
  });

  it("keeps a chunk a stored page payload names", async () => {
    const worker = loadWorker();
    worker.store.set(
      "/dashboard/__next.dashboard.txt",
      stamped(`0:["${CHUNK}lazy.js"]`, 0),
    );
    worker.store.set(`${CHUNK}lazy.js`, stamped("lazy", 60 * DAY));

    await worker.dispatch("activate");

    expect(worker.store.has(`${CHUNK}lazy.js`)).toBe(true);
  });

  it("keeps an unstamped chunk and stamps it now", async () => {
    const worker = loadWorker();
    worker.store.set(`${CHUNK}legacy.js`, stamped("legacy", null));

    await worker.dispatch("activate");

    expect(worker.store.has(`${CHUNK}legacy.js`)).toBe(true);
    expect(
      Math.abs(Date.now() - worker.storedAt(`${CHUNK}legacy.js`)),
    ).toBeLessThan(5000);
    expect(await worker.store.get(`${CHUNK}legacy.js`)?.text()).toBe("legacy");
  });

  it("never deletes a page, a payload or the shell stamp", async () => {
    const worker = loadWorker();
    worker.store.set("/offline", stamped(page(), 90 * DAY));
    worker.store.set(
      "/dashboard/__next.dashboard.txt",
      stamped("0:rsc", 90 * DAY),
    );
    worker.store.set("/__ethr-shell-cached-at", stamped("1", 90 * DAY));
    worker.store.set("/favicon.ico", stamped("icon", 90 * DAY));

    await worker.dispatch("activate");

    expect([...worker.store.keys()].sort()).toEqual([
      "/__ethr-shell-cached-at",
      "/dashboard/__next.dashboard.txt",
      "/favicon.ico",
      "/offline",
    ]);
  });

  it("prunes after the shell is re-read, so the pages just stored decide what is named", async () => {
    const network: Record<string, string | null> = {
      "/leave": "<html></html>",
      "/dashboard": page(`${CHUNK}release-2.js`),
      [`${CHUNK}release-2.js`]: "two",
    };
    const again = loadWorker(network);
    again.store.set(
      "/__ethr-shell-cached-at",
      new Response(String(Date.now() - 7 * 60 * 60 * 1000)),
    );
    again.store.set(`${CHUNK}release-1.js`, stamped("one", 40 * DAY));
    await again.dispatch("fetch", {
      request: { method: "GET", url: `${ORIGIN}/leave`, mode: "navigate" },
    });

    expect(again.store.has(`${CHUNK}release-1.js`)).toBe(false);
    expect(again.store.has(`${CHUNK}release-2.js`)).toBe(true);
  });

  it("re-stamps a chunk used after more than a day, and leaves a fresh one alone", async () => {
    const worker = loadWorker();
    worker.store.set(`${CHUNK}old-use.js`, stamped("a", 3 * DAY));
    worker.store.set(`${CHUNK}fresh-use.js`, stamped("b", 1000));
    const freshStamp = worker.storedAt(`${CHUNK}fresh-use.js`);

    for (const name of ["old-use.js", "fresh-use.js"]) {
      const answer = await worker.dispatch("fetch", {
        request: new Request(`${ORIGIN}${CHUNK}${name}`),
      });
      expect(await answer?.text()).toBe(name === "old-use.js" ? "a" : "b");
    }

    expect(Date.now() - worker.storedAt(`${CHUNK}old-use.js`)).toBeLessThan(
      5000,
    );
    expect(worker.storedAt(`${CHUNK}fresh-use.js`)).toBe(freshStamp);
  });

  it("stamps the chunks it stores with a page", async () => {
    const worker = loadWorker({
      "/offline": page(`${CHUNK}main.js`),
      [`${CHUNK}main.js`]: "main",
    });

    await worker.dispatch("install");

    expect(worker.store.has("/offline")).toBe(true);
    expect(
      Math.abs(Date.now() - worker.storedAt(`${CHUNK}main.js`)),
    ).toBeLessThan(5000);
  });

  it("stamps a chunk it stores on first load", async () => {
    const worker = loadWorker({ [`${CHUNK}first.js`]: "first" });

    const answer = await worker.dispatch("fetch", {
      request: new Request(`${ORIGIN}${CHUNK}first.js`),
    });

    expect(await answer?.text()).toBe("first");
    expect(
      Math.abs(Date.now() - worker.storedAt(`${CHUNK}first.js`)),
    ).toBeLessThan(5000);
  });
});
