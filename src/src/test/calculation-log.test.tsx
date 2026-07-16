import { describe, it, expect } from "vitest";
import type { CalculationLog, PayrollEntry } from "@/features/payroll/api";

describe("PayrollEntry calculation_log type", () => {
  it("has correct structure for a complete calculation log", () => {
    const log: CalculationLog = {
      version: "1.0",
      calculated_at: "2026-07-16T10:00:00+03:00",
      inputs: {
        employee_id: "01HXYZ123456",
        basic_salary_cents: 1000000,
        period_start: "2026-07-01",
        period_end: "2026-07-30",
      },
      steps: [
        {
          step: "proration",
          proration_factor: 1.0,
          prorated_basic_cents: 1000000,
        },
        {
          step: "overtime",
          overtime_minutes: 120,
          overtime_amount_cents: 15000,
        },
        { step: "gross", gross_cents: 1015000 },
        {
          step: "income_tax",
          taxable_amount_cents: 1015000,
          income_tax_cents: 253750,
        },
        {
          step: "pension",
          employee_pension_cents: 70000,
          employer_pension_cents: 110000,
        },
        { step: "loan_deductions", loan_deduction_cents: 50000 },
        {
          step: "net_calculation",
          gross_cents: 1015000,
          total_deductions_cents: 373750,
          net_cents: 641250,
        },
      ],
      outputs: {
        gross_cents: 1015000,
        income_tax_cents: 253750,
        employee_pension_cents: 70000,
        employer_pension_cents: 110000,
        loan_deduction_cents: 50000,
        net_cents: 641250,
      },
    };

    expect(log.version).toBe("1.0");
    expect(log.steps).toHaveLength(7);
    expect(log.outputs.net_cents).toBe(641250);
  });

  it("PayrollEntry can have optional calculation_log", () => {
    const entry: PayrollEntry = {
      public_id: "01HXYZ",
      employee_public_id: "01HABC",
      basic_salary_cents: 1000000,
      gross_cents: 1015000,
      income_tax_cents: 253750,
      employee_pension_cents: 70000,
      employer_pension_cents: 110000,
      other_deductions_cents: 50000,
      net_cents: 641250,
    };

    expect(entry.calculation_log).toBeUndefined();
  });

  it("formats ETB amounts correctly from cents", () => {
    const cents = 1015000;
    const etb = (cents / 100).toLocaleString("en-US", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
    expect(etb).toBe("10,150.00");
  });
});
