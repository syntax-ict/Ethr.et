import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ContractsEndingPage from "@/app/(dashboard)/employees/contracts/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("@/components/shared/role-gate", () => ({
  RoleGate: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

/**
 * Contracts ending (2026-10-23): the watchlist GET /employees/contracts/expiring
 * already served had no screen, so a contract could lapse with nobody told.
 */
function contract(overrides: Record<string, unknown>) {
  return {
    public_id: "01HZCON0000000000000000001",
    reference_number: null,
    contract_type: "fixed_term",
    start_date: "2025-11-01",
    end_date: "2026-11-01",
    salary_cents: null,
    terms: null,
    status: "active",
    ended_at: null,
    end_notes: null,
    is_expired: false,
    expires_soon: true,
    days_until_expiry: 9,
    employee_name: "Selam Tesfaye",
    employee_public_id: "01HZEMP0000000000000000001",
    created_at: "2025-11-01T06:00:00Z",
    ...overrides,
  };
}

function page(rows: unknown[], total = rows.length) {
  return {
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: rows.length ? 1 : null,
      last_page: 1,
      links: [],
      path: null,
      per_page: 100,
      to: rows.length || null,
      total,
    },
  };
}

function renderPage() {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        permissions: ["employee.create"],
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>
        <ContractsEndingPage />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("Contracts ending page", () => {
  beforeEach(() => {
    window.history.replaceState(null, "", "/employees/contracts");
  });

  it("lists each contract with whose it is, when it ends, and links to their Contracts tab", async () => {
    server.use(
      http.get("*/api/v1/employees/contracts/expiring", () =>
        HttpResponse.json(
          page([
            contract({
              public_id: "01HZCON0000000000000000002",
              employee_name: "Abebe Kebede",
              employee_public_id: "01HZEMP0000000000000000002",
              contract_type: "probation",
              is_expired: true,
              expires_soon: false,
              days_until_expiry: -3,
            }),
            contract({}),
          ]),
        ),
      ),
    );
    renderPage();

    const items = await screen.findAllByRole("listitem");
    expect(items).toHaveLength(2);
    expect(within(items[0]).getByText("Abebe Kebede")).toHaveAttribute(
      "href",
      "/employees/01HZEMP0000000000000000002?tab=contracts",
    );
    expect(items[0]).toHaveTextContent(
      "Ended 3 days ago, not renewed or ended",
    );
    expect(items[0]).toHaveTextContent("Probation");
    expect(items[1]).toHaveTextContent("Ends in 9 days");
    expect(items[1]).toHaveTextContent("Fixed term");
  });

  it("asks for the window chosen, and keeps it in the address", async () => {
    const asked: (string | null)[] = [];
    server.use(
      http.get("*/api/v1/employees/contracts/expiring", ({ request }) => {
        asked.push(new URL(request.url).searchParams.get("days"));
        return HttpResponse.json(page([]));
      }),
    );
    const user = userEvent.setup();
    renderPage();

    expect(
      await screen.findByText("No contract ends in the next 30 days"),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("tab", { name: "Next 90 days" }));
    await waitFor(() => expect(asked).toContain("90"));
    expect(asked[0]).toBe("30");
    expect(window.location.search).toBe("?days=90");
  });

  it("says when there are more than it shows, rather than implying the list is whole", async () => {
    server.use(
      http.get("*/api/v1/employees/contracts/expiring", () =>
        HttpResponse.json(page([contract({})], 140)),
      ),
    );
    renderPage();

    expect(
      await screen.findByText("Showing the first 1 of 140, soonest first."),
    ).toBeInTheDocument();
  });
});
