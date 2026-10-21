import { describe, expect, it } from "vitest";
import { buildCsv, centsAsAmount } from "@/lib/utils/csv-export";

// A report's CSV had API field names for headers and raw cents for money
// (FRONTEND_REDESIGN_PLAN.md, backlog 22).
describe("buildCsv labels and values", () => {
  const rows = [{ employee_name: "Hana", basic_salary_cents: 1234567 }];

  it("keeps the keys as headers when no label is given", () => {
    expect(buildCsv(rows)).toBe(
      "employee_name,basic_salary_cents\nHana,1234567",
    );
  });

  it("names columns for the reader and writes cents as an amount", () => {
    const labels: Record<string, string> = {
      employee_name: "ሰራተኛ",
      basic_salary_cents: "መሰረታዊ ደመወዝ",
    };
    expect(
      buildCsv(rows, { label: (k) => labels[k], value: centsAsAmount }),
    ).toBe("ሰራተኛ,መሰረታዊ ደመወዝ\nHana,12345.67");
  });

  it("leaves a non-number in a cents column alone", () => {
    expect(centsAsAmount("x_cents", null)).toBeNull();
    expect(centsAsAmount("name", 5)).toBe(5);
  });
});
