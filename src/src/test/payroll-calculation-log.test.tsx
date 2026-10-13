import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalculationLogDialog } from "@/features/payroll/components/calculation-log-dialog";

/**
 * The calculation behind one payslip, read from the stored calculation_log —
 * the worked example in docs/payroll/ACCOUNTANT-SIGN-OFF.md (G7).
 */
function log() {
  return {
    version: "1",
    calculated_at: "2026-11-01T08:00:00Z",
    inputs: {},
    outputs: {},
    steps: [
      {
        step: "proration",
        proration_factor: 1,
        original_basic_cents: 880000,
        prorated_basic_cents: 880000,
      },
      {
        step: "unpaid_leave",
        unpaid_leave_days: 2,
        daily_rate_cents: 40000,
        deduction_cents: 80000,
      },
      { step: "overtime", overtime_minutes: 0, overtime_amount_cents: 0 },
      {
        step: "allowances",
        items: [
          { name: "Transport allowance", amount_cents: 60000, taxable: false },
          { name: "Position allowance", amount_cents: 80000, taxable: true },
        ],
      },
      { step: "gross", gross_cents: 940000 },
      {
        step: "income_tax",
        taxable_amount_cents: 880000,
        income_tax_cents: 135000,
      },
      {
        step: "pension",
        employee_pension_cents: 56000,
        employer_pension_cents: 88000,
      },
      { step: "loan_deductions", loan_deduction_cents: 0 },
      { step: "cost_sharing", cost_sharing_cents: 0 },
      { step: "net_calculation", net_cents: 749000 },
    ],
  };
}

describe("<CalculationLogDialog>", () => {
  it("walks from basic salary to net pay using the stored figures", async () => {
    server.use(
      http.get("*/api/v1/payroll/runs/RUN1", () =>
        HttpResponse.json({
          public_id: "RUN1",
          entries: [{ public_id: "E1", calculation_log: log() }],
        }),
      ),
    );

    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <CalculationLogDialog
          runId="RUN1"
          entryId="E1"
          employeeName="Abebe Kebede"
          onOpenChange={() => {}}
        />
      </QueryClientProvider>,
    );

    expect(await screen.findByText("Unpaid leave")).toBeInTheDocument();
    expect(screen.getByText("2 days × daily rate")).toBeInTheDocument();
    expect(screen.getByText("Transport allowance")).toBeInTheDocument();
    expect(screen.getByText("Not taxable")).toBeInTheDocument();
    expect(screen.getByText("Taxable income")).toBeInTheDocument();
    expect(screen.getByText("Net pay")).toBeInTheDocument();
    // No overtime, loan or cost sharing: lines with nothing in them are left out.
    expect(screen.queryByText("Overtime")).not.toBeInTheDocument();
    expect(screen.queryByText("Loan repayment")).not.toBeInTheDocument();
    expect(screen.getByText(/7,490\.00/)).toBeInTheDocument();
    expect(screen.getByText(/1,350\.00/)).toBeInTheDocument();
  });
});
