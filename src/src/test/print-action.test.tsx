import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PrintAction } from "@/components/shared/print-action";

describe("<PrintAction>", () => {
  it("prints the page, and is not itself printed", async () => {
    const print = vi.fn();
    vi.stubGlobal("print", print);
    const user = userEvent.setup();
    render(<PrintAction />);

    const button = screen.getByRole("button", { name: "Print" });
    expect(button).toHaveClass("no-print");
    await user.click(button);

    expect(print).toHaveBeenCalledOnce();
    vi.unstubAllGlobals();
  });
});
