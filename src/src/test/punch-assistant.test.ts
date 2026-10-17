import { describe, expect, it } from "vitest";
import type { MyShift } from "@/features/attendance/api";
import {
  autoCheckInDecision,
  distanceMeters,
  geofenceState,
  reminderPlan,
  reminderStillDue,
} from "@/lib/attendance/punch-assistant";

/**
 * The punching assistant's decisions. Shift 08:30–17:30 Addis Ababa on Monday
 * 12 October 2026: 05:30–14:30 UTC.
 */
const BRANCH = { latitude: 9.0108, longitude: 38.7613, radius_meters: 150 };

function shift(
  overrides: {
    status?: MyShift["attendance"]["status"];
    working_day?: boolean;
    reminders?: boolean;
    auto?: boolean;
    photo?: boolean;
    geofence?: typeof BRANCH | null;
  } = {},
): MyShift {
  return {
    date: "2026-10-12",
    timezone: "Africa/Addis_Ababa",
    working_day: overrides.working_day ?? true,
    holiday: null,
    on_leave: false,
    shift: {
      public_id: "01HQZX3NDJKMNP4RSTVWXY5Z6A",
      name: "Regular",
      start_time: "08:30",
      end_time: "17:30",
      crosses_midnight: false,
      grace_minutes: 10,
      early_departure_minutes: 15,
      starts_at: "2026-10-12T05:30:00+00:00",
      ends_at: "2026-10-12T14:30:00+00:00",
    },
    attendance: {
      status: overrides.status ?? "not_checked_in",
      record_public_id: null,
      check_in: null,
      check_out: null,
      status_label: null,
      auto_checked_out: false,
    },
    policy: {
      mobile_enabled: true,
      web_enabled: true,
      geofence_required: false,
      photo_required: overrides.photo ?? false,
      photo_consent: false,
      accuracy_threshold_meters: 100,
      offline_sync_enabled: true,
      geofence: overrides.geofence === undefined ? BRANCH : overrides.geofence,
    },
    assistant: {
      reminders: overrides.reminders ?? true,
      reminders_allowed: true,
      reminder_lead_minutes: 10,
      auto_check_in: overrides.auto ?? true,
      auto_check_in_allowed: true,
      auto_checkout: false,
      auto_checkout_after_minutes: 120,
    },
  };
}

const at = (utc: string) => new Date(utc);
const atBranch = {
  latitude: BRANCH.latitude,
  longitude: BRANCH.longitude,
  accuracy: 20,
};

describe("reminderPlan", () => {
  it("reminds before the shift, at the end of grace, and at the end", () => {
    const plan = reminderPlan(shift(), at("2026-10-12T04:00:00Z"));

    expect(plan.map((r) => [r.kind, r.at.toISOString()])).toEqual([
      ["before_start", "2026-10-12T05:20:00.000Z"],
      ["not_checked_in", "2026-10-12T05:40:00.000Z"],
      ["shift_end", "2026-10-12T14:30:00.000Z"],
    ]);
  });

  it("only reminds to check out once checked in", () => {
    const plan = reminderPlan(
      shift({ status: "checked_in" }),
      at("2026-10-12T06:00:00Z"),
    );

    expect(plan.map((r) => r.kind)).toEqual(["shift_end"]);
  });

  it("says nothing on a day off, or when reminders are off", () => {
    expect(
      reminderPlan(shift({ working_day: false }), at("2026-10-12T04:00:00Z")),
    ).toEqual([]);
    expect(
      reminderPlan(shift({ reminders: false }), at("2026-10-12T04:00:00Z")),
    ).toEqual([]);
  });

  it("drops a reminder whose moment has passed", () => {
    const plan = reminderPlan(shift(), at("2026-10-12T05:25:00Z"));
    expect(plan[0].kind).toBe("not_checked_in");
  });

  it("is silenced by a punch made elsewhere", () => {
    expect(
      reminderStillDue("before_start", shift({ status: "checked_in" })),
    ).toBe(false);
    expect(
      reminderStillDue("shift_end", shift({ status: "checked_out" })),
    ).toBe(false);
    expect(reminderStillDue("shift_end", shift({ status: "checked_in" }))).toBe(
      true,
    );
  });
});

describe("autoCheckInDecision", () => {
  it("checks in on arrival, inside the area, in the window", () => {
    expect(
      autoCheckInDecision(shift(), at("2026-10-12T05:00:00Z"), atBranch),
    ).toEqual({ ok: true });
  });

  it("never for someone who has not opted in", () => {
    expect(
      autoCheckInDecision(
        shift({ auto: false }),
        at("2026-10-12T05:00:00Z"),
        atBranch,
      ),
    ).toEqual({ ok: false, reason: "not_enabled" });
  });

  it("not more than an hour early, nor after the grace period", () => {
    expect(
      autoCheckInDecision(shift(), at("2026-10-12T04:20:00Z"), atBranch),
    ).toMatchObject({ reason: "outside_window" });
    expect(
      autoCheckInDecision(shift(), at("2026-10-12T05:41:00Z"), atBranch),
    ).toMatchObject({ reason: "outside_window" });
  });

  it("not outside the branch area, nor on a fix too vague to tell", () => {
    const away = { ...atBranch, latitude: BRANCH.latitude + 0.01 }; // ~1.1 km
    expect(
      autoCheckInDecision(shift(), at("2026-10-12T05:00:00Z"), away),
    ).toMatchObject({ reason: "outside_area" });
    expect(
      autoCheckInDecision(shift(), at("2026-10-12T05:00:00Z"), {
        ...atBranch,
        accuracy: 900,
      }),
    ).toMatchObject({ reason: "imprecise" });
  });

  it("never where a selfie is required, or once already in", () => {
    expect(
      autoCheckInDecision(
        shift({ photo: true }),
        at("2026-10-12T05:00:00Z"),
        atBranch,
      ),
    ).toMatchObject({ reason: "photo_required" });
    expect(
      autoCheckInDecision(
        shift({ status: "checked_in" }),
        at("2026-10-12T05:00:00Z"),
        atBranch,
      ),
    ).toMatchObject({ reason: "already_in" });
  });
});

describe("geofence", () => {
  it("measures the distance to the branch", () => {
    const away = {
      latitude: BRANCH.latitude + 0.01,
      longitude: BRANCH.longitude,
    };
    expect(Math.round(distanceMeters(BRANCH, away))).toBe(1112);
    expect(geofenceState(shift(), { ...away, accuracy: 10 }).kind).toBe(
      "outside",
    );
    expect(geofenceState(shift(), atBranch).kind).toBe("inside");
    expect(geofenceState(shift({ geofence: null }), atBranch).kind).toBe(
      "none",
    );
  });
});
