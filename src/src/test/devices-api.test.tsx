import { describe, it, expect } from "vitest";
import { act, renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  useDeleteDevice,
  useDevice,
  useDeviceSyncLogs,
  useDevices,
  useTestDeviceConnection,
  useUpdateDevice,
} from "@/features/devices/api";

/**
 * The devices pages used to call apiClient inline while these hooks sat unused
 * and had drifted from the API (audit F2). The pages now go through them, so
 * the request shapes and the cache behaviour the pages relied on are pinned here.
 */
function createWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  function TestQueryWrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  }
  return TestQueryWrapper;
}

const PAGE = {
  data: [],
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 0,
    from: 0,
    to: 0,
  },
  links: { first: "", last: "", prev: null, next: null },
};

describe("devices api", () => {
  it("sends search and the status filter, and leaves out what is unset", async () => {
    const seen: URLSearchParams[] = [];
    server.use(
      http.get("*/api/v1/devices", ({ request }) => {
        seen.push(new URL(request.url).searchParams);
        return HttpResponse.json(PAGE);
      }),
    );

    const wrapper = createWrapper();
    const filtered = renderHook(
      () => useDevices({ search: "gate", status: "offline" }),
      { wrapper },
    );
    await waitFor(() => expect(filtered.result.current.isSuccess).toBe(true));
    const unfiltered = renderHook(() => useDevices(), { wrapper });
    await waitFor(() => expect(unfiltered.result.current.isSuccess).toBe(true));

    expect(seen[0].get("search")).toBe("gate");
    expect(seen[0].get("filter[status]")).toBe("offline");
    // Only paging is sent unfiltered: every page is read now (N69).
    expect([...seen[1].keys()].sort()).toEqual(["page", "per_page"]);
  });

  it("reads every page, so a device past the first page is listed", async () => {
    // Neither the device list nor the health dashboard has a pager, and the
    // API pages at 25: device 26 could not be seen, edited or pulled (N69).
    server.use(
      http.get("*/api/v1/devices", ({ request }) => {
        const page = Number(new URL(request.url).searchParams.get("page"));
        return HttpResponse.json({
          ...PAGE,
          data: [{ public_id: `DEV-PAGE-${page}`, name: `Gate ${page}` }],
          meta: { ...PAGE.meta, current_page: page, last_page: 2 },
        });
      }),
    );

    const { result } = renderHook(() => useDevices(), {
      wrapper: createWrapper(),
    });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data?.data.map((d) => d.public_id)).toEqual([
      "DEV-PAGE-1",
      "DEV-PAGE-2",
    ]);
  });

  it("does not request a device before the route id is known", async () => {
    let calls = 0;
    server.use(
      http.get("*/api/v1/devices/*", () => {
        calls++;
        return HttpResponse.json({});
      }),
    );

    const { result } = renderHook(() => useDevice(""), {
      wrapper: createWrapper(),
    });

    await new Promise((r) => setTimeout(r, 50));
    expect(result.current.fetchStatus).toBe("idle");
    expect(calls).toBe(0);
  });

  it("holds the sync log query until the page enables it", async () => {
    let calls = 0;
    server.use(
      http.get("*/api/v1/devices/:id/sync-logs", () => {
        calls++;
        return HttpResponse.json(PAGE);
      }),
    );

    const { result } = renderHook(
      () => useDeviceSyncLogs("DEV1", { page: 1 }, { enabled: false }),
      { wrapper: createWrapper() },
    );

    await new Promise((r) => setTimeout(r, 50));
    expect(result.current.fetchStatus).toBe("idle");
    expect(calls).toBe(0);
  });

  it("updates the device named in the call, not one fixed at hook creation", async () => {
    let put: { id: string; body: unknown } | null = null;
    server.use(
      http.put("*/api/v1/devices/:id", async ({ params, request }) => {
        put = { id: String(params.id), body: await request.json() };
        return HttpResponse.json({ public_id: params.id });
      }),
    );

    const { result } = renderHook(() => useUpdateDevice(), {
      wrapper: createWrapper(),
    });
    await act(() =>
      result.current.mutateAsync({
        publicId: "DEV2",
        payload: { name: "Renamed" },
      }),
    );

    expect(put).toEqual({ id: "DEV2", body: { name: "Renamed" } });
  });

  it("refreshes the device list after a connection test, which rewrites status", async () => {
    let listCalls = 0;
    server.use(
      http.get("*/api/v1/devices", () => {
        listCalls++;
        return HttpResponse.json(PAGE);
      }),
      http.get("*/api/v1/devices/:id/status", ({ params }) =>
        HttpResponse.json({
          device_public_id: params.id,
          status: "offline",
          details: [],
          device_info: [],
        }),
      ),
    );

    const wrapper = createWrapper();
    const list = renderHook(() => useDevices(), { wrapper });
    await waitFor(() => expect(list.result.current.isSuccess).toBe(true));
    expect(listCalls).toBe(1);

    const probe = renderHook(() => useTestDeviceConnection(), { wrapper });
    const outcome = await act(() => probe.result.current.mutateAsync("DEV1"));

    expect(outcome.status).toBe("offline");
    await waitFor(() => expect(listCalls).toBe(2));
  });

  it("refreshes the list after a delete without refetching the deleted device", async () => {
    let listCalls = 0;
    let detailCalls = 0;
    server.use(
      http.get("*/api/v1/devices", () => {
        listCalls++;
        return HttpResponse.json(PAGE);
      }),
      http.get("*/api/v1/devices/DEV1", () => {
        detailCalls++;
        return HttpResponse.json({ public_id: "DEV1" });
      }),
      http.delete(
        "*/api/v1/devices/DEV1",
        () => new HttpResponse(null, { status: 204 }),
      ),
    );

    const wrapper = createWrapper();
    const list = renderHook(() => useDevices(), { wrapper });
    const detail = renderHook(() => useDevice("DEV1"), { wrapper });
    await waitFor(() => expect(list.result.current.isSuccess).toBe(true));
    await waitFor(() => expect(detail.result.current.isSuccess).toBe(true));

    const remove = renderHook(() => useDeleteDevice(), { wrapper });
    await act(() => remove.result.current.mutateAsync("DEV1"));

    await waitFor(() => expect(listCalls).toBe(2));
    expect(detailCalls).toBe(1);
  });
});
