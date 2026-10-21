import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import {
  KNOWN_STATUSES,
  statusToneOf,
  toneClasses,
  type StatusTone,
} from "@/lib/status/registry";
import { statusBadgeClass } from "@/lib/utils/status-colors";
import { StatusBadge } from "@/components/shared/status-badge";

/**
 * Two status tables disagreed until 2026-10-20 (FRONTEND_REDESIGN_PLAN.md,
 * problem 5). These pin the single table and the decisions that ended the
 * disagreement, so a third table cannot quietly start again.
 */
describe("status registry", () => {
  it.each<[string, StatusTone]>([
    // Reversible trouble: amber in both places now, red in one before.
    ["suspended", "warning"],
    // Closed without judgement: grey, not red.
    ["cancelled", "neutral"],
    // Not started: grey, not blue.
    ["draft", "neutral"],
  ])("draws %s as %s everywhere", (status, tone) => {
    expect(statusToneOf(status)).toBe(tone);
    expect(statusBadgeClass(status)).toContain(toneClasses(tone).badge);
  });

  it("separates a calculated payroll run from an approved one", () => {
    expect(statusToneOf("completed", "payroll_run")).toBe("warning");
    expect(statusToneOf("approved", "payroll_run")).toBe("success");
    // A completed loan is repaid, which is good news.
    expect(statusToneOf("completed")).toBe("success");
  });

  it("falls back to neutral for a status it has never seen", () => {
    expect(statusToneOf("something_new")).toBe("neutral");
  });

  it("reads a status in any case", () => {
    expect(statusToneOf("ACTIVE")).toBe("success");
  });

  it("draws every tone with a soft pair, which the contrast test holds to AA", () => {
    for (const status of KNOWN_STATUSES) {
      const { badge } = toneClasses(statusToneOf(status));
      expect(badge, status).toMatch(/^bg-[a-z]+-soft text-[a-z]+-on-soft$/);
    }
  });

  it("gives StatusBadge the same colour the helper gives", () => {
    render(<StatusBadge status="suspended" />);
    const badge = screen.getByText(/suspended/i).closest("div, span")!;
    expect(badge.className).toContain(toneClasses("warning").badge);
  });

  it("lets a payroll run badge use its own meaning of completed", () => {
    render(<StatusBadge status="completed" domain="payroll_run" />);
    // Calculated and waiting: "Completed" read as finished.
    const badge = screen.getByText("Awaiting approval").closest("div, span")!;
    expect(badge.className).toContain(toneClasses("warning").badge);
  });

  it("keeps the plain word for a completed status elsewhere", () => {
    render(<StatusBadge status="completed" />);
    expect(screen.getByText("Completed")).toBeInTheDocument();
  });
});
