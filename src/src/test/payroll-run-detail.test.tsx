import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PayrollRunDetail } from "@/app/(dashboard)/payroll/[id]/payroll-run-detail";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like PayrollRunResource with its entries loaded. */
const RUN = {
  public_id: "RUN1",
  period_label: "Meskerem 2019",
  period_start: "2026-09-11",
  period_end: "2026-10-10",
  status: "completed",
  employee_count: 1,
  gross_total_cents: 1500000,
  net_total_cents: 1234567,
  tax_total_cents: 200000,
  entries: [
    {
      public_id: "ENT1",
      employee: { public_id: "EMP1", name: "Abebe Kebede" },
      employee_public_id: "EMP1",
      basic_salary_cents: 1500000,
      allowances: [],
      deductions: [],
      gross_cents: 1500000,
      income_tax_cents: 200000,
      employee_pension_cents: 105000,
      employer_pension_cents: 165000,
      other_deductions_cents: 0,
      net_cents: 1234567,
    },
  ],
  processed_at: "2026-10-10T06:00:00Z",
  approved_at: null,
  voided_at: null,
  void_reason: null,
  reprocessed_from_public_id: null,
  created_at: "2026-10-10T06:00:00Z",
};

function me(role: string, permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function renderDetail() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<PayrollRunDetail routeId="RUN1" />, { wrapper: Wrapper });
}

let saved: Blob[] = [];

beforeEach(() => {
  saved = [];
  vi.spyOn(URL, "createObjectURL").mockImplementation((blob) => {
    saved.push(blob as Blob);
    return "blob:mock";
  });
  vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});
  server.use(
    http.get("*/api/v1/payroll/runs/RUN1", () => HttpResponse.json(RUN)),
  );
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("<PayrollRunDetail> actions", () => {
  it("offers Approve to a role holding payroll.approve, whatever its tier", async () => {
    // PayrollRunPolicy checks `payroll.approve`; the page asked for the
    // tenant-admin tier, so a finance-tier custom role granted the permission
    // never saw the button.
    server.use(me("finance_admin", ["payroll.viewAll", "payroll.approve"]));
    renderDetail();

    expect(
      await screen.findByRole("button", { name: /Approve Payroll/ }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Void Payroll/ }),
    ).not.toBeInTheDocument();
  });

  it("hides Approve from a role without payroll.approve", async () => {
    server.use(me("tenant_admin", ["payroll.viewAll", "payroll.void"]));
    renderDetail();

    expect(
      await screen.findByRole("button", { name: /Void Payroll/ }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /Approve Payroll/ }),
    ).not.toBeInTheDocument();
  });
});

describe("<PayrollRunDetail> exports", () => {
  it("writes the bank transfer file with each amount in one column", async () => {
    server.use(
      me("finance_admin", ["payroll.viewAll"]),
      http.get("*/api/v1/payroll/runs/RUN1/export/bank", () =>
        HttpResponse.json({
          period: "Meskerem 2019",
          total_entries: 1,
          total_amount_cents: 1234567,
          rows: [
            {
              employee_name: "Abebe Kebede",
              employee_code: "E001",
              bank_name: "Commercial Bank of Ethiopia",
              branch_name: "Bole",
              account_number: "1000123456789",
              net_amount_cents: 1234567,
            },
          ],
        }),
      ),
    );
    const user = userEvent.setup();
    renderDetail();

    await user.click(await screen.findByRole("button", { name: /Export/ }));
    await user.click(
      await screen.findByRole("menuitem", { name: /Bank Transfer/i }),
    );

    await waitFor(() => expect(saved).toHaveLength(1));
    expect((await saved[0].text()).split("\n")).toEqual([
      "Employee Name,Employee Code,Bank,Branch,Account Number,Net Amount (ETB)",
      "Abebe Kebede,E001,Commercial Bank of Ethiopia,Bole,1000123456789,12345.67",
    ]);
  });

  it("writes the same journal file as the Accounting page", async () => {
    server.use(
      me("finance_admin", ["payroll.viewAll"]),
      http.get("*/api/v1/accounting/journal/RUN1", () =>
        HttpResponse.json({
          period: "Meskerem 2019",
          date: "2026-10-10",
          reference: "PAYROLL-RUN1",
          entries: [
            {
              account_code: "5100",
              account_name: "Salary Expense",
              debit_cents: 1500000,
              credit_cents: 0,
            },
          ],
          total_debits_cents: 1500000,
          total_credits_cents: 1500000,
          is_balanced: true,
        }),
      ),
    );
    const user = userEvent.setup();
    renderDetail();

    await user.click(await screen.findByRole("button", { name: /Export/ }));
    await user.click(await screen.findByRole("menuitem", { name: /Journal/i }));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect((await saved[0].text()).split("\n")).toEqual([
      "Reference,Period,Date,Account Code,Account Name,Debit (ETB),Credit (ETB)",
      "PAYROLL-RUN1,Meskerem 2019,2026-10-10,5100,Salary Expense,15000.00,",
      ",TOTALS,,,,15000.00,15000.00",
    ]);
  });
});
