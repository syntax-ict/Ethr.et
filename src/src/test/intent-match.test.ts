import { describe, expect, it } from "vitest";
import { intentScore, splitPhrases } from "@/lib/navigation/intent-match";

const approvals = [
  "Approvals",
  "pending approvals",
  "approve leave",
  "approve a leave request",
  "waiting for my decision",
];
const leave = ["Leave", "request leave", "time off", "leave balance"];
const payroll = ["Payroll Runs", "prepare payroll", "process salary"];

describe("intentScore", () => {
  it("scores a phrase typed whole highest", () => {
    expect(intentScore("Approve a leave request", approvals)).toBe(1);
    expect(intentScore("approve a leave request?", approvals)).toBe(1);
  });

  it("accepts a part of a phrase", () => {
    expect(intentScore("pending appr", approvals)).toBe(0.9);
    expect(intentScore("appr", approvals)).toBe(0.9);
  });

  it("matches on the words that carry meaning, not the ones around them", () => {
    // "I want to", "the" and "my" say nothing about which row is meant.
    expect(intentScore("i want to prepare the payroll", payroll)).toBe(0.95);
    expect(intentScore("show me my leave balance", leave)).toBe(0.95);
    // The filler is dropped from the phrase side too.
    expect(intentScore("approve leave request", approvals)).toBe(0.95);
    expect(intentScore("pending approval", approvals)).toBe(0.9);
    expect(intentScore("i want the pending approval", approvals)).toBe(0.85);
  });

  it("ranks a row that answers every word above one that answers some", () => {
    const full = intentScore("approve leave request", approvals);
    const partial = intentScore("approve leave request", leave);
    expect(full).toBe(0.95);
    expect(partial).toBeGreaterThan(0);
    expect(partial).toBeLessThan(full);
  });

  it("does not light up a row because its letters happen to appear in order", () => {
    // cmdk's fuzzy default accepted this: a, b, s, e, n, t in "Announcements".
    expect(intentScore("absent", ["Announcements"])).toBe(0);
    expect(intentScore("who is absent today", payroll)).toBe(0);
  });

  it("needs at least half the words before a row is a plausible answer", () => {
    expect(intentScore("who is absent today", ["Attendance", "absent"])).toBe(
      0,
    );
    expect(
      intentScore("absent today", ["Attendance", "absent"]),
    ).toBeGreaterThan(0);
  });

  it("matches a word typed in the plural against its stem", () => {
    expect(intentScore("payslips", ["My Payslip"])).toBeGreaterThan(0);
  });

  it("matches Amharic phrases whole and by word", () => {
    const am = ["ዛሬ ማን ቀረ", "ዛሬ የቀሩ", "ያልመጡ"];
    expect(intentScore("ዛሬ ማን ቀረ", am)).toBe(1);
    expect(intentScore("ዛሬ ማን ቀረ?", am)).toBe(1);
    expect(intentScore("የቀሩ", am)).toBe(0.9);
    expect(intentScore("ፈቃድ", am)).toBe(0);
  });

  it("treats an empty query as matching everything", () => {
    expect(intentScore("", leave)).toBe(1);
    expect(intentScore("   ", leave)).toBe(1);
  });

  it("gives nothing to a query made only of filler", () => {
    expect(intentScore("i want to", leave)).toBe(0);
  });
});

describe("splitPhrases", () => {
  it("splits on the bar and drops blanks", () => {
    expect(splitPhrases(" a | b ||c ")).toEqual(["a", "b", "c"]);
    expect(splitPhrases(undefined)).toEqual([]);
    expect(splitPhrases("")).toEqual([]);
  });
});
