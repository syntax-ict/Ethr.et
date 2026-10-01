import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AccountingSettingsPage from "@/app/(dashboard)/settings/accounting/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** `AccountingController::chartOfAccounts`, defaults only. */
const CHART = {
  accounts: [
    ["salary_expense", "5100", "Salary Expense"],
    ["pension_expense", "5200", "Pension Expense (Employer)"],
    ["tax_payable", "2100", "Income Tax Payable"],
    ["pension_payable_employee", "2200", "Pension Payable (Employee)"],
    ["pension_payable_employer", "2201", "Pension Payable (Employer)"],
    ["net_salary_payable", "2300", "Net Salary Payable"],
  ].map(([key, account_code, account_name]) => ({
    key,
    account_code,
    account_name,
    is_custom: false,
  })),
};

/** Shaped like PayrollRunResource. */
function run(n: number) {
  return {
    public_id: `RUN${n}`,
    period_label: `Period ${n}`,
    period_start: "2026-09-01",
    period_end: "2026-09-30",
    status: "approved",
    employee_count: 40,
    gross_total_cents: 0,
    net_total_cents: 0,
    tax_total_cents: 0,
    processed_at: null,
    approved_at: null,
    voided_at: null,
    void_reason: null,
    created_at: "2026-09-30T06:00:00Z",
  };
}

/** `AccountingExportService::journalEntries`, trimmed to two lines. */
const JOURNAL = {
  period: "Meskerem 2019",
  date: "2026-10-10",
  reference: "PAYROLL-RUN1",
  entries: [
    {
      account_code: "5100",
      account_name: "Salary Expense",
      debit_cents: 1234567,
      credit_cents: 0,
    },
    {
      account_code: "2300",
      account_name: "Net Salary Payable",
      debit_cents: 0,
      credit_cents: 1234567,
    },
  ],
  total_debits_cents: 1234567,
  total_credits_cents: 1234567,
  is_balanced: true,
};

function serveRuns(total: number) {
  server.use(
    http.get("*/api/v1/payroll/runs", ({ request }) => {
      const url = new URL(request.url);
      const page = Number(url.searchParams.get("page") ?? 1);
      const perPage = Math.min(
        Number(url.searchParams.get("per_page") ?? 25),
        100,
      );
      const from = (page - 1) * perPage;
      const rows = Array.from(
        { length: Math.max(0, Math.min(perPage, total - from)) },
        (_, i) => run(from + i + 1),
      );
      return HttpResponse.json({
        data: rows,
        meta: {
          current_page: page,
          last_page: Math.max(1, Math.ceil(total / perPage)),
          per_page: perPage,
          total,
        },
        links: {},
      });
    }),
  );
}

function me(permissions: string[], role = "finance_admin") {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

/** The app's own query defaults (app/providers.tsx): fresh for a minute. */
function newClient() {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, staleTime: 60 * 1000 },
      mutations: { retry: false },
    },
  });
}

function renderPage(queryClient = newClient()) {
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<AccountingSettingsPage />, { wrapper: Wrapper });
}

let saved: Blob[] = [];
let opened: unknown[] = [];

beforeEach(() => {
  saved = [];
  opened = [];
  vi.spyOn(URL, "createObjectURL").mockImplementation((blob) => {
    saved.push(blob as Blob);
    return "blob:mock";
  });
  vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(() => {});
  vi.spyOn(window, "open").mockImplementation((...args) => {
    opened.push(args);
    return null;
  });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe("<AccountingSettingsPage>", () => {
  it("exports the journal with every amount in one column", async () => {
    // The button opened GET /accounting/export/{run}, whose
    // `number_format($cents / 100, 2)` writes "12,345.67" into an unquoted
    // CSV — two columns for every amount over 1,000 ETB.
    serveRuns(1);
    server.use(
      me(["payroll.viewAll"]),
      http.get("*/api/v1/accounting/chart-of-accounts", () =>
        HttpResponse.json(CHART),
      ),
      http.get("*/api/v1/accounting/journal/RUN1", () =>
        HttpResponse.json(JOURNAL),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: /Period 1/ }));
    await screen.findByText(/PAYROLL-RUN1/);
    await user.click(screen.getByRole("button", { name: "Export CSV" }));

    await waitFor(() => expect(saved).toHaveLength(1));
    expect(opened).toHaveLength(0);
    const lines = (await saved[0].text()).split("\n");
    expect(lines[0]).toBe(
      "Reference,Period,Date,Account Code,Account Name,Debit (ETB),Credit (ETB)",
    );
    expect(lines[1]).toBe(
      "PAYROLL-RUN1,Meskerem 2019,2026-10-10,5100,Salary Expense,12345.67,",
    );
    expect(lines.at(-1)).toBe(",TOTALS,,,,12345.67,12345.67");
  });

  it("offers runs past the first page of 25", async () => {
    serveRuns(30);
    server.use(
      me(["payroll.viewAll"]),
      http.get("*/api/v1/accounting/chart-of-accounts", () =>
        HttpResponse.json(CHART),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("combobox"));
    expect(
      await screen.findByRole("option", { name: /Period 30/ }),
    ).toBeInTheDocument();
  });

  it("still shows the chart of accounts when the cached copy is fresh", async () => {
    // The rows were copied into state from inside the query function, which
    // does not run while the cache is fresh — so coming back to the page
    // within a minute showed a chart with no rows.
    serveRuns(0);
    server.use(
      me(["payroll.viewAll"]),
      http.get("*/api/v1/accounting/chart-of-accounts", () =>
        HttpResponse.json(CHART),
      ),
    );
    const client = newClient();
    const first = renderPage(client);
    await screen.findByDisplayValue("5100");
    first.unmount();

    renderPage(client);
    expect(await screen.findByDisplayValue("5100")).toBeInTheDocument();
  });

  it("saves only the accounts that were edited", async () => {
    let sent: unknown = null;
    serveRuns(0);
    server.use(
      me(["payroll.viewAll"]),
      http.get("*/api/v1/accounting/chart-of-accounts", () =>
        HttpResponse.json(CHART),
      ),
      http.put("*/api/v1/accounting/chart-of-accounts", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ message: "Chart of accounts updated." });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    const code = await screen.findByRole("textbox", {
      name: /Income Tax Payable.*Account code/i,
    });
    await user.clear(code);
    await user.type(code, "2105");
    await user.click(screen.getByRole("button", { name: /Save/ }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toEqual({
      accounts: [
        {
          key: "tax_payable",
          account_code: "2105",
          account_name: "Income Tax Payable",
        },
      ],
    });
  });
});
