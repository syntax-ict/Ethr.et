import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, waitFor, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { useMarkAsRead } from "@/features/notifications/api";

vi.mock("@/api/client", () => ({
  apiClient: {
    get: vi.fn(),
    put: vi.fn(),
  },
}));

import { apiClient } from "@/api/client";

function makeWrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        {children}
      </QueryClientProvider>
    );
  };
}

function seedNotificationsCache(queryClient: QueryClient) {
  queryClient.setQueryData(["notifications", undefined], {
    data: [
      { id: "n1", type: "TestNotification", data: {}, read_at: null, created_at: "2026-01-01" },
      { id: "n2", type: "TestNotification", data: {}, read_at: null, created_at: "2026-01-02" },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 },
    links: { first: "", last: "", prev: null, next: null },
  });
  queryClient.setQueryData(["notifications", "unread-count"], { count: 2 });
}

describe("useMarkAsRead (optimistic)", () => {
  beforeEach(() => {
    vi.mocked(apiClient.put).mockReset();
  });

  it("marks the notification as read and decrements the unread count immediately, before the request resolves", async () => {
    let resolveRequest: () => void = () => {};
    vi.mocked(apiClient.put).mockReturnValue(
      new Promise((resolve) => {
        resolveRequest = () => resolve({ data: {} } as any);
      }),
    );

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    seedNotificationsCache(queryClient);

    const { result: markAsRead } = renderHook(() => useMarkAsRead(), {
      wrapper: makeWrapper(queryClient),
    });

    act(() => {
      markAsRead.current.mutate("n1");
    });

    // Optimistic update should be visible immediately, before the mocked
    // network request resolves.
    await waitFor(() => {
      const cached = queryClient.getQueryData<any>(["notifications", undefined]);
      expect(cached.data.find((n: any) => n.id === "n1").read_at).not.toBeNull();
    });

    const count = queryClient.getQueryData<{ count: number }>([
      "notifications",
      "unread-count",
    ]);
    expect(count?.count).toBe(1);

    resolveRequest();
    await waitFor(() => expect(markAsRead.current.isSuccess).toBe(true));
  });

  it("rolls back the optimistic update and unread count if the request fails", async () => {
    vi.mocked(apiClient.put).mockRejectedValue(new Error("network error"));

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    seedNotificationsCache(queryClient);

    const { result: markAsRead } = renderHook(() => useMarkAsRead(), {
      wrapper: makeWrapper(queryClient),
    });

    act(() => {
      markAsRead.current.mutate("n1");
    });

    await waitFor(() => expect(markAsRead.current.isError).toBe(true));

    const cached = queryClient.getQueryData<any>(["notifications", undefined]);
    expect(cached.data.find((n: any) => n.id === "n1").read_at).toBeNull();

    const count = queryClient.getQueryData<{ count: number }>([
      "notifications",
      "unread-count",
    ]);
    expect(count?.count).toBe(2);
  });

  it("does not decrement the unread count when marking an already-read notification as read", async () => {
    vi.mocked(apiClient.put).mockResolvedValue({ data: {} } as any);

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    queryClient.setQueryData(["notifications", undefined], {
      data: [
        { id: "n1", type: "TestNotification", data: {}, read_at: "2026-01-01T00:00:00Z", created_at: "2026-01-01" },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
      links: { first: "", last: "", prev: null, next: null },
    });
    queryClient.setQueryData(["notifications", "unread-count"], { count: 0 });

    const { result: markAsRead } = renderHook(() => useMarkAsRead(), {
      wrapper: makeWrapper(queryClient),
    });

    act(() => {
      markAsRead.current.mutate("n1");
    });

    await waitFor(() => expect(markAsRead.current.isSuccess).toBe(true));

    const count = queryClient.getQueryData<{ count: number }>([
      "notifications",
      "unread-count",
    ]);
    expect(count?.count).toBe(0);
  });
});
