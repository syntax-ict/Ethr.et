import { describe, expect, it } from "vitest";
import { shiftMoment } from "@/lib/attendance/shift-moment";

const base = {
  startsAt: "2026-10-17T05:00:00Z",
  endsAt: "2026-10-17T13:00:00Z",
  status: "not_checked_in" as const,
  checkIn: null,
  checkOut: null,
};
const at = (iso: string) => new Date(iso);

describe("shiftMoment", () => {
  it("counts down to the start, flooring to whole minutes", () => {
    expect(shiftMoment({ ...base, now: at("2026-10-17T04:20:30Z") })).toEqual({
      kind: "starts_in",
      minutes: 39,
    });
  });

  it("counts up from the start when nobody has checked in", () => {
    expect(shiftMoment({ ...base, now: at("2026-10-17T05:45:59Z") })).toEqual({
      kind: "started_ago",
      minutes: 45,
    });
  });

  it("says nothing once the shift is over and nobody came", () => {
    expect(
      shiftMoment({ ...base, now: at("2026-10-17T13:00:01Z") }),
    ).toBeNull();
  });

  it("counts the time worked and what is left", () => {
    expect(
      shiftMoment({
        ...base,
        status: "checked_in",
        checkIn: "2026-10-17T04:55:00Z",
        now: at("2026-10-17T09:07:00Z"),
      }),
    ).toEqual({ kind: "worked", minutes: 252, endsInMinutes: 233 });
  });

  it("drops the time left once past the end", () => {
    expect(
      shiftMoment({
        ...base,
        status: "checked_in",
        checkIn: "2026-10-17T05:00:00Z",
        now: at("2026-10-17T13:30:00Z"),
      }),
    ).toEqual({ kind: "worked", minutes: 510, endsInMinutes: null });
  });

  it("totals a finished day from check-in to check-out", () => {
    expect(
      shiftMoment({
        ...base,
        status: "checked_out",
        checkIn: "2026-10-17T05:00:00Z",
        checkOut: "2026-10-17T12:59:59Z",
        now: at("2026-10-17T15:00:00Z"),
      }),
    ).toEqual({ kind: "worked_total", minutes: 479 });
  });

  it("never goes negative", () => {
    expect(
      shiftMoment({
        ...base,
        status: "checked_in",
        checkIn: "2026-10-17T09:00:00Z",
        now: at("2026-10-17T08:59:00Z"),
      }),
    ).toMatchObject({ kind: "worked", minutes: 0 });
  });

  it("gives null for bad dates or a missing time", () => {
    expect(
      shiftMoment({ ...base, startsAt: "x", now: at(base.startsAt) }),
    ).toBeNull();
    expect(
      shiftMoment({ ...base, status: "checked_in", now: at(base.startsAt) }),
    ).toBeNull();
    expect(
      shiftMoment({
        ...base,
        status: "checked_out",
        checkIn: "2026-10-17T05:00:00Z",
        now: at(base.startsAt),
      }),
    ).toBeNull();
    expect(shiftMoment({ ...base, now: new Date("x") })).toBeNull();
  });
});
