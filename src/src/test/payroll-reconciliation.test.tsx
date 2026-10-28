import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { ReconcilePanel } from "@/features/payroll/components/reconcile-panel";
import { ReconciliationBanner } from "@/features/payroll/components/reconciliation-banner";
import { DeductionCeilingBanner } from "@/features/payroll/components/deduction-ceiling-banner";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like GET /payroll/reconciliation (AttendanceReconciliation). */
const RECONCILIATION = {
  period: { start: "2026-06-01", end: "2026-06-30" },
  mode: "required",
  lateness_deduction_enabled: true,
  totals: { open: 2, pending: 1, resolved: 0, blocking: 3 },
  by_type: [
    { type: "late_arrival", count: 1 },
    { type: "unexplained_absence", count: 2 },
  ],
  unmatched_punches: 0,
  employees: [
    {
      employee_public_id: "01HZEMP0000000000000000001",
      employee_name: "Abebe Kebede",
      employee_code: "EMP-0001",
      exceptions: [
        {
          type: "unexplained_absence",
          date: "2026-06-03",
          state: "open",
          minutes: null,
          record_public_id: null,
          request_kind: null,
          request_public_id: null,
        },
        {
          type: "unexplained_absence",
          date: "2026-06-04",
          state: "pending",
          minutes: null,
          record_public_id: null,
          request_kind: "correction",
          request_public_id: "01HZCOR0000000000000000001",
        },
        {
          type: "late_arrival",
          date: "2026-06-10",
          state: "open",
          minutes: 40,
          record_public_id: "01HZATT0000000000000000010",
          request_kind: null,
          request_public_id: null,
        },
      ],
    },
  ],
};

const LEAVE_TYPES = [
  {
    public_id: "LT-ANNUAL",
    name: "Annual Leave",
    kind: "leave",
    is_paid: true,
    is_active: true,
  },
  {
    public_id: "LT-UNPAID",
    name: "Unpaid Leave",
    kind: "leave",
    is_paid: false,
    is_active: true,
  },
  {
    public_id: "LT-DUTY",
    name: "Official Duty / Business Trip",
    kind: "duty",
    is_paid: true,
    is_active: true,
  },
];

function wrapper() {
  localStorage.setItem("ethr.calendar", "gregorian");
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <CalendarProvider>{children}</CalendarProvider>
      </QueryClientProvider>
    );
  };
}

describe("<ReconcilePanel>", () => {
  it("lists each exception with its state, and files leave for an absence on the employee's behalf", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/payroll/reconciliation", () =>
        HttpResponse.json(RECONCILIATION),
      ),
      http.get("*/api/v1/leave-types", () =>
        HttpResponse.json({
          data: LEAVE_TYPES,
          meta: { current_page: 1, last_page: 1, per_page: 100, total: 3 },
          links: {},
        }),
      ),
      http.post("*/api/v1/leave/request", async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ public_id: "LR1" }, { status: 201 });
      }),
    );
    const user = userEvent.setup();
    render(<ReconcilePanel />, { wrapper: wrapper() });

    expect(await screen.findByText("2 open")).toBeInTheDocument();
    expect(screen.getByText("1 waiting")).toBeInTheDocument();
    expect(screen.getAllByText("Absent, nothing on record")).toHaveLength(2);
    expect(screen.getByText("Late arrival")).toBeInTheDocument();

    // The pending absence links to the approvals inbox, the open one is settled here.
    const settleButtons = screen.getAllByRole("button", { name: "Settle" });
    expect(settleButtons).toHaveLength(2);

    await user.click(settleButtons[0]);
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("combobox"));
    // Only types that can settle an absence: unpaid and the trip, not annual leave.
    expect(screen.queryByRole("option", { name: "Annual Leave" })).toBeNull();
    await user.click(screen.getByRole("option", { name: "Unpaid Leave" }));
    await user.type(
      within(dialog).getByLabelText(/Reason/),
      "No call, no show.",
    );
    await user.click(
      within(dialog).getByRole("button", { name: "File for approval" }),
    );

    await waitFor(() => expect(posted).not.toBeNull());
    expect(posted).toEqual({
      leave_type_public_id: "LT-UNPAID",
      employee_public_id: "01HZEMP0000000000000000001",
      start_date: "2026-06-03",
      end_date: "2026-06-03",
      reason: "No call, no show.",
    });
  });
});

describe("<ReconciliationBanner>", () => {
  it("says why a run waits when reconciliation is required", () => {
    render(
      <ReconciliationBanner
        reconciliation={{
          mode: "required",
          lateness_deduction_enabled: false,
          totals: { open: 2, pending: 1, resolved: 0, blocking: 3 },
          by_type: [],
          unmatched_punches: 0,
        }}
      />,
      { wrapper: wrapper() },
    );

    expect(
      screen.getByText(/2 open, 1 waiting for a decision/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/cannot be approved until they are settled/),
    ).toBeInTheDocument();
  });

  it("shows nothing while reconciliation is off", () => {
    const { container } = render(
      <ReconciliationBanner
        reconciliation={{
          mode: "off",
          lateness_deduction_enabled: false,
          totals: { open: 5, pending: 0, resolved: 0, blocking: 5 },
          by_type: [],
          unmatched_punches: 0,
        }}
      />,
      { wrapper: wrapper() },
    );

    expect(container).toBeEmptyDOMElement();
  });
});

describe("<DeductionCeilingBanner>", () => {
  it("lists a payslip whose deductions pass a third of basic, and nothing when none do", () => {
    const { rerender, container } = render(
      <DeductionCeilingBanner
        ceiling={{
          count: 1,
          employees: [
            {
              employee_public_id: "01HZEMP0000000000000000001",
              employee_name: "Abebe Kebede",
              ceiling_cents: 166_666,
              deducted_cents: 200_000,
            },
          ],
        }}
      />,
      { wrapper: wrapper() },
    );

    expect(
      screen.getByText(/1 payslip\(s\) deduct more than a third of basic pay/),
    ).toBeInTheDocument();
    expect(screen.getByText("2,000.00 ETB")).toBeInTheDocument();
    expect(screen.getByText("1,666.66 ETB")).toBeInTheDocument();

    rerender(<DeductionCeilingBanner ceiling={{ count: 0, employees: [] }} />);
    expect(container).toBeEmptyDOMElement();
  });
});
