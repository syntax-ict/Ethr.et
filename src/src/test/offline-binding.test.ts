import { describe, expect, it } from "vitest";
import { offlinePunchWouldBeRefused } from "@/features/attendance/offline-binding";
import type { AttendanceDeviceState } from "@/features/attendance/api";

const state = (
  over: Partial<AttendanceDeviceState> = {},
): AttendanceDeviceState => ({
  binding: "enforce",
  rp_id: "ethr.et",
  host_allowed: true,
  canonical_url: null,
  device: null,
  pending: null,
  exempt_until: null,
  ...over,
});
// 21:30 UTC on 2026-11-20 is 00:30 on 2026-11-21 in Addis Ababa.
const now = new Date("2026-11-20T21:30:00Z");

describe("offlinePunchWouldBeRefused", () => {
  it("refuses under an enforced rule with no grant naming the phone", () => {
    expect(offlinePunchWouldBeRefused(state(), false, now)).toBe(true);
  });

  it("allows it with a grant that names the phone", () => {
    expect(offlinePunchWouldBeRefused(state(), true, now)).toBe(false);
  });

  it("allows it when the rule only flags or is off", () => {
    for (const binding of ["off", "flag"] as const) {
      expect(offlinePunchWouldBeRefused(state({ binding }), false, now)).toBe(
        false,
      );
    }
  });

  it("allows it when the state is not known", () => {
    expect(offlinePunchWouldBeRefused(undefined, false, now)).toBe(false);
  });

  it("reads the last exempt day on the server's clock, UTC, not the local one", () => {
    // 00:30 in Addis Ababa on the 21st is still the 20th in UTC, so an
    // exemption until the 20th still covers it, as the server reads it.
    expect(
      offlinePunchWouldBeRefused(
        state({ exempt_until: "2026-11-20" }),
        false,
        now,
      ),
    ).toBe(false);
    expect(
      offlinePunchWouldBeRefused(
        state({ exempt_until: "2026-11-19" }),
        false,
        now,
      ),
    ).toBe(true);
  });
});
