import { describe, it, expect } from "vitest";
import { describeChanges } from "@/features/employees/components/employee-history-tab";

// A minimal translator that just returns the provided fallback.
const t = ((_key: string, fallback?: string) => fallback ?? _key) as never;

describe("describeChanges", () => {
  it("formats a salary change as ETB from minor units", () => {
    const rows = describeChanges(
      { salary: { from: 1_500_000, to: 1_800_000 } },
      t,
    );
    expect(rows).toEqual([
      { label: "Salary", from: "15,000.00 ETB", to: "18,000.00 ETB" },
    ]);
  });

  it("passes through label changes for relational fields", () => {
    const rows = describeChanges({ grade: { from: "G6", to: "G7" } }, t);
    expect(rows).toEqual([{ label: "Grade", from: "G6", to: "G7" }]);
  });

  it("renders a null 'from' as an em dash", () => {
    const rows = describeChanges({ salary_step: { from: null, to: 2 } }, t);
    expect(rows).toEqual([{ label: "Step", from: "—", to: "2" }]);
  });

  it("returns one row per changed dimension", () => {
    const rows = describeChanges(
      {
        grade: { from: "G6", to: "G7" },
        department: { from: "Finance", to: "Planning" },
      },
      t,
    );
    expect(rows.map((r) => r.label).sort()).toEqual(["Department", "Grade"]);
  });
});
