import { describe, expect, it } from "vitest";
import { shiftRail } from "@/lib/attendance/shift-rail";

const START = "2026-10-17T05:00:00Z";
const END = "2026-10-17T13:00:00Z"; // 8 h: the margin is 8%, 38.4 minutes
const at = (iso: string) => new Date(iso);

function rail(over: Partial<Parameters<typeof shiftRail>[0]> = {}) {
  return shiftRail({
    startsAt: START,
    endsAt: END,
    checkIn: null,
    checkOut: null,
    now: at("2026-10-17T09:00:00Z"),
    ...over,
  });
}

describe("shiftRail", () => {
  it("puts now inside the shift, midway through it", () => {
    const r = rail()!;
    expect(r.nowState).toBe("during");
    expect(r.start).toBeGreaterThan(0);
    expect(r.end).toBeLessThan(1);
    expect(r.now).toBeCloseTo(0.5, 5);
    expect(r.checkIn).toBeNull();
    expect(r.checkOut).toBeNull();
  });

  it("says before and after against the shift, not the window", () => {
    const before = rail({ now: at("2026-10-17T04:45:00Z") })!;
    expect(before.nowState).toBe("before");
    expect(before.now).toBeLessThan(before.start);
    const after = rail({ now: at("2026-10-17T13:10:00Z") })!;
    expect(after.nowState).toBe("after");
    expect(after.now).toBeGreaterThan(after.end);
  });

  it("gives a check-in ten minutes early a place before the start mark", () => {
    const r = rail({ checkIn: "2026-10-17T04:50:00Z" })!;
    expect(r.checkIn).not.toBeNull();
    expect(r.checkIn!).toBeLessThan(r.start);
    expect(r.checkIn!).toBeGreaterThan(0);
    expect(r.checkIn!).toBeLessThanOrEqual(1);
  });

  it("clamps a check-in two hours early to the left end", () => {
    expect(rail({ checkIn: "2026-10-17T03:00:00Z" })!.checkIn).toBe(0);
  });

  it("clamps a late check-out and a late now to the right end", () => {
    const r = rail({
      checkOut: "2026-10-17T18:00:00Z",
      now: at("2026-10-17T20:00:00Z"),
    })!;
    expect(r.checkOut).toBe(1);
    expect(r.now).toBe(1);
  });

  it("uses at least 30 minutes of margin on a short shift", () => {
    const r = shiftRail({
      startsAt: "2026-10-17T05:00:00Z",
      endsAt: "2026-10-17T06:00:00Z",
      checkIn: null,
      checkOut: null,
      now: at("2026-10-17T05:30:00Z"),
    })!;
    // A one hour shift with 30 minutes each side: the window is two hours.
    expect(r.start).toBeCloseTo(0.25, 5);
    expect(r.end).toBeCloseTo(0.75, 5);
  });

  it("handles an overnight shift as plain instants", () => {
    const r = shiftRail({
      startsAt: "2026-10-17T19:00:00Z",
      endsAt: "2026-10-18T03:00:00Z",
      checkIn: "2026-10-17T19:05:00Z",
      checkOut: null,
      now: at("2026-10-17T23:00:00Z"),
    })!;
    expect(r.nowState).toBe("during");
    expect(r.now).toBeCloseTo(0.5, 5);
    expect(r.checkIn!).toBeGreaterThan(r.start);
  });

  it("returns null for bad dates or an end that is not after the start", () => {
    expect(rail({ startsAt: "nope" })).toBeNull();
    expect(rail({ endsAt: "" })).toBeNull();
    expect(rail({ startsAt: END, endsAt: START })).toBeNull();
    expect(rail({ startsAt: START, endsAt: START })).toBeNull();
    expect(rail({ now: new Date("x") })).toBeNull();
  });
});
