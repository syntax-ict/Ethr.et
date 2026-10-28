import { describe, expect, it } from "vitest";
import { nextPunch } from "@/lib/attendance/next-punch";
import type { MyShift } from "@/features/attendance/api";

const day = (status: "not_checked_in" | "checked_in" | "checked_out") =>
  ({ attendance: { status } }) as unknown as MyShift;

describe("nextPunch", () => {
  it("offers the punch the day is waiting for", () => {
    expect(nextPunch(day("not_checked_in"))).toBe("check_in");
    expect(nextPunch(day("checked_in"))).toBe("check_out");
    expect(nextPunch(day("checked_out"))).toBe("done");
  });

  it("has no answer without the server's record", () => {
    expect(nextPunch(undefined)).toBeNull();
  });
});
