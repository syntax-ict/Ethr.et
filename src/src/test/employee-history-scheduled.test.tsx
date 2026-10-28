import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { EmployeeHistoryTab } from "@/features/employees/components/employee-history-tab";
import type { PersonnelAction } from "@/features/employees/api";

/**
 * A personnel action dated after today waits for its effective date. The
 * history shows it as scheduled and lets whoever may record actions cancel it;
 * an applied action offers no cancel, since it is history.
 */
const can = vi.fn(() => ({ recordPersonnelAction: true }));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ can: can() }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function action(overrides: Partial<PersonnelAction>): PersonnelAction {
  return {
    public_id: "pa1",
    type: "promotion",
    is_temporary: false,
    is_scheduled: false,
    effective_date: "2026-09-01",
    end_date: null,
    reference_number: null,
    reason: null,
    remarks: null,
    changes: { grade: { from: "G6", to: "G7" } },
    recorded_by: "Selam HR",
    created_at: "2026-09-01T08:00:00Z",
    ...overrides,
  };
}

function renderTab() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <EmployeeHistoryTab employeeId="e1" />
    </QueryClientProvider>,
  );
}

function historyOf(rows: PersonnelAction[]) {
  server.use(
    http.get("*/employees/e1/personnel-actions", () => HttpResponse.json(rows)),
  );
}

describe("scheduled personnel actions", () => {
  beforeEach(() => {
    can.mockReturnValue({ recordPersonnelAction: true });
  });

  it("marks a scheduled change and offers to cancel only that one", async () => {
    historyOf([
      action({
        public_id: "next",
        is_scheduled: true,
        effective_date: "2026-11-01",
        changes: { grade: { from: "G7", to: "G8" } },
      }),
      action({ public_id: "past" }),
    ]);
    renderTab();

    expect(await screen.findByText("Scheduled")).toBeInTheDocument();
    expect(
      screen.getByText(
        "Takes effect on 2026-11-01. The employee record is unchanged until then.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getAllByRole("button", { name: "Cancel change" }),
    ).toHaveLength(1);
  });

  it("cancels a scheduled change after confirming", async () => {
    let deleted: string | null = null;
    historyOf([
      action({
        public_id: "next",
        is_scheduled: true,
        effective_date: "2026-11-01",
      }),
    ]);
    server.use(
      http.delete("*/employees/e1/personnel-actions/:id", ({ params }) => {
        deleted = String(params.id);
        return new HttpResponse(null, { status: 204 });
      }),
    );
    renderTab();

    fireEvent.click(
      await screen.findByRole("button", { name: "Cancel change" }),
    );
    expect(
      screen.getByText("Cancel this scheduled change?"),
    ).toBeInTheDocument();
    const buttons = screen.getAllByRole("button", { name: "Cancel change" });
    fireEvent.click(buttons[buttons.length - 1]);

    await waitFor(() => expect(deleted).toBe("next"));
  });

  it("offers no cancel to someone who cannot record actions", async () => {
    can.mockReturnValue({ recordPersonnelAction: false });
    historyOf([
      action({
        public_id: "next",
        is_scheduled: true,
        effective_date: "2026-11-01",
      }),
    ]);
    renderTab();

    expect(await screen.findByText("Scheduled")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Cancel change" }),
    ).not.toBeInTheDocument();
  });
});
