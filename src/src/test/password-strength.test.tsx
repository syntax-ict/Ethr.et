import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import {
  PasswordStrengthMeter,
  scorePassword,
} from "@/components/shared/password-strength";

/**
 * PHASE_00 S03 — password strength indicator. It previously existed only on the
 * reset page, not on password change or registration.
 */
describe("scorePassword", () => {
  it("reports nothing for an empty password", () => {
    expect(scorePassword("").score).toBe(0);
  });

  it("rates a short simple password weak", () => {
    expect(scorePassword("abc").score).toBe(1);
  });

  it("rates a long mixed password strong", () => {
    expect(scorePassword("Str0ng!Passphrase").score).toBe(4);
  });

  it("does not let length alone flatter a repeated character", () => {
    // 20 chars but only one distinct character — trivially guessable.
    expect(scorePassword("aaaaaaaaaaaaaaaaaaaa").score).toBe(1);
  });

  it("rewards added character classes", () => {
    const lower = scorePassword("abcdefghijkl").score;
    const mixed = scorePassword("Abcdefgh1jkl").score;

    expect(mixed).toBeGreaterThan(lower);
  });
});

describe("PasswordStrengthMeter", () => {
  it("renders nothing until something is typed", () => {
    const { container } = render(<PasswordStrengthMeter password="" />);

    expect(container).toBeEmptyDOMElement();
  });

  it("exposes the score to assistive technology", () => {
    render(<PasswordStrengthMeter password="Str0ng!Passphrase" />);

    const meter = screen.getByRole("meter");
    expect(meter).toHaveAttribute("aria-valuenow", "4");
    expect(meter).toHaveAttribute("aria-valuemax", "4");
  });

  it("announces the strength label politely", () => {
    render(<PasswordStrengthMeter password="abc" />);

    expect(screen.getByText("Weak")).toHaveAttribute("aria-live", "polite");
  });
});
