import { describe, expect, it } from "vitest";
import { parseRows } from "@/features/onboarding/v2/components/migration-step";

describe("the guided setup's pasted list", () => {
  it("reads the hire date and the department, each optional", () => {
    const { rows, badDates } = parseRows(
      [
        "Abebe Kebede, abebe@acme.et, 0911223344, EMP-1, 2019-07-08, Finance",
        "Hanna Girma",
        "Sara Tesfaye, , , EMP-3, , Sales",
      ].join("\n"),
    );

    expect(badDates).toEqual([]);
    expect(rows).toEqual([
      {
        name: "Abebe Kebede",
        email: "abebe@acme.et",
        phone: "0911223344",
        employee_code: "EMP-1",
        hire_date: "2019-07-08",
        department: "Finance",
      },
      {
        name: "Hanna Girma",
        email: undefined,
        phone: undefined,
        employee_code: undefined,
        hire_date: undefined,
        department: undefined,
      },
      {
        name: "Sara Tesfaye",
        email: undefined,
        phone: undefined,
        employee_code: "EMP-3",
        hire_date: undefined,
        department: "Sales",
      },
    ]);
  });

  it("names the lines whose hire date is not YYYY-MM-DD instead of guessing", () => {
    const { rows, badDates } = parseRows(
      ["A, , , , 2019-07-08", "B, , , , 08/07/2019", "C, , , , 2011 E.C."].join(
        "\n",
      ),
    );

    expect(badDates).toEqual([2, 3]);
    expect(rows.map((r) => r.name)).toEqual(["A"]);
  });
});
