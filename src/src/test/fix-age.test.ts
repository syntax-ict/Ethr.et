import { describe, expect, it } from "vitest";
import { fixIsOld } from "@/lib/attendance/fix-age";

const NOW = 1_000_000_000;
const fix = (ageMs: number) => ({ timestamp: NOW - ageMs });

describe("fixIsOld", () => {
  it("treats a missing fix as old", () => {
    expect(fixIsOld(null, NOW)).toBe(true);
    expect(fixIsOld(undefined, NOW)).toBe(true);
  });

  it("treats a fix with no timestamp as old", () => {
    expect(fixIsOld({}, NOW)).toBe(true);
  });

  it("keeps a fix up to 60 seconds old", () => {
    expect(fixIsOld(fix(0), NOW)).toBe(false);
    expect(fixIsOld(fix(59_000), NOW)).toBe(false);
    expect(fixIsOld(fix(60_000), NOW)).toBe(false);
  });

  it("calls a fix older than 60 seconds old", () => {
    expect(fixIsOld(fix(60_001), NOW)).toBe(true);
    expect(fixIsOld(fix(300_000), NOW)).toBe(true);
  });

  it("honours another limit", () => {
    expect(fixIsOld(fix(10_000), NOW, 5_000)).toBe(true);
    expect(fixIsOld(fix(10_000), NOW, 20_000)).toBe(false);
  });

  it("does not call a fix from the future old", () => {
    expect(fixIsOld({ timestamp: NOW + 5_000 }, NOW)).toBe(false);
  });
});
