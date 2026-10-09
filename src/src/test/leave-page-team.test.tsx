import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import LeavePage from "@/app/(dashboard)/leave/page";

vi.mock("next/navigation", () => ({
  usePathname: () => "/leave",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "supervisor",
    level: 50,
    permissions: [],
    hasPermission: () => true,
    isAtLeast: () => true,
    hasRole: () => false,
    isSuperAdmin: false,
    isTenantAdmin: false,
    isHrAdmin: false,
    isFinanceAdmin: false,
    isSupervisor: true,
    isEmployee: false,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

function today(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function teamRequest(name: string, status: string) {
  return {
    public_id: `01HZLEAVE${name.toUpperCase().slice(0, 6)}`,
    employee: { public_id: `01HZEMP${name.toUpperCase().slice(0, 6)}`, name },
    leave_type: { name: "Annual", code: "annual", public_id: "01HZLT01" },
    start_date: today(),
    end_date: today(),
    days: 1,
    reason: null,
    status,
    created_at: "2026-10-01T00:00:00Z",
  };
}

function serveLeave(): string[] {
  const leaveTypeQueries: string[] = [];
  const page = (data: unknown[]) => ({
    data,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
    links: { first: null, last: null, prev: null, next: null },
  });
  server.use(
    http.get("*/api/v1/leave/balance", () => HttpResponse.json([])),
    http.get("*/api/v1/leave/my", () => HttpResponse.json(page([]))),
    http.get("*/api/v1/leave/team", () =>
      HttpResponse.json(
        page([
          teamRequest("Abebe Kebede", "approved"),
          teamRequest("Sara Tesfaye", "cancelled"),
        ]),
      ),
    ),
    http.get("*/api/v1/leave-types", ({ request }) => {
      leaveTypeQueries.push(new URL(request.url).search);
      return HttpResponse.json(page([]));
    }),
  );
  return leaveTypeQueries;
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <LeavePage />
    </QueryClientProvider>,
  );
}

describe("the team leave tab", () => {
  it("names who asked for each request", async () => {
    serveLeave();
    renderPage();

    await userEvent.click(await screen.findByRole("tab", { name: /team/i }));

    // The list read an `employee_name` the API never sends, so every row
    // said "—". The name is nested under `employee`.
    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
  });

  it("leaves cancelled requests off the calendar", async () => {
    serveLeave();
    renderPage();

    await userEvent.click(await screen.findByRole("tab", { name: /team/i }));
    await screen.findByText("Abebe Kebede");
    await userEvent.click(
      screen.getByRole("button", { name: /calendar view/i }),
    );

    // Badges print the first name.
    expect(await screen.findByText("Abebe")).toBeInTheDocument();
    expect(screen.queryByText("Sara")).not.toBeInTheDocument();
  });
});

describe("the leave request form", () => {
  it("offers only active leave types", async () => {
    const queries = serveLeave();
    renderPage();

    await waitFor(() => expect(queries.length).toBeGreaterThan(0));
    expect(decodeURIComponent(queries[0])).toContain("filter[is_active]=1");
  });
});
