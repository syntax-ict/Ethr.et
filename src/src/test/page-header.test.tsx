import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { PageHeader } from "@/components/shared/page-header";

/**
 * The actions box was `shrink-0` in a `justify-end` row (audit N35). It kept
 * its one-line width on a phone, and the overflow spilled off the *left* edge,
 * which cannot be scrolled to: at 400px the analytics toolbar lost the first
 * 13px of its branch filter and Export button. Measured in the browser; jsdom
 * has no layout, so this pins the classes that decide it.
 */
describe("<PageHeader>", () => {
  it("lets the actions shrink and wrap instead of overflowing", () => {
    render(<PageHeader actions={<button type="button">Export</button>} />);

    const box = screen.getByRole("button", { name: "Export" }).parentElement!;
    expect(box).not.toHaveClass("shrink-0");
    expect(box).toHaveClass("min-w-0", "flex-wrap");
  });

  it("keeps wrapped actions aligned to the end", () => {
    render(<PageHeader actions={<button type="button">Export</button>} />);

    const box = screen.getByRole("button", { name: "Export" }).parentElement!;
    expect(box).toHaveClass("justify-end");
  });

  it("renders nothing without actions", () => {
    const { container } = render(<PageHeader title="Analytics" />);
    expect(container).toBeEmptyDOMElement();
  });
});
