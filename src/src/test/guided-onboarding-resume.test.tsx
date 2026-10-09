import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { GuidedOnboarding } from "@/features/onboarding/v2/components/guided-onboarding";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * The guided setup kept which steps were done in component state alone, so a
 * reload put a half-configured organisation back at step one with nothing
 * ticked (2026-10-09). It now reads the server's progress record.
 */
describe("GuidedOnboarding", () => {
  it("resumes at the first step not yet done", async () => {
    server.use(
      http.get("*/api/v1/onboarding/progress", () =>
        HttpResponse.json({
          current_step: 6,
          completed_steps: [3, 4],
          step_data: {},
          completed_at: null,
        }),
      ),
      http.get("*/api/v1/onboarding/access", () =>
        HttpResponse.json({
          available: ["email", "phone"],
          login_identifiers: ["email"],
          role_defaults: {},
        }),
      ),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <GuidedOnboarding />
      </QueryClientProvider>,
    );

    // Configure and Workforce are done; Access is where it opens.
    expect(await screen.findByText("Save login methods")).toBeInTheDocument();
    expect(screen.getByRole("progressbar")).toHaveAttribute(
      "aria-valuenow",
      "50",
    );
  });
});
