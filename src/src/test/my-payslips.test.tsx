import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import MyPayslipsPage from "@/app/(dashboard)/payroll/payslips/page";

/**
 * `GET /payroll/payslips/my` — PayrollController::myPayslips loads `employee`,
 * so each PayrollEntryResource carries the employee it belongs to.
 */
const PAYSLIPS = {
  data: [
    {
      public_id: "01HZENTRY00000000000000001",
      employee: {
        public_id: "01HZEMPL000000000000000001",
        name: "Abebe Kebede",
      },
      employee_public_id: "01HZEMPL000000000000000001",
      basic_salary_cents: 1_000_000,
      allowances: null,
      deductions: null,
      gross_cents: 1_000_000,
      income_tax_cents: 200_000,
      employee_pension_cents: 70_000,
      employer_pension_cents: 110_000,
      other_deductions_cents: 0,
      net_cents: 730_000,
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
  links: { first: null, last: null, prev: null, next: null },
};

/** The signed-in user — whose login email is not their name. */
const ME = {
  user: {
    public_id: "01HZUSER000000000000000001",
    name: "Abebe Kebede",
    email: "abebe@acme.et",
    role: "employee",
  },
  tenant: { public_id: "01HZTENANT0000000000000001", name: "Acme" },
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<MyPayslipsPage />, { wrapper });
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe("My Payslips — printed payslip", () => {
  /**
   * The printed payslip put the signed-in user's login email where the
   * employee's name belongs, because the hand-written PayrollEntry type had no
   * `employee` and the page reached for the only identity it had. The entry
   * has always carried its employee.
   */
  it("names the employee on the printed payslip, not the login email", async () => {
    server.use(
      http.get("*/api/v1/payroll/payslips/my", () =>
        HttpResponse.json(PAYSLIPS),
      ),
      http.get("*/api/v1/auth/me", () => HttpResponse.json(ME)),
    );

    let html = "";
    const fakeWindow = {
      document: {
        write: (chunk: string) => {
          html += chunk;
        },
        close: () => {},
      },
    };
    vi.spyOn(window, "open").mockReturnValue(fakeWindow as unknown as Window);

    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: "Download payslip" }),
    );

    expect(html).toContain("Abebe Kebede");
    expect(html).not.toContain("abebe@acme.et");
  });
});
