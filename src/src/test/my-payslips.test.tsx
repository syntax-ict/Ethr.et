import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
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

describe("My Payslips — download", () => {
  /**
   * Download opened a bare HTML page built in the browser: no allowance or
   * deduction lines, no organisation, and the server's PDF went unused. It now
   * fetches that PDF for this entry and saves it (audit N79).
   */
  it("saves the server's PDF for the entry", async () => {
    let requested = "";
    server.use(
      http.get("*/api/v1/payroll/payslips/my", () =>
        HttpResponse.json(PAYSLIPS),
      ),
      http.get("*/api/v1/auth/me", () => HttpResponse.json(ME)),
      http.get("*/api/v1/payroll/payslips/:entry/pdf", ({ params }) => {
        requested = String(params.entry);
        return new HttpResponse("%PDF-1.7", {
          headers: { "Content-Type": "application/pdf" },
        });
      }),
    );
    const createObjectURL = vi.fn(() => "blob:payslip");
    Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() });
    const click = vi
      .spyOn(HTMLAnchorElement.prototype, "click")
      .mockImplementation(() => {});
    const open = vi.spyOn(window, "open");

    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: "Download payslip" }),
    );

    await waitFor(() => expect(click).toHaveBeenCalled());
    expect(requested).toBe("01HZENTRY00000000000000001");
    expect(createObjectURL).toHaveBeenCalled();
    expect(open).not.toHaveBeenCalled();
  });
});

describe("My Payslips — telling payslips apart", () => {
  /**
   * PayrollEntryResource never sent the run's period, so every card was titled
   * "ETHR Payslip" and every download button announced the same name.
   */
  it("titles each payslip and its download with the run's period", async () => {
    const twoMonths = {
      ...PAYSLIPS,
      data: [
        { ...PAYSLIPS.data[0], period_label: "Meskerem 2019" },
        {
          ...PAYSLIPS.data[0],
          public_id: "01HZENTRY00000000000000002",
          period_label: "Tikimt 2019",
        },
      ],
    };
    server.use(
      http.get("*/api/v1/payroll/payslips/my", () =>
        HttpResponse.json(twoMonths),
      ),
      http.get("*/api/v1/auth/me", () => HttpResponse.json(ME)),
    );

    renderPage();

    expect(await screen.findByText("Meskerem 2019")).toBeInTheDocument();
    expect(screen.getByText("Tikimt 2019")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Download payslip — Tikimt 2019" }),
    ).toBeInTheDocument();
  });
});
