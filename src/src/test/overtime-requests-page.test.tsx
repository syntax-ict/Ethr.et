import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import OvertimeRequestsPage from "@/app/(dashboard)/attendance/overtime-requests/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like `OvertimeRequestResource`. */
const PENDING = {
  public_id: "01HZOT00000000000000000001",
  date: "2099-06-01",
  planned_minutes: 150,
  reason: "Month-end stock count",
  status: "pending",
  decision_note: null,
  decided_at: null,
  created_at: "2099-05-30T08:00:00+00:00",
};

function setup(rows: unknown[]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Abebe", role: "employee" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: ["overtime.request"],
      }),
    ),
    http.get("*/api/v1/attendance/overtime-requests/my", () =>
      HttpResponse.json({
        data: rows,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 50,
          total: rows.length,
        },
        links: { next: null, prev: null },
      }),
    ),
  );

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<OvertimeRequestsPage />, { wrapper: Wrapper });
}

describe("<OvertimeRequestsPage>", () => {
  it("lists the employee's requests with their hours and status", async () => {
    setup([PENDING]);

    expect(
      await screen.findByText("2.5 hours: Month-end stock count"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Cancel request" }),
    ).toBeInTheDocument();
  });

  it("says one hour, not one hours", async () => {
    setup([
      {
        ...PENDING,
        public_id: "01HZOT00000000000000000002",
        planned_minutes: 60,
        reason: "Inventory",
      },
    ]);

    expect(await screen.findByText("1 hour: Inventory")).toBeInTheDocument();
  });

  it("sends a request for a day, hours and reason", async () => {
    const user = userEvent.setup();
    let body: unknown = null;
    server.use(
      http.post(
        "*/api/v1/attendance/overtime-requests",
        async ({ request }) => {
          body = await request.json();
          return HttpResponse.json(PENDING, { status: 201 });
        },
      ),
    );
    setup([]);

    expect(
      await screen.findByText("No overtime requests yet"),
    ).toBeInTheDocument();
    await user.type(screen.getByLabelText(/Day/), "2099-06-01");
    await user.type(screen.getByLabelText(/Why/), "Month-end stock count");
    await user.click(screen.getByRole("button", { name: "Send request" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({
      date: "2099-06-01",
      planned_minutes: 120,
      reason: "Month-end stock count",
    });
  });

  it("asks before cancelling a request", async () => {
    const user = userEvent.setup();
    let cancelled = false;
    server.use(
      http.put("*/api/v1/attendance/overtime-requests/:id/cancel", () => {
        cancelled = true;
        return HttpResponse.json({ ...PENDING, status: "cancelled" });
      }),
    );
    setup([PENDING]);

    await user.click(
      await screen.findByRole("button", { name: "Cancel request" }),
    );
    expect(cancelled).toBe(false);

    const dialog = await screen.findByRole("dialog");
    await user.click(
      within(dialog).getByRole("button", { name: "Cancel request" }),
    );
    await waitFor(() => expect(cancelled).toBe(true));
  });
});
