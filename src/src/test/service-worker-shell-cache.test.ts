import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * What the service worker stores for the shell pages it serves offline.
 *
 * Production QA 2026-10-28, F-01: the worker stored the HTML of `/offline` and
 * none of the chunks it loads. The server went down, the page came out of the
 * cache in its prerendered Amharic, then failed to hydrate on a chunk nothing
 * had fetched and showed "Something went wrong".
 */

type Listener = (event: Record<string, unknown>) => void;

function page(...assets: string[]): string {
  return `<!doctype html><html><head>${assets
    .map((asset) => `<script src="${asset}" async=""></script>`)
    .join(
      "",
    )}</head><body><script>self.__next_f.push([1,"\\"${assets[0] ?? ""}\\""])</script></body></html>`;
}

/** Runs public/sw.js against an in-memory Cache Storage and a scripted network. */
function loadWorker(network: Record<string, string | null>) {
  const source = readFileSync(resolve(__dirname, "../../public/sw.js"), "utf8");
  const listeners: Record<string, Listener> = {};
  const store = new Map<string, Response>();
  const fetched: string[] = [];

  const key = (input: RequestInfo | URL) =>
    new URL(
      typeof input === "string"
        ? input
        : input instanceof URL
          ? input.href
          : input.url,
      "https://acme.ethr.et",
    ).pathname;

  const fetch = async (input: RequestInfo | URL) => {
    const path = key(input);
    fetched.push(path);
    const body = network[path];
    if (body === undefined || body === null)
      throw new TypeError("Failed to fetch");
    return new Response(body, { status: 200 });
  };

  // Cache Storage keys on the whole URL, query string included, unless the
  // caller asks it not to. The network above ignores the query, as Apache
  // does for a static file.
  const entry = (input: RequestInfo | URL) => {
    const url = new URL(
      typeof input === "string"
        ? input
        : input instanceof URL
          ? input.href
          : input.url,
      "https://acme.ethr.et",
    );
    return url.pathname + url.search;
  };
  const cache = {
    match: async (
      input: RequestInfo | URL,
      options?: { ignoreSearch?: boolean },
    ) => {
      if (!options?.ignoreSearch) return store.get(entry(input))?.clone();
      const path = key(input);
      for (const [stored, response] of store) {
        if (stored.split("?")[0] === path) return response.clone();
      }
      return undefined;
    },
    put: async (input: RequestInfo | URL, response: Response) => {
      store.set(entry(input), response);
    },
    add: async (input: RequestInfo | URL) => {
      store.set(entry(input), await fetch(input));
    },
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
    // A waitUntil registered while a response settles joins the list late.
    for (let i = 0; i < pending.length; i++) {
      await pending[i].catch(() => {});
    }
    return responded as Promise<Response | undefined> | undefined;
  }

  return { network, store, fetched, dispatch };
}

/** A navigation as the browser sends it; mode cannot be set on a Request here. */
function navigation(path: string) {
  return {
    request: {
      method: "GET",
      url: `https://acme.ethr.et${path}`,
      mode: "navigate",
    },
  };
}

const SHELL = [
  "/",
  "/offline",
  "/attendance/mobile",
  "/kiosk",
  "/dashboard",
  "/attendance",
  "/leave",
  "/payroll/payslips",
];

describe("service worker shell cache", () => {
  it("stores each shell page together with the chunks it loads", async () => {
    const network: Record<string, string> = {
      "/_next/static/chunks/main.js": "main",
      "/_next/static/chunks/offline-page.js": "offline",
      "/_next/static/chunks/app.css": "css",
    };
    for (const path of SHELL) {
      network[path] = page(
        "/_next/static/chunks/main.js",
        path === "/offline"
          ? "/_next/static/chunks/offline-page.js"
          : "/_next/static/chunks/app.css",
      );
    }

    const worker = loadWorker(network);
    await worker.dispatch("install");

    expect(worker.store.has("/offline")).toBe(true);
    expect(worker.store.has("/_next/static/chunks/offline-page.js")).toBe(true);
    expect(worker.store.has("/_next/static/chunks/main.js")).toBe(true);
    expect(worker.store.has("/_next/static/chunks/app.css")).toBe(true);
  });

  it("does not store a page whose chunks could not all be stored", async () => {
    const worker = loadWorker({
      "/offline": page(
        "/_next/static/chunks/main.js",
        "/_next/static/chunks/missing.js",
      ),
      "/_next/static/chunks/main.js": "main",
      "/_next/static/chunks/missing.js": null,
    });
    await worker.dispatch("install");

    expect(worker.store.has("/offline")).toBe(false);
  });

  it("re-reads the shell on a navigation once its copy is old, not before", async () => {
    const network: Record<string, string> = {
      "/offline": page("/_next/static/chunks/release-1.js"),
      "/_next/static/chunks/release-1.js": "one",
      "/_next/static/chunks/release-2.js": "two",
      "/leave": "<html></html>",
    };
    const worker = loadWorker(network);
    await worker.dispatch("install");

    // A new release is deployed; sw.js is unchanged, so no reinstall.
    network["/offline"] = page("/_next/static/chunks/release-2.js");
    const navigate = () =>
      worker.dispatch("fetch", {
        // A Request cannot be constructed in navigate mode outside a browser.
        request: {
          method: "GET",
          url: "https://acme.ethr.et/leave",
          mode: "navigate",
        },
      });

    await navigate();
    expect(worker.store.has("/_next/static/chunks/release-2.js")).toBe(false);

    // Six hours and a minute later.
    const stamp = Date.now() - (6 * 60 + 1) * 60 * 1000;
    worker.store.set("/__ethr-shell-cached-at", new Response(String(stamp)));
    await navigate();

    expect(worker.store.has("/_next/static/chunks/release-2.js")).toBe(true);
    expect(await worker.store.get("/offline")?.clone().text()).toContain(
      "release-2.js",
    );
  });
  it("opens the installed app's start URL from the cache when offline", async () => {
    // manifest.json: start_url is /dashboard?source=pwa. The worker matched
    // the exact URL, query string included, and never stored /dashboard at
    // all, so a cold start with no signal always showed "You are offline".
    const network: Record<string, string | null> = {
      "/_next/static/chunks/main.js": "main",
      "/_next/static/chunks/dashboard-page.js": "dashboard",
    };
    for (const path of SHELL) {
      network[path] = page(
        "/_next/static/chunks/main.js",
        "/_next/static/chunks/dashboard-page.js",
      );
    }
    network["/offline"] = page("/_next/static/chunks/main.js") + "offline";
    const worker = loadWorker(network);
    await worker.dispatch("install");

    for (const path of Object.keys(network)) network[path] = null;

    for (const url of [
      "/dashboard?source=pwa",
      "/attendance/mobile?source=shortcut",
    ]) {
      const response = await worker.dispatch("fetch", navigation(url));
      const body = await response?.text();
      expect(body).toContain("dashboard-page.js");
      expect(body).not.toContain("offline");
    }
  });

  it("keeps a page opened online for the next time there is no signal", async () => {
    // Apache answers /employees/ with the same file (DirectorySlash Off).
    const employees = page("/_next/static/chunks/employees-page.js");
    const network: Record<string, string | null> = {
      "/employees": employees,
      "/employees/": employees,
      "/_next/static/chunks/employees-page.js": "employees",
      "/offline": page("/_next/static/chunks/main.js") + "offline",
      "/_next/static/chunks/main.js": "main",
    };
    const worker = loadWorker(network);
    await worker.dispatch("install");
    await worker.dispatch("fetch", navigation("/employees/"));

    expect(worker.store.has("/employees")).toBe(true);
    expect(worker.store.has("/_next/static/chunks/employees-page.js")).toBe(
      true,
    );

    for (const path of Object.keys(network)) network[path] = null;
    const response = await worker.dispatch(
      "fetch",
      navigation("/employees?status=active"),
    );
    expect(await response?.text()).toContain("employees-page.js");
  });

  it("answers a client-side navigation's page payload from the cache offline", async () => {
    const payload = "/dashboard/__next.!KGRhc2hib2FyZCk.dashboard.__PAGE__.txt";
    const network: Record<string, string | null> = { [payload]: "0:rsc" };
    const worker = loadWorker(network);
    const request = {
      method: "GET",
      url: `https://acme.ethr.et${payload}?_rsc=abc`,
    };

    await worker.dispatch("fetch", { request });
    network[payload] = null;

    const response = await worker.dispatch("fetch", {
      request: { ...request, url: `https://acme.ethr.et${payload}?_rsc=def` },
    });
    expect(await response?.text()).toBe("0:rsc");
  });

  it("still falls back to /offline for a page it never had", async () => {
    const network: Record<string, string | null> = {
      "/offline": page("/_next/static/chunks/main.js") + "offline",
      "/_next/static/chunks/main.js": "main",
    };
    const worker = loadWorker(network);
    await worker.dispatch("install");
    for (const path of Object.keys(network)) network[path] = null;

    const response = await worker.dispatch("fetch", navigation("/recruitment"));
    expect(await response?.text()).toContain("offline");
  });
});
