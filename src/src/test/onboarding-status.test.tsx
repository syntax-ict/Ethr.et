import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { useOnboardingStatus } from "@/features/onboarding/useOnboardingStatus";

function renderStatus(completed: number[]) {
  server.use(
    http.get("*/api/v1/onboarding/progress", () =>
      HttpResponse.json({
        current_step: 7,
        completed_steps: completed,
        step_data: {},
        completed_at: null,
      }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return renderHook(() => useOnboardingStatus(), {
    wrapper: ({ children }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    ),
  });
}

/**
 * The sidebar's "N of M completed" counts the guided setup's four steps:
 * configuration (3), workforce (4), access (5) and go live (7).
 *
 * It counted against the server enum's seven, and nothing marks 1, 2 or 6, so
 * the bar stopped at 3 of 7 however far setup got (2026-10-09). Before that it
 * counted against six, so a finished tenant saw "7 of 6" and 117%.
 */
describe("useOnboardingStatus", () => {
  it("reaches 4 of 4 when every guided step is done", async () => {
    const { result } = renderStatus([3, 4, 5, 7]);

    await waitFor(() => expect(result.current.completedSteps).toHaveLength(4));
    expect(result.current.totalSteps).toBe(4);
    expect(result.current.progress).toBe(100);
  });

  it("ignores the old wizard's step numbers, which nothing marks any more", async () => {
    const { result } = renderStatus([1, 2, 3, 6]);

    await waitFor(() => expect(result.current.completedSteps).toEqual([3]));
    expect(result.current.progress).toBe(25);
  });
});
