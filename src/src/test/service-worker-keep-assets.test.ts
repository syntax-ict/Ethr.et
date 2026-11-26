import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { loadedStaticAssets } from "@/lib/hooks/useServiceWorker";

/**
 * What a first visit had loaded before the worker was in charge.
 *
 * Measured on production on 2026-11-24: one visit in Afaan Oromoo, network
 * cut, and the offline page came up in English. That dictionary is a chunk of
 * its own, it arrived before the worker controlled the page, and no stored
 * page names it, so nothing kept it until the app was opened a second time.
 * The page now tells the worker what it loaded (`KEEP_ASSETS`).
 */

const ORIGIN = "https://acme.ethr.et";
const OM = "/_next/static/chunks/om.4f2a.js";
const APP = "/_next/static/chunks/app.91bc.js";

type MessageListener = (event: {
  data: unknown;
  waitUntil: (work: Promise<unknown>) => void;
}) => void;

/** Runs public/sw.js against an in-memory Cache Storage and a scripted network. */
function loadWorker(network: Record<string, number>, stored: string[] = []) {
  const source = readFileSync(resolve(__dirname, "../../public/sw.js"), "utf8");
  const listeners: Record<string, MessageListener> = {};
  const store = new Map<string, Response>(
    stored.map((path) => [path, new Response("kept")]),
  );
  const fetched: string[] = [];

  const path = (input: RequestInfo | URL) =>
    new URL(
      typeof input === "string"
        ? input
        : input instanceof URL
          ? input.href
          : input.url,
      ORIGIN,
    ).pathname;

  const fetch = async (input: RequestInfo | URL) => {
    const target = path(input);
    fetched.push(target);
    const status = network[target];
    if (status === undefined) throw new TypeError("Failed to fetch");
    return new Response("body of " + target, { status });
  };

  const cache = {
    match: async (input: RequestInfo | URL) => store.get(path(input))?.clone(),
    put: async (input: RequestInfo | URL, response: Response) => {
      store.set(path(input), response);
    },
    keys: async () => [],
    delete: async () => true,
  };
  const caches = {
    open: async () => cache,
    match: async (input: RequestInfo | URL) => cache.match(input),
    keys: async () => [],
    delete: async () => true,
  };
  const self = {
    addEventListener: (type: string, listener: MessageListener) => {
      listeners[type] = listener;
    },
    location: { origin: ORIGIN },
    clients: { claim: async () => {}, matchAll: async () => [] },
    skipWaiting: async () => {},
    registration: {},
  };

  new Function("self", "caches", "fetch", source)(self, caches, fetch);

  async function send(data: unknown) {
    const work: Promise<unknown>[] = [];
    listeners.message({ data, waitUntil: (p) => void work.push(p) });
    await Promise.all(work);
  }

  return { send, store, fetched };
}

describe("the worker keeps what a first visit had loaded", () => {
  it("stores a chunk no page names, with the stamp pruning reads", async () => {
    const worker = loadWorker({ [OM]: 200, [APP]: 200 });

    await worker.send({ type: "KEEP_ASSETS", urls: [OM, APP] });

    expect([...worker.store.keys()].sort()).toEqual([APP, OM].sort());
    expect(worker.store.get(OM)?.headers.get("X-ETHR-Stored-At")).toMatch(
      /^\d+$/,
    );
  });

  it("does not fetch again what it already holds", async () => {
    const worker = loadWorker({ [OM]: 200, [APP]: 200 }, [APP]);

    await worker.send({ type: "KEEP_ASSETS", urls: [OM, APP] });

    expect(worker.fetched).toEqual([OM]);
  });

  it("accepts a full address on this origin and stores it by its path", async () => {
    const worker = loadWorker({ [OM]: 200 });

    await worker.send({ type: "KEEP_ASSETS", urls: [ORIGIN + OM + "?v=1"] });

    expect([...worker.store.keys()]).toEqual([OM]);
  });

  it("refuses anything that is not this origin's /_next/static", async () => {
    const worker = loadWorker({ [OM]: 200 });

    await worker.send({
      type: "KEEP_ASSETS",
      urls: [
        "/api/v1/payroll/payslips/my",
        "/dashboard",
        "https://evil.example/_next/static/chunks/x.js",
        42,
        null,
      ],
    });

    expect(worker.fetched).toEqual([]);
    expect(worker.store.size).toBe(0);
  });

  it("stores nothing for an answer that is not ok, and nothing when the network fails", async () => {
    const worker = loadWorker({ [OM]: 404 });

    await worker.send({ type: "KEEP_ASSETS", urls: [OM, APP] });

    expect(worker.store.size).toBe(0);
  });

  it("ignores other messages and a list that is not a list", async () => {
    const worker = loadWorker({ [OM]: 200 });

    await worker.send({ type: "SOMETHING_ELSE", urls: [OM] });
    await worker.send({ type: "KEEP_ASSETS", urls: OM });
    await worker.send(null);

    expect(worker.fetched).toEqual([]);
  });

  it("takes no more than its limit from one message", async () => {
    const many = Array.from(
      { length: 260 },
      (_, i) => `/_next/static/chunks/c${i}.js`,
    );
    const worker = loadWorker(Object.fromEntries(many.map((p) => [p, 200])));

    await worker.send({ type: "KEEP_ASSETS", urls: many });

    expect(worker.store.size).toBe(200);
  });
});

describe("loadedStaticAssets", () => {
  it("lists this origin's /_next/static files once each, as paths", () => {
    expect(
      loadedStaticAssets(
        [
          { name: ORIGIN + OM },
          { name: ORIGIN + OM + "?dpl=abc" },
          { name: ORIGIN + APP },
          { name: ORIGIN + "/api/v1/plans" },
          { name: ORIGIN + "/app-icons/icon-192.png" },
          { name: "https://maps.googleapis.com/_next/static/x.js" },
          { name: "not a url at all\u0000" },
        ],
        ORIGIN,
      ),
    ).toEqual([OM, APP]);
  });

  it("is empty when nothing was loaded", () => {
    expect(loadedStaticAssets([], ORIGIN)).toEqual([]);
  });
});
