import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { PrintAction } from "@/components/shared/print-action";

afterEach(() => vi.unstubAllGlobals());

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
  });

  it("with a PDF offered, opens a menu of two and prints the page from it", async () => {
    const print = vi.fn();
    vi.stubGlobal("print", print);
    const user = userEvent.setup();
    render(
      <PrintAction pdf={{ label: "Full register", onDownload: vi.fn() }} />,
    );

    await user.click(screen.getByRole("button", { name: /Print/ }));
    expect(screen.getAllByRole("menuitem")).toHaveLength(2);
    await user.click(screen.getByRole("menuitem", { name: "Print this page" }));

    expect(print).toHaveBeenCalledOnce();
  });

  it("downloads the PDF, shows its note, and does not print", async () => {
    const print = vi.fn();
    vi.stubGlobal("print", print);
    const onDownload = vi.fn().mockResolvedValue(undefined);
    const user = userEvent.setup();
    render(
      <PrintAction
        pdf={{ label: "Full register", onDownload, note: "Source not applied" }}
      />,
    );

    await user.click(screen.getByRole("button", { name: /Print/ }));
    expect(screen.getByText("Source not applied")).toBeInTheDocument();
    await user.click(screen.getByRole("menuitem", { name: /Full register/ }));

    await waitFor(() => expect(onDownload).toHaveBeenCalledOnce());
    expect(print).not.toHaveBeenCalled();
  });

  it("survives a failed download", async () => {
    const onDownload = vi.fn().mockRejectedValue(new Error("boom"));
    const user = userEvent.setup();
    render(<PrintAction pdf={{ label: "Full register", onDownload }} />);

    await user.click(screen.getByRole("button", { name: /Print/ }));
    await user.click(screen.getByRole("menuitem", { name: /Full register/ }));

    await waitFor(() => expect(onDownload).toHaveBeenCalledOnce());
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /Print/ })).toHaveAttribute(
        "aria-busy",
        "false",
      ),
    );
  });
});
