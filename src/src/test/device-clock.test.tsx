import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { clockFinding, driftSize } from "@/features/devices/clock";
import { DeviceClockNote } from "@/features/devices/components/device-clock-note";
import { DeviceTodayCard } from "@/features/devices/components/device-today";

const HOUR = 3600;
const YEAR = 365 * 86400;

describe("clockFinding", () => {
  it("reads within two minutes as in step, either way", () => {
    expect(clockFinding(0, true).kind).toBe("in_step");
    expect(clockFinding(120, true).kind).toBe("in_step");
    expect(clockFinding(-120, true).kind).toBe("in_step");
  });

  it("warns beyond two minutes and says which way", () => {
    expect(clockFinding(121, true)).toEqual({ kind: "ahead", seconds: 121 });
    expect(clockFinding(-600, true)).toEqual({ kind: "behind", seconds: -600 });
  });

  it("calls six hours, ahead or behind, Ethiopian time of day, within half an hour", () => {
    expect(clockFinding(6 * HOUR, true).kind).toBe("ethiopian_time");
    expect(clockFinding(-6 * HOUR, true).kind).toBe("ethiopian_time");
    expect(clockFinding(6 * HOUR + 1799, true).kind).toBe("ethiopian_time");
    expect(clockFinding(6 * HOUR - 1800, true).kind).toBe("ethiopian_time");
    expect(clockFinding(6 * HOUR + 1801, true).kind).toBe("ahead");
    expect(clockFinding(3 * HOUR, true).kind).toBe("ahead");
  });

  it("calls a year or more the Ethiopian calendar", () => {
    expect(clockFinding(-7 * YEAR, true).kind).toBe("ethiopian_calendar");
    expect(clockFinding(YEAR, true).kind).toBe("ethiopian_calendar");
    expect(clockFinding(YEAR - 1, true).kind).not.toBe("ethiopian_calendar");
  });

  it("never reads a terminal that cannot be measured as zero", () => {
    expect(clockFinding(null, false)).toEqual({ kind: "unmeasurable" });
    expect(clockFinding(null, null)).toEqual({ kind: "unknown" });
    expect(clockFinding(undefined, undefined)).toEqual({ kind: "unknown" });
  });
});

describe("driftSize", () => {
  it("picks the unit a person would say", () => {
    expect(driftSize(60)).toEqual({ unit: "minutes", count: 1 });
    expect(driftSize(-6 * HOUR)).toEqual({ unit: "hours", count: 6 });
    expect(driftSize(5 * 86400)).toEqual({ unit: "days", count: 5 });
    expect(driftSize(-7 * YEAR)).toEqual({ unit: "years", count: 7 });
  });
});

describe("DeviceClockNote", () => {
  it("names Ethiopian time of day and the fix for six hours ahead", () => {
    render(
      <DeviceClockNote
        device={{ clock_offset_seconds: 6 * HOUR, clock_measurable: true }}
      />,
    );

    expect(screen.getByRole("status").textContent).toMatch(/6 hours ahead/);
    expect(screen.getByRole("status").textContent).toMatch(/24-hour clock/);
    expect(screen.getByRole("status").textContent).toMatch(/NTP/);
  });

  it("names the Ethiopian calendar for a date years out", () => {
    render(
      <DeviceClockNote
        device={{ clock_offset_seconds: -7 * YEAR, clock_measurable: true }}
      />,
    );

    expect(screen.getByRole("status").textContent).toMatch(/7 years behind/);
    expect(screen.getByRole("status").textContent).toMatch(
      /Ethiopian calendar/,
    );
  });

  it("says the clock is not measurable and shows no zero", () => {
    const { container } = render(
      <DeviceClockNote
        device={{ clock_offset_seconds: null, clock_measurable: false }}
      />,
    );

    expect(container.textContent).toMatch(/not measurable on this terminal/);
    expect(container.textContent).not.toMatch(/\b0\b/);
  });

  it("says nothing for a clock in step", () => {
    const { container } = render(
      <DeviceClockNote
        device={{ clock_offset_seconds: 30, clock_measurable: true }}
      />,
    );

    expect(container.textContent).toBe("");
  });
});

describe("DeviceTodayCard", () => {
  it("shows each of today's counts and the ones waiting", () => {
    render(
      <DeviceTodayCard
        today={{
          day: "2026-11-21",
          received: 42,
          recorded: 30,
          held: 5,
          duplicate: 6,
          quarantined: 1,
          waiting: 2,
        }}
      />,
    );

    expect(screen.getByText("Received").nextSibling?.textContent).toBe("42");
    expect(screen.getByText("Recorded").nextSibling?.textContent).toBe("30");
    expect(screen.getByText("Sent again").nextSibling?.textContent).toBe("6");
    expect(screen.getByText("Need a look").nextSibling?.textContent).toBe("1");
    expect(screen.getByText(/2 punches are waiting/)).toBeTruthy();
  });
});
