import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
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
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
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

describe("<PayrollRunDetail> skipped employees", () => {
  // Production QA 2026-10-15 F1: a run that skipped employees for having no
  // salary said nothing about them anywhere.
  it("says how many employees were skipped for having no salary", async () => {
    server.use(me("finance_admin", ["payroll.viewAll"]));
    server.use(
      http.get("*/api/v1/payroll/runs/RUN1", () =>
        HttpResponse.json({ ...RUN, employee_count: 1, skipped_no_salary: 2 }),
      ),
    );
    renderDetail();

    expect(
      await screen.findByText(
        "2 employees were skipped because no salary is set for them",
      ),
    ).toBeInTheDocument();
  });

  it("says nothing when nobody was skipped", async () => {
    server.use(me("finance_admin", ["payroll.viewAll"]));
    server.use(
      http.get("*/api/v1/payroll/runs/RUN1", () =>
        HttpResponse.json({ ...RUN, skipped_no_salary: 0 }),
      ),
    );
    renderDetail();

    await screen.findByText("Meskerem 2019");
    expect(screen.queryByText(/skipped because no salary/)).toBeNull();
  });
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
  it("offers no bank transfer file before the run is approved", async () => {
    server.use(me("finance_admin", ["payroll.viewAll"]));
    const user = userEvent.setup();
    renderDetail();

    await user.click(await screen.findByRole("button", { name: /Export/ }));
    expect(
      await screen.findByRole("menuitem", { name: /Payroll Register/i }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("menuitem", { name: /Bank Transfer/i }),
    ).not.toBeInTheDocument();
  });

  it("writes the bank transfer file with each amount in one column", async () => {
    server.use(
      me("finance_admin", ["payroll.viewAll"]),
      http.get("*/api/v1/payroll/runs/RUN1", () =>
        HttpResponse.json({ ...RUN, status: "approved" }),
      ),
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

// Approving releases every payslip in the run and cannot be undone from this
// screen, yet one click did it (found in the browser, 2026-10-08). Void already
// asked; approve and reprocess now ask through the same ConfirmDialog.
describe("<PayrollRunDetail> confirmation before approving", () => {
  it("sends nothing until the approval is confirmed, then approves", async () => {
    const approvals: string[] = [];
    server.use(
      me("finance_admin", ["payroll.viewAll", "payroll.approve"]),
      http.put("*/api/v1/payroll/runs/RUN1/approve", () => {
        approvals.push("approve");
        return HttpResponse.json({ ...RUN, status: "approved" });
      }),
    );
    renderDetail();

    await userEvent.click(
      await screen.findByRole("button", { name: /Approve Payroll/ }),
    );

    // The click opened a question, not a request.
    const dialog = await screen.findByRole("dialog");
    expect(approvals).toEqual([]);

    await userEvent.click(
      within(dialog).getByRole("button", { name: /Approve|Confirm/ }),
    );

    await waitFor(() => expect(approvals).toEqual(["approve"]));
  });
});

describe("<PayrollRunDetail> pension exemptions", () => {
  const exemptions = {
    count: 3,
    by_reason: { non_national: 2, ethiopian_origin_unconfirmed: 1 },
    employees: [
      {
        employee_public_id: "E1",
        employee_name: "Liam Otieno",
        reason: "non_national",
      },
      {
        employee_public_id: "E2",
        employee_name: "Amina Yusuf",
        reason: "non_national",
      },
      {
        employee_public_id: "E3",
        employee_name: "Sara Haile",
        reason: "ethiopian_origin_unconfirmed",
      },
    ],
  };

  function register() {
    return http.get("*/api/v1/payroll/runs/RUN1/register", () =>
      HttpResponse.json({
        period: "Meskerem 2019",
        status: "completed",
        rows: [],
        totals: {},
        pension_exemptions: exemptions,
      }),
    );
  }

  it("lists every exempt employee with the reason, linked to the profile", async () => {
    server.use(me("finance_admin", ["payroll.viewAll"]), register());
    renderDetail();

    expect(
      await screen.findByText(
        "3 employee(s) exempt from pension: 2 non-national, 1 Ethiopian origin (unconfirmed).",
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Sara Haile" })).toHaveAttribute(
      "href",
      "/employees/E3",
    );
  });

  it("asks the approver to confirm the count, and sends it", async () => {
    const bodies: unknown[] = [];
    server.use(
      me("finance_admin", ["payroll.viewAll", "payroll.approve"]),
      register(),
      http.put("*/api/v1/payroll/runs/RUN1/approve", async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ ...RUN, status: "approved" });
      }),
    );
    const user = userEvent.setup();
    renderDetail();

    await screen.findByText(/exempt from pension/);
    await user.click(screen.getByRole("button", { name: /Approve Payroll/ }));
    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText(/exempts 3 employee\(s\) from pension/),
    ).toBeInTheDocument();
    await user.click(
      within(dialog).getByRole("button", {
        name: "Approve with 3 pension exemption(s)",
      }),
    );

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toEqual({ acknowledged_pension_exemptions: 3 });
  });
});
