import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import LeavePage from "@/app/(dashboard)/leave/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

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

/** Today in Addis Ababa, the zone the team calendar opens its month in. */
function today(): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Africa/Addis_Ababa",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
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

function renderPage({ calendar = false } = {}) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      {calendar ? (
        <CalendarProvider>
          <LeavePage />
        </CalendarProvider>
      ) : (
        <LeavePage />
      )}
    </QueryClientProvider>,
  );
}

afterEach(() => {
  vi.useRealTimers();
  localStorage.removeItem("ethr.calendar");
});

describe("the team leave tab", () => {
  // The rows printed the API's "2026-10-22 — 2026-10-22" whatever calendar the
  // organisation uses (FRONTEND_REDESIGN_PLAN.md, backlog 7).
  it("shows each request's dates formatted, not as raw ISO strings", async () => {
    serveLeave();
    renderPage();

    await userEvent.click(await screen.findByRole("tab", { name: /team/i }));
    const name = await screen.findByText("Abebe Kebede");
    const row = name.closest("tr, li, [data-row]") ?? name.parentElement!;

    expect(row.textContent).not.toContain(today());
    expect(row.textContent).toMatch(/\S+ to \S+/);
  });

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

  // The grid is a Gregorian month, but an Ethiopian-calendar reader saw
  // "November 2026" over Gregorian day numbers.
  it("heads the calendar with the Ethiopian range it covers", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-11-01T09:00:00Z"));
    localStorage.setItem("ethr.calendar", "ethiopian");
    serveLeave();
    renderPage({ calendar: true });

    await userEvent.click(await screen.findByRole("tab", { name: /team/i }));
    await screen.findByText("Abebe Kebede");
    await userEvent.click(
      screen.getByRole("button", { name: /calendar view/i }),
    );

    // 1 to 30 November 2026 is Tikimt 22 to Hidar 21, 2019.
    expect(
      await screen.findByText("Tikimt 22 – Hidar 21, 2019"),
    ).toBeInTheDocument();
    expect(screen.queryByText("November 2026")).not.toBeInTheDocument();
    // Each cell carries its Ethiopian day, and the month name on the 1st
    // (10 November is Hidar 1).
    expect(screen.getByText("Hidar")).toBeInTheDocument();
    expect(screen.getByTitle("Hidar 1, 2019")).toHaveTextContent("1");
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

/**
 * Rejecting a team request sent the literal word "Rejected" in one click, so
 * the employee never learned why. It now asks for the reason, which the
 * employee sees, and will not send without one.
 */
describe("rejecting a team leave request", () => {
  it("asks why, and sends the reason that was typed", async () => {
    serveLeave();
    const sent: { reason?: string }[] = [];
    server.use(
      http.get("*/api/v1/leave/team", () =>
        HttpResponse.json({
          data: [teamRequest("Abebe Kebede", "pending")],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
          links: { first: null, last: null, prev: null, next: null },
        }),
      ),
      http.put("*/api/v1/leave/:id/reject", async ({ request }) => {
        sent.push((await request.json()) as { reason?: string });
        return HttpResponse.json({});
      }),
    );
    renderPage();
    const user = userEvent.setup();

    await user.click(await screen.findByRole("tab", { name: /team/i }));
    await user.click(await screen.findByRole("button", { name: "Reject" }));

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("Abebe Kebede");
    const confirm = screen.getAllByRole("button", { name: "Reject" }).at(-1)!;
    expect(confirm).toBeDisabled();

    await user.type(
      screen.getByRole("textbox", { name: /reason for rejection/i }),
      "Team is at minimum cover that week.",
    );
    expect(confirm).toBeEnabled();
    await user.click(confirm);

    await waitFor(() =>
      expect(sent).toEqual([{ reason: "Team is at minimum cover that week." }]),
    );
    expect(sent[0].reason).not.toBe("Rejected");
  });
});
