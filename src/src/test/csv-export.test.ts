import { describe, it, expect } from "vitest";
import { buildCsv } from "@/lib/utils/csv-export";

describe("buildCsv", () => {
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

  it("returns an empty string for no rows", () => {
    expect(buildCsv([])).toBe("");
  });

  it("fills a missing key on a later row with an empty cell", () => {
    const csv = buildCsv([
      { name: "A", extra: "x" },
      { name: "B" } as unknown as Record<string, string>,
    ]);

    expect(csv).toBe("name,extra\nA,x\nB,");
  });
});
