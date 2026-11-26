import { describe, expect, it } from "vitest";
import { formatDistance } from "@/lib/attendance/format-distance";

describe("formatDistance", () => {
  it("gives whole metres under a kilometre", () => {
    const out = formatDistance(149.6, "en");
    expect(out).toContain("150");
    expect(out.length).toBeGreaterThan(3);
  });

  it("gives kilometres to one decimal from 1000 m", () => {
    const out = formatDistance(1234, "en");
    expect(out).toContain("1.2");
  });

  it("is not empty in Amharic and keeps the number", () => {
    const out = formatDistance(80, "am");
    expect(out.length).toBeGreaterThan(0);
    expect(out).toMatch(/80|፹/);
  });

  it("is not empty in Afaan Oromoo", () => {
    expect(formatDistance(2500, "om")).toContain("2.5");
  });
});
