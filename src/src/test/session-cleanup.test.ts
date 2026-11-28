import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { QueryClient } from "@tanstack/react-query";
import { clearSessionData } from "@/lib/session-cleanup";
import { saveOfflineIdentity } from "@/features/auth/offline-identity";

/**
 * What a session leaves on the device, and what ends it (SP-BROWSER-DATA-EXPOSURE
 * E-2 to E-4). The offline punch queues are deliberately not in this list: a
 * punch that is not on the server yet is somebody's work.
 */
describe("clearSessionData", () => {
  const originalCaches = globalThis.caches;
  let deleted: string[];

  beforeEach(() => {
    localStorage.clear();
    deleted = [];
    Object.defineProperty(globalThis, "caches", {
      value: {
        keys: async () => ["ethr-v6-api", "ethr-v6-static"],
        delete: async (key: string) => {
          deleted.push(key);
          return true;
        },
      },
      configurable: true,
      writable: true,
    });
  });

  afterEach(() => {
    Object.defineProperty(globalThis, "caches", {
      value: originalCaches,
      configurable: true,
      writable: true,
    });
    vi.restoreAllMocks();
  });

  it("removes exactly the session keys and leaves everything else", async () => {
    localStorage.setItem("impersonating", "true");
    localStorage.setItem("original_tenant", "platform");
    localStorage.setItem("ethr.search.recent.U1", '[{"title":"Abebe"}]');
    localStorage.setItem("ethr.search.recent.U2", "[]");
    localStorage.setItem("tenant", "acme");
    localStorage.setItem("locale", "am");
    localStorage.setItem("ethr.offline_grant.E1", "grant");
    localStorage.setItem("datatable:employees:visibility", '{"a":true}');

    await clearSessionData();

    expect(localStorage.getItem("impersonating")).toBeNull();
    expect(localStorage.getItem("original_tenant")).toBeNull();
    expect(localStorage.getItem("ethr.search.recent.U1")).toBeNull();
    expect(localStorage.getItem("ethr.search.recent.U2")).toBeNull();
    // Not its job: a sign-in sets the tenant after clearing, a sign-out keeps
    // the language, and the grants go through clearOfflineOnSignOut only.
    expect(localStorage.getItem("tenant")).toBe("acme");
    expect(localStorage.getItem("locale")).toBe("am");
    expect(localStorage.getItem("ethr.offline_grant.E1")).toBe("grant");
    expect(localStorage.getItem("datatable:employees:visibility")).toBe(
      '{"a":true}',
    );
  });

  it("clears the query cache, the stored API answers and the offline identity", async () => {
    const queryClient = new QueryClient();
    queryClient.setQueryData(["employees"], { data: [1] });
    saveOfflineIdentity({
      user: { public_id: "U1", name: "Abebe", role: "employee" },
      permissions: [],
      tenant: null,
    } as never);
    expect(localStorage.getItem("ethr.offline-identity")).not.toBeNull();

    await clearSessionData(queryClient);

    expect(queryClient.getQueryData(["employees"])).toBeUndefined();
    expect(deleted).toEqual(["ethr-v6-api"]);
    expect(localStorage.getItem("ethr.offline-identity")).toBeNull();
  });

  it("keeps saved employee views and drops only their search", async () => {
    localStorage.setItem(
      "datatable:employees:views",
      JSON.stringify([
        {
          id: "v1",
          name: "Active in Finance",
          state: { search: "abebe@acme.et", status: "active", sort: "name" },
        },
        { id: "v2", name: "Everyone", state: { search: "" } },
      ]),
    );

    await clearSessionData();

    expect(
      JSON.parse(localStorage.getItem("datatable:employees:views") ?? "null"),
    ).toEqual([
      {
        id: "v1",
        name: "Active in Finance",
        state: { status: "active", sort: "name" },
      },
      { id: "v2", name: "Everyone", state: {} },
    ]);
  });

  it("drops saved views it cannot read rather than keep a search it cannot find", async () => {
    localStorage.setItem("datatable:employees:views", "{not json");

    await clearSessionData();

    expect(localStorage.getItem("datatable:employees:views")).toBeNull();
  });

  it("never touches the offline punch queues", async () => {
    const opened: string[] = [];
    vi.stubGlobal("indexedDB", {
      open: (name: string) => {
        opened.push(name);
        throw new Error("clearSessionData must not open IndexedDB");
      },
      deleteDatabase: (name: string) => {
        opened.push(name);
        throw new Error("clearSessionData must not delete IndexedDB");
      },
    });
    localStorage.setItem("ethr-visit-queue", '[{"id":"visit-1"}]');

    try {
      await clearSessionData();
    } finally {
      vi.unstubAllGlobals();
    }

    expect(opened).toEqual([]);
    expect(localStorage.getItem("ethr-visit-queue")).toBe('[{"id":"visit-1"}]');
  });

  it("does not throw where storage and Cache Storage are unavailable", async () => {
    Object.defineProperty(globalThis, "caches", {
      value: undefined,
      configurable: true,
      writable: true,
    });
    vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
      throw new Error("blocked");
    });
    vi.spyOn(Storage.prototype, "removeItem").mockImplementation(() => {
      throw new Error("blocked");
    });

    await expect(clearSessionData()).resolves.toBeUndefined();
  });
});
