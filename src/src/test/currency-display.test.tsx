import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { CurrencyDisplay } from "@/components/shared/currency-display";

describe("CurrencyDisplay", () => {
  it("formats cents to ETB correctly", () => {
    render(<CurrencyDisplay cents={150000} />);
    expect(screen.getByText(/1,500\.00 ETB/)).toBeInTheDocument();
  });

  it("handles zero", () => {
    render(<CurrencyDisplay cents={0} />);
    expect(screen.getByText(/0\.00 ETB/)).toBeInTheDocument();
  });

  it("handles large amounts", () => {
    render(<CurrencyDisplay cents={1000000} />);
    expect(screen.getByText(/10,000\.00 ETB/)).toBeInTheDocument();
  });

  it("applies custom className", () => {
    const { container } = render(
      <CurrencyDisplay cents={100} className="text-red-500" />,
    );
    expect(container.firstChild).toHaveClass("text-red-500");
  });
});
