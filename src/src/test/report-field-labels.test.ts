import { describe, it, expect } from "vitest";
import {
  formatReportCell,
  reportFieldLabel,
} from "@/features/reports/field-labels";

/** The fallback is returned as given, as the real `t` does with no catalogue entry. */
const t = (_key: string, fallback?: string) => fallback ?? _key;

describe("report field labels (audit N30)", () => {
  it("names fields in words, not API keys", () => {
    expect(reportFieldLabel(t as never, "salary_cents")).toBe("Salary");
    expect(reportFieldLabel(t as never, "employee_code")).toBe("Employee code");
    expect(reportFieldLabel(t as never, "net_cents")).toBe("Net pay");
  });

  it("keeps an unknown field readable", () => {
    expect(reportFieldLabel(t as never, "overtime_pay_cents")).toBe(
      "Overtime pay",
    );
  });
});

describe("report cells", () => {
  it("shows money in ETB, not raw cents", () => {
    expect(formatReportCell("salary_cents", 500000)).toBe("5,000.00 ETB");
    expect(formatReportCell("net_cents", "1234567")).toBe("12,345.67 ETB");
  });

  it("leaves other values as they are, and marks a missing one", () => {
    expect(formatReportCell("worked_minutes", 480)).toBe("480");
    expect(formatReportCell("check_out", null)).toBe("—");
  });
});
