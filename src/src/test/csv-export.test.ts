import { describe, it, expect } from "vitest";
import {
  buildCsv,
  csvAmount,
  csvCell,
  csvFromRows,
} from "@/lib/utils/csv-export";

/**
 * Six hand-rolled CSV builders became one. Between them they had split amounts
 * like "12,345.67" across two columns — the payroll register and the bank
 * transfer file joined unquoted cells — and none neutralised a formula.
 */
describe("csvCell", () => {
  it("keeps a formula from evaluating when the file is opened", () => {
    expect(csvCell('=HYPERLINK("http://x.test","Open")')).toBe(
      `"'=HYPERLINK(""http://x.test"",""Open"")"`,
    );
    expect(csvCell("+251911000000 call")).toBe("'+251911000000 call");
    expect(csvCell("@SUM(A1)")).toBe("'@SUM(A1)");
    expect(csvCell("-1+cmd")).toBe("'-1+cmd");
  });

  it("leaves plain numbers alone, negative ones included", () => {
    expect(csvCell("-250.00")).toBe("-250.00");
    expect(csvCell("+12")).toBe("+12");
    expect(csvCell(42)).toBe("42");
  });

  it("quotes delimiters, quotes and line breaks", () => {
    expect(csvCell("Addis Ababa, HQ")).toBe('"Addis Ababa, HQ"');
    expect(csvCell('6" monitor')).toBe('"6"" monitor"');
    expect(csvCell("two\nlines")).toBe('"two\nlines"');
    expect(csvCell(null)).toBe("");
  });
});

describe("csvAmount", () => {
  it("never groups thousands, which a CSV reader takes as a delimiter", () => {
    expect(csvAmount(1234567)).toBe("12345.67");
    expect(csvAmount(-5000)).toBe("-50.00");
  });
});

describe("csvFromRows / buildCsv", () => {
  it("keeps every amount in its own column", () => {
    const csv = csvFromRows(
      ["Employee", "Net Pay"],
      [["Abebe Kebede", csvAmount(1234567)]],
    );
    const [, row] = csv.split("\n");
    expect(row.split(",")).toEqual(["Abebe Kebede", "12345.67"]);
  });

  it("builds from records with the first row's keys as headers", () => {
    expect(buildCsv([{ name: "=1+1", total: 3 }])).toBe("name,total\n'=1+1,3");
    expect(buildCsv([])).toBe("");
  });
});

describe("buildCsv (the contract it had before the rewrite)", () => {
  it("builds a header row from the first row's keys plus one data row per entry", () => {
    const csv = buildCsv([
      { name: "Finance", count: 12 },
      { name: "Operations", count: 8 },
    ]);

    expect(csv).toBe("name,count\nFinance,12\nOperations,8");
  });

  it("quotes and escapes values containing commas, quotes, or newlines", () => {
    const csv = buildCsv([{ name: 'Sales, "East"', note: "line1\nline2" }]);

    expect(csv).toBe('name,note\n"Sales, ""East"""' + "," + '"line1\nline2"');
  });

  it("fills a missing key on a later row with an empty cell", () => {
    const csv = buildCsv([
      { name: "A", extra: "x" },
      { name: "B" } as unknown as Record<string, string>,
    ]);

    expect(csv).toBe("name,extra\nA,x\nB,");
  });
});
