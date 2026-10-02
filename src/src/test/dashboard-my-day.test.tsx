import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { WelcomeSection } from "@/features/dashboard/components/welcome-section";
import DashboardPage from "@/app/(dashboard)/dashboard/page";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => false,
    hasRole: () => false,
    can: {},
    role: "employee",
    isSupervisor: false,
  }),
}));

// The page test is about its own "My Day" tiles; every other widget is
// stubbed so it does not need its own endpoints.
vi.mock("next/dynamic", () => ({ default: () => () => null }));
vi.mock("@/features/dashboard/components", async (importOriginal) => {
  const actual =
    await importOriginal<typeof import("@/features/dashboard/components")>();
  const none = () => null;
  return {
    ...actual,
    WelcomeSection: none,
    SetupProgress: none,
    PendingApprovalsPanel: none,
    RecentActivity: none,
    AnnouncementsWidget: none,
    CalendarWidget: none,
    TeamOverview: none,
    LeaveOverview: none,
  };
});

/**
 * `GET /dashboard/employee` for someone who checked in at 08:30 Addis Ababa
 * time. `check_in` is AttendanceRecord's `datetime` cast, so it arrives as a
 * UTC ISO-8601 instant — 05:30Z.
 */
const DASHBOARD = {
  attendance_today: {
    status: "checked_in",
    check_in: "2026-10-02T05:30:00.000000Z",
    check_out: null,
    worked_minutes: null,
  },
  leave_balances: [],
  latest_payslip: null,
  upcoming_holidays: [],
  pending_approvals: 0,
  tenant_summary: { employee_count: 1, department_count: 1, branch_count: 1 },
  onboarding_complete: true,
};

const ME = {
  user: {
    public_id: "01HZUSER000000000000000001",
    name: "Abebe Kebede",
    name_am: null,
    email: "abebe@acme.et",
    role: "employee",
    photo_thumb_url: null,
  },
  permissions: [],
  tenant: {
    public_id: "01HZTENANT0000000000000001",
    name: "Acme",
    subdomain: "acme",
    status: "active",
    timezone: "Africa/Addis_Ababa",
  },
};

function renderWith(ui: ReactNode, dashboard: object = DASHBOARD) {
  server.use(
    http.get("*/api/v1/dashboard/employee", () => HttpResponse.json(dashboard)),
    http.get("*/api/v1/auth/me", () => HttpResponse.json(ME)),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{ui}</CalendarProvider>
    </QueryClientProvider>,
  );
}

/**
 * Both places printed `attendance_today.check_in` as it arrived, so an
 * employee read "2026-10-02T05:30:00.000000Z" — a raw UTC instant, three hours
 * off the clock on the wall — instead of their check-in time.
 */
describe("Dashboard — today's check-in time", () => {
  it("shows the check-in as a local time in the welcome header", async () => {
    renderWith(<WelcomeSection />);

    expect(await screen.findByText("08:30")).toBeInTheDocument();
    expect(screen.queryByText(/05:30:00/)).not.toBeInTheDocument();
  });

  it("shows the check-in as a local time on the attendance tile", async () => {
    renderWith(<DashboardPage />);

    expect(await screen.findByText("Since 08:30")).toBeInTheDocument();
    expect(screen.queryByText(/05:30:00/)).not.toBeInTheDocument();
  });
});

describe("Dashboard — leave balance tile", () => {
  /**
   * `type` is `leaveType?->name`, null once the leave type has been
   * soft-deleted; `String(type)` put the word "null" under the balance.
   */
  it("does not print 'null' for a balance whose leave type was deleted", async () => {
    renderWith(<DashboardPage />, {
      ...DASHBOARD,
      leave_balances: [{ type: null, entitled: 5, used: 0, remaining: 5 }],
    });

    expect(await screen.findByText("5 days")).toBeInTheDocument();
    expect(screen.queryByText("null")).not.toBeInTheDocument();
  });
});
