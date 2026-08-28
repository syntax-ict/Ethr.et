import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { StatusBadge } from "@/components/shared/status-badge";

/**
 * The badge used to print the raw enum, so every status in the product rendered
 * English regardless of locale. It now resolves `status.<value>` and falls back to
 * the humanised value, which is what keeps an unmapped status readable instead of
 * leaking a bare key into the UI.
 */
describe("StatusBadge", () => {
  it("renders a translated label for a known status", () => {
    render(<StatusBadge status="active" />);
    expect(screen.getByText("Active")).toBeInTheDocument();
  });

  it("renders pending status", () => {
    render(<StatusBadge status="pending" />);
    expect(screen.getByText("Pending")).toBeInTheDocument();
  });

  it("renders approved status", () => {
    render(<StatusBadge status="approved" />);
    expect(screen.getByText("Approved")).toBeInTheDocument();
  });

  it("renders rejected status", () => {
    render(<StatusBadge status="rejected" />);
    expect(screen.getByText("Rejected")).toBeInTheDocument();
  });

  it("humanises an underscored status rather than showing the raw enum", () => {
    render(<StatusBadge status="on_leave" />);
    expect(screen.getByText("On Leave")).toBeInTheDocument();
  });

  it("falls back to the humanised value for a status with no translation key", () => {
    // Deliberately not in the status.* namespace: the fallback must never surface
    // a bare key like "status.quantum_flux" to a user.
    render(<StatusBadge status="quantum_flux" />);
    expect(screen.getByText("quantum flux")).toBeInTheDocument();
  });
});
