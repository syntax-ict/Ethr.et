import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import ApprovalsPage from "@/app/(dashboard)/approvals/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like the `items` entries ApprovalController::pending() builds. */
const LEAVE_ITEM = {
  type: "leave",
  public_id: "01HZLEAVE0000000000000001",
  employee_name: "Abebe Kebede",
  employee_public_id: "01HZEMPLOYEE0000000000001",
  summary: "Annual Leave: Oct 06 - Oct 08",
  submitted_at: "2026-10-01T06:00:00Z",
};

function renderPage() {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Supervisor", role: "supervisor" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: ["leave.viewTeam", "leave.approve"],
      }),
    ),
  );
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <ApprovalsPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(toast.success).mockClear();
  vi.mocked(toast.error).mockClear();
});

describe("Approvals page", () => {
  it("reports an item the batch endpoint refused instead of calling it done", async () => {
    // The endpoint answers 200 for the batch and refuses items one by one —
    // here, a request someone else already decided.
    server.use(
      http.get("*/api/v1/approvals/pending", () =>
        HttpResponse.json({ items: [LEAVE_ITEM], total: 1 }),
      ),
      http.post("*/api/v1/approvals/batch", () =>
        HttpResponse.json({
          results: [
            {
              public_id: LEAVE_ITEM.public_id,
              status: "error",
              detail: "Not found or not pending",
            },
          ],
        }),
      ),
    );

    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: /Approve/ }));

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith("Action failed", {
        description: "Not found or not pending",
      }),
    );
    expect(toast.success).not.toHaveBeenCalled();
  });

  it("confirms an item that went through", async () => {
    let sent: unknown;
    server.use(
      http.get("*/api/v1/approvals/pending", () =>
        HttpResponse.json({ items: [LEAVE_ITEM], total: 1 }),
      ),
      http.post("*/api/v1/approvals/batch", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({
          results: [{ public_id: LEAVE_ITEM.public_id, status: "approved" }],
        });
      }),
    );

    renderPage();
    fireEvent.click(await screen.findByRole("button", { name: /Approve/ }));

    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Action completed"),
    );
    expect(sent).toEqual({
      actions: [
        { type: "leave", public_id: LEAVE_ITEM.public_id, action: "approve" },
      ],
    });
  });

  it("says the queue could not be loaded rather than that it is empty", async () => {
    server.use(
      http.get("*/api/v1/approvals/pending", () =>
        HttpResponse.json(
          { title: "Server Error", status: 500 },
          { status: 500 },
        ),
      ),
    );

    renderPage();

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
    expect(screen.queryByText("All caught up!")).not.toBeInTheDocument();
  });
});
