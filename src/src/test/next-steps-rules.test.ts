import { describe, expect, it } from "vitest";
import { nextSteps } from "@/features/dashboard/lib/next-steps";

const run = (status: string) => ({
  public_id: "01HZRUN",
  period_label: "Tikimt 2019",
  status,
});

describe("nextSteps", () => {
  it("answers with nothing when nothing is waiting", () => {
    expect(nextSteps({})).toEqual([]);
    expect(
      nextSteps({
        approvals: { leave: 0, correction: 0, profile_update: 0, total: 0 },
        teamAbsent: 0,
        integrityOpen: 0,
        unmatchedBadges: 0,
        payrollRun: run("approved"),
      }),
    ).toEqual([]);
  });

  it("never invents a count: a source that did not answer makes no row", () => {
    expect(
      nextSteps({ approvals: undefined, integrityOpen: undefined }),
    ).toEqual([]);
  });

  it("puts what blocks payroll before what waits on one person", () => {
    const steps = nextSteps({
      teamAbsent: 2,
      approvals: { leave: 3, correction: 1, profile_update: 0, total: 4 },
      integrityOpen: 5,
      payrollRun: run("completed"),
      unmatchedBadges: 1,
    });
    expect(steps.map((s) => s.id)).toEqual([
      "integrity",
      "payroll_approve",
      "approvals",
    ]);
    expect(steps[0]).toMatchObject({
      href: "/attendance/integrity",
      count: 5,
      tone: "warning",
    });
    expect(steps[1]).toMatchObject({
      href: "/payroll/01HZRUN",
      period: "Tikimt 2019",
    });
  });

  it("shows at most three, and the rest when the first are put away", () => {
    const input = {
      teamAbsent: 2,
      approvals: { leave: 3, correction: 1, profile_update: 0, total: 4 },
      integrityOpen: 5,
      unmatchedBadges: 1,
    };
    expect(nextSteps(input)).toHaveLength(3);
    const after = nextSteps(input, { integrity: "5", approvals: "4" });
    expect(after.map((s) => s.id)).toEqual(["unmatched", "team_absent"]);
  });

  it("brings a put-away row back only when its number changes", () => {
    const dismissed = { approvals: "4" };
    const same = { leave: 3, correction: 1, profile_update: 0, total: 4 };
    expect(nextSteps({ approvals: same }, dismissed)).toEqual([]);
    const more = { ...same, leave: 5, total: 6 };
    expect(nextSteps({ approvals: more }, dismissed)).toHaveLength(1);
  });

  it("names a failed run and a calculated run, and leaves the others alone", () => {
    expect(nextSteps({ payrollRun: run("failed") })[0]?.id).toBe(
      "payroll_failed",
    );
    expect(nextSteps({ payrollRun: run("completed") })[0]?.id).toBe(
      "payroll_approve",
    );
    for (const status of ["processing", "approved", "voided", "paid"]) {
      expect(nextSteps({ payrollRun: run(status) })).toEqual([]);
    }
    expect(nextSteps({ payrollRun: null })).toEqual([]);
  });

  it("puts contracts that need a decision after approvals and before held punches", () => {
    const steps = nextSteps({
      approvals: { leave: 1, correction: 0, profile_update: 0, total: 1 },
      contractsEnding: 2,
      unmatchedBadges: 4,
    });
    expect(steps.map((s) => s.id)).toEqual([
      "approvals",
      "contracts_ending",
      "unmatched",
    ]);
    expect(steps[1]).toMatchObject({
      href: "/employees/contracts",
      count: 2,
      tone: "warning",
      signature: "2",
    });
    expect(nextSteps({ contractsEnding: 0 })).toEqual([]);
  });
});
