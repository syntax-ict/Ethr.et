import { afterEach, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { KioskPunchResultCard } from "@/features/kiosk/components/kiosk-punch-result";
import {
  punchOutcomeText,
  type PunchOutcomeInfo,
} from "@/features/attendance/punch-outcome";

/**
 * Several ways of punching share one record a day. Each screen says what a
 * punch came to in the same words: on time, late, early out, or "already
 * checked in" by another method.
 */
afterEach(() => {
  localStorage.setItem("locale", "en");
});

// Real strings come from the dictionary, so these use the en fallbacks.
const t = (
  _key: string,
  fallback?: string,
  replacements?: Record<string, string | number>,
) =>
  Object.entries(replacements ?? {}).reduce(
    (text, [name, value]) => text.replace(`:${name}`, String(value)),
    fallback ?? "",
  );

const clock = (iso: string) => iso.slice(11, 16);

const outcome = (over: Partial<PunchOutcomeInfo>): PunchOutcomeInfo => ({
  code: "checked_in",
  minutes: 0,
  by: null,
  at: null,
  ...over,
});

describe("punchOutcomeText", () => {
  it("says checked in and checked out", () => {
    expect(punchOutcomeText(outcome({}), t, clock)).toMatchObject({
      title: "Checked in successfully",
      tone: "success",
      already: false,
    });
    expect(
      punchOutcomeText(outcome({ code: "checked_out" }), t, clock),
    ).toMatchObject({ title: "Checked out successfully", tone: "success" });
  });

  it("says how late and how early", () => {
    expect(
      punchOutcomeText(
        outcome({ code: "checked_in_late", minutes: 30 }),
        t,
        clock,
      )?.title,
    ).toBe("Checked in, 30 min late");
    expect(
      punchOutcomeText(
        outcome({ code: "checked_out_early", minutes: 90 }),
        t,
        clock,
      )?.title,
    ).toBe("Checked out, 90 min early");
  });

  it("tells the kiosk the person is already in from the phone", () => {
    const text = punchOutcomeText(
      outcome({
        code: "already_checked_in",
        by: "mobile",
        at: "2026-10-12T05:25:00+00:00",
      }),
      t,
      clock,
    );

    expect(text?.title).toBe("Already checked in at 05:25 (Mobile)");
    expect(text?.already).toBe(true);
    expect(text?.tone).toBe("info");
    expect(text?.hint).toContain("one record");
  });

  it("names the method that already closed the day", () => {
    expect(
      punchOutcomeText(
        outcome({
          code: "already_checked_out",
          by: "kiosk",
          at: "2026-10-12T14:35:00+00:00",
        }),
        t,
        clock,
      )?.title,
    ).toBe("Already checked out at 14:35 (Kiosk)");
  });

  it("says nothing when the server sent no outcome", () => {
    expect(punchOutcomeText(null, t, clock)).toBeNull();
    expect(punchOutcomeText(undefined, t, clock)).toBeNull();
    expect(
      punchOutcomeText(outcome({ code: "something_new" }), t, clock),
    ).toBeNull();
  });
});

describe("KioskPunchResultCard", () => {
  it("shows what the punch came to under the greeting", () => {
    const text = punchOutcomeText(
      outcome({
        code: "already_checked_in",
        by: "mobile",
        at: "2026-10-12T05:25:00+00:00",
      }),
      t,
      clock,
    );

    render(
      <KioskPunchResultCard
        message="Welcome, Abebe"
        time="08:25"
        person={null}
        outcome={text}
        canReport={false}
        onReport={async () => {}}
      />,
    );

    expect(screen.getByText("Welcome, Abebe")).toBeInTheDocument();
    expect(
      screen.getByText("Already checked in at 05:25 (Mobile)"),
    ).toBeInTheDocument();
  });
});
