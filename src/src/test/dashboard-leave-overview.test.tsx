import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { LeaveOverview } from "@/features/dashboard/components/leave-overview";

/**
 * `GET /dashboard/employee`, as EmployeeDashboardService::assemble builds it.
 * `entitled` and `used` are LeaveBalance's `decimal:1` casts, sent as numbers
 * since audit N13 (they were strings such as "16.0"); `remaining` is
 * `remainingDays()`. `type` is `leaveType?->name`, null once the leave type
 * has been soft-deleted.
 */
const DASHBOARD = {
  attendance_today: null,
  leave_balances: [
    { type: "Annual", entitled: 16, used: 4.5, remaining: 11.5 },
    { type: null, entitled: 5, used: 0, remaining: 5 },
  ],
  latest_payslip: null,
  upcoming_holidays: [],
  pending_approvals: 0,
  tenant_summary: { employee_count: 1, department_count: 1, branch_count: 1 },
  onboarding_complete: true,
};

function renderWidget() {
  server.use(
    http.get("*/api/v1/dashboard/employee", () => HttpResponse.json(DASHBOARD)),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<LeaveOverview />, { wrapper });
}

describe("Dashboard leave balances", () => {
  it("shows day counts as numbers, not the decimal casts' strings", async () => {
    renderWidget();

    await screen.findByText("Annual");
    // Was "11.5/16.0": the entitlement printed as the raw "16.0" string
    // beside a remaining figure that had none.
    expect(screen.getByText("/16")).toBeInTheDocument();
    expect(screen.queryByText("/16.0")).not.toBeInTheDocument();
    expect(screen.getByText("4.5 used")).toBeInTheDocument();
    expect(
      screen.getByRole("progressbar", { name: "Annual leave: 28% used" }),
    ).toBeInTheDocument();
  });

  it("does not print 'null' for a balance whose leave type was deleted", async () => {
    renderWidget();

    await screen.findByText("Annual");
    expect(screen.queryByText("null")).not.toBeInTheDocument();
    expect(screen.getByText("—")).toBeInTheDocument();
  });
});
