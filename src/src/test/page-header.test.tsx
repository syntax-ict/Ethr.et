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

  it("aligns to the end safely, so what cannot fit overflows to the right", () => {
    render(<PageHeader actions={<button type="button">Export</button>} />);

    const box = screen.getByRole("button", { name: "Export" }).parentElement!;
    // `safe flex-end`: plain end alignment pushed /shifts off the left edge.
    expect(box).toHaveClass("justify-end-safe");
    expect(box.parentElement).toHaveClass("justify-end-safe");
  });

  it("wraps the group a page passes in, not only its own children", () => {
    // Most pages pass one `flex gap-2` group of buttons; without wrapping it,
    // that group kept its one-line width and /shifts began at -83px.
    render(
      <PageHeader
        actions={
          <div className="flex gap-2">
            <button type="button">Roster</button>
          </div>
        }
      />,
    );

    const box = screen.getByRole("button", { name: "Roster" }).parentElement!
      .parentElement!;
    expect(box).toHaveClass("*:flex-wrap");
  });

  it("renders nothing without actions", () => {
    const { container } = render(<PageHeader title="Analytics" />);
    expect(container).toBeEmptyDOMElement();
  });
});
