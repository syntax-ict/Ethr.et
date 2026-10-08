import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import LoansPage from "@/app/(dashboard)/payroll/loans/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

// The page is wrapped in <RoleGate allowedRoles={[...]}>; grant access directly.
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    can: {},
    role: "finance_admin",
  }),
}));

/** Shaped like EmployeeLoanResource, as LoanController::store returns it. */
const CREATED_LOAN = {
  public_id: "01HZLOAN000000000000000001",
  employee_public_id: "01HZEMPL000000000000000001",
  amount_cents: 500_000,
  remaining_cents: 500_000,
  monthly_deduction_cents: 50_000,
  start_date: "2026-10-02",
  end_date: null,
  status: "active",
  reason: "Medical expenses",
  created_at: "2026-10-02T06:00:00Z",
  updated_at: "2026-10-02T06:00:00Z",
};

const EMPTY_PAGE = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
  links: { first: null, last: null, prev: null, next: null },
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<LoansPage />, { wrapper });
}

describe("Loans page — new loan", () => {
  /**
   * The form has always had a Reason field, and StoreLoanRequest has always
   * accepted `reason` — but the submit handler built its body from three
   * fields and left it out, so whatever finance typed was dropped and every
   * loan was stored with no reason.
   */
  it("sends the reason typed into the form", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/payroll/loans", () => HttpResponse.json(EMPTY_PAGE)),
      http.post("*/api/v1/payroll/loans", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(CREATED_LOAN, { status: 201 });
      }),
    );

    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("button", { name: /new loan/i }));
    await user.type(
      await screen.findByPlaceholderText("Paste employee public_id"),
      CREATED_LOAN.employee_public_id,
    );
    const [amount, monthly] = screen.getAllByRole("spinbutton");
    await user.type(amount, "5000");
    await user.type(monthly, "500");
    await user.type(
      screen.getByPlaceholderText("Optional"),
      "Medical expenses",
    );
    await user.click(screen.getByRole("button", { name: /create loan/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({
      employee_public_id: CREATED_LOAN.employee_public_id,
      amount_cents: 500_000,
      monthly_deduction_cents: 50_000,
      reason: "Medical expenses",
    });
  });

  it("sends a null reason when the field is left empty", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/payroll/loans", () => HttpResponse.json(EMPTY_PAGE)),
      http.post("*/api/v1/payroll/loans", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          { ...CREATED_LOAN, reason: null },
          { status: 201 },
        );
      }),
    );

    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("button", { name: /new loan/i }));
    await user.type(
      await screen.findByPlaceholderText("Paste employee public_id"),
      CREATED_LOAN.employee_public_id,
    );
    const [amount, monthly] = screen.getAllByRole("spinbutton");
    await user.type(amount, "5000");
    await user.type(monthly, "500");
    await user.click(screen.getByRole("button", { name: /create loan/i }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ reason: null });
  });
});

describe("Loans page — the list", () => {
  /**
   * The page has no pager and GET /payroll/loans pages at 25, so a loan on
   * the second page could never be seen, repaid or checked.
   */
  it("shows loans past the first page", async () => {
    const loan = (n: number, name: string) => ({
      ...CREATED_LOAN,
      public_id: `01HZLOAN0000000000000000${String(n).padStart(2, "0")}`,
      employee: { public_id: `01HZEMP${n}`, name },
    });
    server.use(
      http.get("*/api/v1/payroll/loans", ({ request }) => {
        const page = Number(new URL(request.url).searchParams.get("page") ?? 1);
        return HttpResponse.json({
          data: [page === 1 ? loan(1, "First Page") : loan(2, "Second Page")],
          meta: { current_page: page, last_page: 2, per_page: 1, total: 2 },
          links: { first: null, last: null, prev: null, next: null },
        });
      }),
    );

    renderPage();

    expect(await screen.findByText("First Page")).toBeInTheDocument();
    expect(await screen.findByText("Second Page")).toBeInTheDocument();
  });
});
