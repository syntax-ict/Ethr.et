import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import DashboardPage from "@/app/(dashboard)/dashboard/page";
import { AnnouncementsWidget } from "@/features/dashboard/components/announcements-widget";
import { RecentActivity } from "@/features/dashboard/components/recent-activity";

/**
 * The first screen after PR 7 of the dashboard review (2026-11-03): an
 * employee's balances and calendar fill the wide column that held only Next
 * steps, a supervisor's stay beside their queue, and an empty Announcements
 * or Recent Activity card is one line, not an illustration.
 */
const session = vi.hoisted(() => ({
  supervisor: false,
  executive: false,
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => false,
    hasRole: () => false,
    can: { viewExecutiveDashboard: session.executive },
    role: session.supervisor ? "supervisor" : "employee",
    isSupervisor: session.supervisor,
    isSuperAdmin: false,
    orgScope: null,
  }),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
}));

// Every widget but the two whose place is under test is stubbed, and those
// two become markers: the page's columns are the subject, not their contents.
vi.mock("next/dynamic", () => ({ default: () => () => null }));
vi.mock("@/features/next-steps/components/next-steps-card", () => ({
  NextStepsCard: () => null,
}));
vi.mock("@/features/dashboard/components", async (importOriginal) => {
  const actual =
    await importOriginal<typeof import("@/features/dashboard/components")>();
  const none = () => null;
  return {
    ...actual,
    WelcomeSection: none,
    PendingApprovalsPanel: none,
    RecentActivity: none,
    AnnouncementsWidget: none,
    TeamOverview: none,
    DepartmentOverview: none,
    OrganisationSnapshot: none,
    LeaveOverview: () => <div data-testid="leave-overview" />,
    CalendarWidget: () => <div data-testid="calendar-widget" />,
  };
});
vi.mock("@/features/dashboard/components/voice-and-integrity-prompts", () => ({
  PulsePrompt: () => null,
}));

const ME = {
  user: {
    public_id: "01HZUSER000000000000000001",
    name: "Abebe Kebede",
    email: "abebe@acme.et",
    role: "employee",
    employee_public_id: "01HZEMP0000000000000000001",
  },
  permissions: [],
  tenant: {
    public_id: "01HZTENANT0000000000000001",
    name: "Acme",
    timezone: "Africa/Addis_Ababa",
  },
};

const page = (rows: unknown[]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
  links: {},
});

function renderWith(ui: ReactNode) {
  server.use(
    http.get("*/api/v1/auth/me", () => HttpResponse.json(ME)),
    http.get("*/api/v1/dashboard/employee", () =>
      HttpResponse.json({
        attendance_today: { status: "not_checked_in" },
        leave_balances: [],
        latest_payslip: null,
        upcoming_holidays: [],
        pending_approvals: 0,
      }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>{ui}</CalendarProvider>
    </QueryClientProvider>,
  );
}

/** The wide column is the one spanning two of the three xl columns. */
function wideColumn(el: HTMLElement) {
  return el.closest(".xl\\:col-span-2");
}

beforeEach(() => {
  session.supervisor = false;
  session.executive = false;
});

describe("Dashboard first screen", () => {
  it("puts an employee's balances and calendar in the wide column", async () => {
    renderWith(<DashboardPage />);

    const leave = await screen.findByTestId("leave-overview");
    expect(wideColumn(leave)).not.toBeNull();
    expect(wideColumn(screen.getByTestId("calendar-widget"))).not.toBeNull();
  });

  it("keeps a supervisor's balances and calendar beside the queue", async () => {
    session.supervisor = true;
    renderWith(<DashboardPage />);

    const leave = await screen.findByTestId("leave-overview");
    expect(wideColumn(leave)).toBeNull();
    expect(wideColumn(screen.getByTestId("calendar-widget"))).toBeNull();
  });

  it("keeps an executive's calendar beside the queue too", async () => {
    session.executive = true;
    renderWith(<DashboardPage />);

    expect(wideColumn(await screen.findByTestId("calendar-widget"))).toBeNull();
  });
});

describe("Empty side cards", () => {
  it("say in one line that there are no announcements, and still link to them", async () => {
    server.use(
      http.get("*/api/v1/announcements", () => HttpResponse.json(page([]))),
    );
    renderWith(<AnnouncementsWidget />);

    expect(await screen.findByText("No announcements")).toBeInTheDocument();
    expect(screen.queryByText(/will appear here/)).toBeNull();
    expect(screen.getByRole("link", { name: /view all/i })).toHaveAttribute(
      "href",
      "/announcements",
    );
  });

  it("say in one line that there is no activity", async () => {
    server.use(
      http.get("*/api/v1/notifications", () => HttpResponse.json(page([]))),
    );
    renderWith(<RecentActivity />);

    expect(await screen.findByText("No recent activity")).toBeInTheDocument();
    expect(screen.queryByText(/will appear here/)).toBeNull();
    expect(screen.getByRole("link", { name: /view all/i })).toHaveAttribute(
      "href",
      "/notifications",
    );
  });
});
