import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { useDeviceEnrollments } from "@/features/devices/api";

vi.mock("@/api/client", () => ({
  apiClient: { get: vi.fn() },
}));

import { apiClient } from "@/api/client";

/** The app's own defaults (providers.tsx), with no wait between attempts. */
function wrapper() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: 1, retryDelay: 0 } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
  };
}

function failWith(status: number) {
  vi.mocked(apiClient.get).mockRejectedValue(
    Object.assign(new Error(`Request failed with status code ${status}`), {
      response: { status, data: { code: "device-roster-unavailable" } },
    }),
  );
}

describe("useDeviceEnrollments", () => {
  // Braces: a function returned from beforeEach is run as its teardown, and
  // mockReset returns the mock, which would be called once more after each test.
  beforeEach(() => {
    vi.mocked(apiClient.get).mockReset();
  });

  it("does not ask a terminal that only sends for its roster a second time", async () => {
    failWith(409);
    const { result } = renderHook(() => useDeviceEnrollments("01DEVICE"), {
      wrapper: wrapper(),
    });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(apiClient.get).toHaveBeenCalledTimes(1);
  });

  it("still tries once more when the server fails", async () => {
    failWith(500);
    const { result } = renderHook(() => useDeviceEnrollments("01DEVICE"), {
      wrapper: wrapper(),
    });

    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(apiClient.get).toHaveBeenCalledTimes(2);
  });
});
