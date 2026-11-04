import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import DashboardPage from "@/app/(dashboard)/dashboard/page";

/**
 * The dashboard's charts pull in recharts, about 98 KB brotli. Splitting them
 * into their own chunk was not enough: a split chunk downloads as soon as its
 * component renders, and the charts component rendered for everyone only to
 * return null for an employee. Measured 2026-11-01, every signed-in reader of
 * /dashboard fetched recharts. These tests pin who mounts the charts and when;
 * mounting is what fetches the chunk.
 */

const session = vi.hoisted(() => ({
  isSupervisor: false,
  can: {} as Record<string, boolean>,
  chartsMounted: 0,
  teamSize: 8,
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => false,
    hasRole: () => false,
    can: session.can,
    role: session.isSupervisor ? "supervisor" : "employee",
    isSupervisor: session.isSupervisor,
    isSuperAdmin: false,
  }),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
}));

// The page's one dynamic import is the charts.
vi.mock("next/dynamic", () => ({
  default: () =>
    function Charts() {
      session.chartsMounted += 1;
      return <div data-testid="charts" />;
    },
}));
vi.mock("@/features/next-steps/components/next-steps-card", () => ({
  NextStepsCard: () => null,
}));
vi.mock("@/components/shared/install-app", () => ({
  InstallAppBanner: () => null,
}));
vi.mock("@/features/dashboard/components/voice-and-integrity-prompts", () => ({
  PulsePrompt: () => null,
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
    CalendarWidget: none,
    TeamOverview: none,
    LeaveOverview: none,
    OrganisationSnapshot: none,
  };
});

/** An IntersectionObserver the test moves the page under. */
const observers: Array<{
  callback: IntersectionObserverCallback;
  root: Element | Document | null | undefined;
  margin: string | undefined;
  disconnected: boolean;
}> = [];

/**
 * The charts' own observer: the side column's light widgets wait too, with a
 * 200 px margin (PR 11), and the charts keep their 600 px one.
 */
function chartObservers() {
  return observers.filter((o) => o.margin === "600px");
}

class FakeIntersectionObserver {
  private entry: (typeof observers)[number];
  constructor(
    callback: IntersectionObserverCallback,
    options?: IntersectionObserverInit,
  ) {
    this.entry = {
      callback,
      root: options?.root,
      margin: options?.rootMargin,
      disconnected: false,
    };
    observers.push(this.entry);
  }
  observe() {}
  unobserve() {}
  disconnect() {
    this.entry.disconnected = true;
  }
  takeRecords() {
    return [];
  }
}

function scrollChartsNear() {
  act(() => {
    for (const o of observers) {
      if (o.disconnected) continue;
      o.callback(
        [{ isIntersecting: true } as IntersectionObserverEntry],
        {} as IntersectionObserver,
      );
    }
  });
}

beforeEach(() => {
  session.isSupervisor = false;
  session.can = {};
  session.chartsMounted = 0;
  session.teamSize = 8;
  observers.length = 0;
  vi.stubGlobal("IntersectionObserver", FakeIntersectionObserver);
  server.use(
    http.get("*/api/v1/dashboard/employee", () =>
      HttpResponse.json({
        attendance_today: { status: "not_checked_in" },
        leave_balances: [],
        latest_payslip: null,
        upcoming_holidays: [],
      }),
    ),
    // Eight direct reports: the figure that decides whether a supervisor is
    // offered the team chart at all.
    http.get("*/api/v1/dashboard/manager", () =>
      HttpResponse.json({ team_size: session.teamSize }),
    ),
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER", name: "Abebe", role: "employee" },
        permissions: [],
        tenant: null,
      }),
    ),
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
});

// The shell's <main> is the element that scrolls, not the window.
function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>
        <main data-testid="scroller" style={{ overflowY: "auto" }}>
          <DashboardPage />
        </main>
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("Dashboard charts", () => {
  it("are never mounted for someone who would not be shown them", () => {
    renderPage();
    scrollChartsNear();

    expect(session.chartsMounted).toBe(0);
    expect(chartObservers()).toHaveLength(0);
  });

  it("wait for a supervisor to come near them, keeping their height", () => {
    session.isSupervisor = true;
    const { container } = renderPage();

    expect(session.chartsMounted).toBe(0);
    expect(container.querySelector(".h-\\[360px\\]")).not.toBeNull();

    scrollChartsNear();

    expect(screen.getByTestId("charts")).toBeInTheDocument();
    expect(chartObservers().every((o) => o.disconnected)).toBe(true);
  });

  /**
   * Observed against the viewport, the chart area was clipped by the
   * scrolling <main>, and the margin that should fetch the chunk early never
   * applied (measured in Chrome, 2026-11-01).
   */
  it("watch the shell's scrolling area, not the window", () => {
    session.isSupervisor = true;
    renderPage();

    expect(chartObservers()).toHaveLength(1);
    expect(chartObservers()[0].root).toBe(screen.getByTestId("scroller"));
  });

  it("wait the same way for the executive view", () => {
    session.can = { viewExecutiveDashboard: true };
    renderPage();

    expect(session.chartsMounted).toBe(0);
    scrollChartsNear();
    expect(screen.getByTestId("charts")).toBeInTheDocument();
  });

  // A department head is "at least supervisor" and usually has no direct
  // reports; their chart card could only say the team recorded nothing.
  it("are not offered to a supervisor with no direct reports", async () => {
    session.isSupervisor = true;
    session.teamSize = 0;
    const { container } = renderPage();

    await waitFor(() =>
      expect(container.querySelector(".h-\\[360px\\]")).toBeNull(),
    );
    scrollChartsNear();
    expect(session.chartsMounted).toBe(0);
  });

  it("load straight away in a browser without IntersectionObserver", async () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    session.isSupervisor = true;
    renderPage();

    expect(await screen.findByTestId("charts")).toBeInTheDocument();
  });
});
