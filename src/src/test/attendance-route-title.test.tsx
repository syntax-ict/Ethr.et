import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { PageTitleBar } from "@/components/layouts/page-title-bar";
import { PageActionsSlotProvider } from "@/components/layouts/page-actions-slot";
import { getRouteMeta, routeText } from "@/lib/route-meta";

/**
 * `/attendance` is everyone's records for whoever may view all attendance and
 * the reader's own for everyone else. The title bar named both "Attendance",
 * "Track and manage employee attendance records", so an employee was told they
 * manage everyone's attendance on the page that shows only theirs.
 */

let pathname = "/attendance";
let viewAll = false;

vi.mock("next/navigation", () => ({
  usePathname: () => pathname,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: new Proxy(
      {},
      { get: (_, flag) => flag === "viewAllAttendance" && viewAll },
    ),
    isSupervisor: false,
    isTenantAdmin: viewAll,
    isSuperAdmin: false,
  }),
}));

function withQueries({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

function renderBar() {
  render(
    <PageActionsSlotProvider>
      <PageTitleBar />
    </PageActionsSlotProvider>,
    { wrapper: withQueries },
  );
}

const t = (_key: string, fallback: string) => fallback;

describe("the /attendance title", () => {
  beforeEach(() => {
    pathname = "/attendance";
  });

  it("names everyone's records for whoever may view all attendance", () => {
    viewAll = true;
    renderBar();

    expect(
      screen.getByRole("heading", { level: 1, name: "Attendance" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Everyone's check-ins and check-outs, by day, source and status",
      ),
    ).toBeInTheDocument();
  });

  it("names the reader's own records for everyone else", () => {
    viewAll = false;
    renderBar();

    expect(
      screen.getByRole("heading", { level: 1, name: "My Attendance" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Your check-ins, check-outs, and timesheet history"),
    ).toBeInTheDocument();
    expect(screen.queryByText(/manage/i)).not.toBeInTheDocument();
  });

  it("puts an employee's Mobile Check-in under My Attendance", () => {
    viewAll = false;
    pathname = "/attendance/mobile";
    renderBar();

    const trail = screen.getByRole("navigation", { name: /breadcrumb/i });
    expect(trail.querySelector('a[href="/attendance"]')?.textContent).toBe(
      "My Attendance",
    );
  });

  it("reads the own view's keys, not the shared ones", () => {
    const meta = getRouteMeta("/attendance")!;
    const keys: string[] = [];
    routeText("/attendance", meta, {}, (key, fallback) => {
      keys.push(key);
      return fallback;
    });
    expect(keys).toEqual([
      "route.attendance.own.label",
      "route.attendance.own.desc",
    ]);

    expect(
      routeText("/attendance", meta, { viewAllAttendance: true }, t).label,
    ).toBe("Attendance");
  });
});
