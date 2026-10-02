import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { useOnboardingStatus } from "@/features/onboarding/useOnboardingStatus";

/**
 * The backend's OnboardingStep enum has seven steps. The hook said six, so a
 * tenant who had finished every step but go-live saw "7 of 6 completed" and a
 * progress bar at 117%.
 */
describe("useOnboardingStatus", () => {
  it("counts against all seven onboarding steps", async () => {
    server.use(
      http.get("*/api/v1/onboarding/progress", () =>
        HttpResponse.json({
          current_step: 7,
          completed_steps: [1, 2, 3, 4, 5, 6, 7],
          step_data: {},
          completed_at: null,
        }),
      ),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });

    const { result } = renderHook(() => useOnboardingStatus(), {
      wrapper: ({ children }) => (
        <QueryClientProvider client={client}>{children}</QueryClientProvider>
      ),
    });

    await waitFor(() => expect(result.current.completedSteps).toHaveLength(7));
    expect(result.current.totalSteps).toBe(7);
    expect(result.current.progress).toBe(100);
  });
});
