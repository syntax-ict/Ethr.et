import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
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

/**
 * Sign-in comes before People (owner decision 2026-11-16), so the way people
 * sign in is chosen before anyone is invited, and the list is added without
 * the invitation tick already ticked.
 */
describe("GuidedOnboarding order", () => {
  const LIST = "01JBQ8Z6ZK3M9V2X7Y4W5T6L01";

  function renderGuided(completed: number[]) {
    server.use(
      http.get("*/api/v1/onboarding/progress", () =>
        HttpResponse.json({
          current_step: 6,
          completed_steps: completed,
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
      http.get("*/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "01JBQ8Z6ZK3M9V2X7Y4W5T6R80", role: "hr_admin" },
          permissions: ["employee.create", "employee.viewAny"],
          tenant: null,
        }),
      ),
      http.get("*/devices", () =>
        HttpResponse.json({ data: [], meta: {}, links: {} }),
      ),
      http.get("*/onboarding/migration/batches", () =>
        HttpResponse.json({
          data: [
            {
              public_id: LIST,
              status: "reviewing",
              mode: "add",
              source_type: "csv",
              rows: 1,
              created_at: new Date(Date.now() - 300_000).toISOString(),
              updated_at: new Date(Date.now() - 300_000).toISOString(),
              created_by_name: null,
              mine: true,
            },
          ],
        }),
      ),
      http.get("*/onboarding/migration/batches/:id", () =>
        HttpResponse.json({
          public_id: LIST,
          source_type: "csv",
          source_ref: null,
          status: "reviewing",
          mode: "add",
          totals: null,
          summary: { new: 1 },
          rows: [
            {
              public_id: `${LIST}-ROW`,
              display_name: "Hanna Girma",
              external_identifier: null,
              match_outcome: "new",
              match_confidence: null,
              candidates: [],
              resolved_employee_public_id: null,
              action: "create",
              changes: [],
              can_apply_changes: false,
              scheduled_action: false,
              processed_at: null,
            },
          ],
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
  }

  it("shows Sign-in second and People third", async () => {
    renderGuided([]);
    const nav = await screen.findByRole("navigation", {
      name: "Setup progress",
    });
    const labels = within(nav)
      .getAllByRole("listitem")
      .map((li) => li.textContent?.replace(/^\d+/, ""));
    expect(labels).toEqual(["Organisation", "Sign-in", "People", "Go live"]);
  });

  it("opens on People when Organisation and Sign-in are done, and People starts without the invitation ticked", async () => {
    renderGuided([3, 5]);

    const row = await screen.findByTestId("list-in-progress");
    await userEvent.click(within(row).getByRole("button", { name: "Open" }));

    expect(
      await screen.findByRole("checkbox", {
        name: /Invite them to sign in as soon as they are added/,
      }),
    ).not.toBeChecked();
  });

  it("opens on Sign-in when only Organisation and People are done", async () => {
    renderGuided([3, 4]);

    expect(await screen.findByText("Save login methods")).toBeInTheDocument();
  });
});
