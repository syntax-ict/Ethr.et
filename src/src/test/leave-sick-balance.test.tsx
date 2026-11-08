import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
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
    role: "employee",
    level: 10,
    permissions: [],
    hasPermission: () => false,
    isAtLeast: () => false,
    hasRole: () => false,
    isSuperAdmin: false,
    isTenantAdmin: false,
    isHrAdmin: false,
    isFinanceAdmin: false,
    isSupervisor: false,
    isEmployee: true,
    can: new Proxy({} as Record<string, boolean>, { get: () => false }),
  }),
}));

function serveBalances(balances: unknown[]) {
  const page = {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    links: { first: null, last: null, prev: null, next: null },
  };
  server.use(
    http.get("*/api/v1/leave/balance", () => HttpResponse.json(balances)),
    http.get("*/api/v1/leave/my", () => HttpResponse.json(page)),
    http.get("*/api/v1/leave/team", () => HttpResponse.json(page)),
    http.get("*/api/v1/leave-types", () => HttpResponse.json(page)),
  );
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

/*
 * K-50 (testing pack, 2026-11-08). Art. 85 sick leave is six months within
 * twelve, not the 30-day bucket, so after a long illness the card printed
 * entitled minus used: a negative number of days.
 */
describe("the Sick balance card", () => {
  it("shows days taken in the Art. 85 period and the next day's pay, never a negative remainder", async () => {
    serveBalances([
      {
        leave_type: { name: "Annual", code: "annual", public_id: "01HZLT01" },
        entitled_days: 16,
        used_days: 2,
        remaining_days: 14,
      },
      {
        leave_type: { name: "Sick", code: "sick", public_id: "01HZLT02" },
        entitled_days: 30,
        used_days: 42,
        remaining_days: -12,
        sick_leave: {
          days_taken: 42,
          days_allowed: 132,
          period_start: "2026-04-01",
          period_end: "2027-03-31",
          next_tier: "half",
        },
      },
    ]);
    renderPage();

    const card = (await screen.findAllByText("Sick"))[0].closest(
      ".rounded-xl",
    ) as HTMLElement;

    expect(await within(card).findByText("42")).toBeInTheDocument();
    expect(within(card).getByText("days taken")).toBeInTheDocument();
    expect(within(card).getByText(/42 of 132 days/)).toBeInTheDocument();
    expect(
      within(card).getByText("Next sick day at half pay"),
    ).toBeInTheDocument();
    expect(document.body.textContent).not.toContain("-12");

    // The annual card is unchanged.
    const annual = screen
      .getAllByText("Annual")[0]
      .closest(".rounded-xl") as HTMLElement;
    expect(within(annual).getByText("14")).toBeInTheDocument();
    expect(within(annual).getByText("2 used of 16")).toBeInTheDocument();
  });

  it("never shows a negative remainder, even without the period figures", async () => {
    serveBalances([
      {
        leave_type: { name: "Sick", code: "sick", public_id: "01HZLT02" },
        entitled_days: 30,
        used_days: 42,
        remaining_days: -12,
      },
    ]);
    renderPage();

    const card = (await screen.findAllByText("Sick"))[0].closest(
      ".rounded-xl",
    ) as HTMLElement;

    expect(card.textContent).not.toContain("-12");
    expect(within(card).getByText("0")).toBeInTheDocument();
  });
});
