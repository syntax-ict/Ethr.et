import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { ReasonDialog } from "@/components/shared/reason-dialog";

function Harness({ onConfirm }: { onConfirm: (reason: string) => void }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <button onClick={() => setOpen(true)}>Open</button>
      <ReasonDialog
        open={open}
        onOpenChange={setOpen}
        title="Reject request"
        label="Reason for rejection"
        confirmLabel="Reject"
        onConfirm={(reason) => {
          onConfirm(reason);
          setOpen(false);
        }}
      />
    </>
  );
}

describe("ReasonDialog", () => {
  it("will not confirm without a reason, and trims the one it sends", async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    render(<Harness onConfirm={onConfirm} />);

    await user.click(screen.getByRole("button", { name: "Open" }));
    const confirm = screen.getByRole("button", { name: "Reject" });
    expect(confirm).toBeDisabled();

    // Spaces alone are not a reason.
    const field = screen.getByRole("textbox", {
      name: /reason for rejection/i,
    });
    await user.type(field, "   ");
    expect(confirm).toBeDisabled();

    await user.type(field, " No cover that week ");
    await user.click(confirm);
    expect(onConfirm).toHaveBeenCalledWith("No cover that week");
  });

  it("starts empty each time it opens, so one reason never pre-fills the next", async () => {
    const user = userEvent.setup();
    render(<Harness onConfirm={() => {}} />);

    await user.click(screen.getByRole("button", { name: "Open" }));
    await user.type(
      screen.getByRole("textbox", { name: /reason for rejection/i }),
      "First refusal",
    );
    await user.click(screen.getByRole("button", { name: "Reject" }));

    await user.click(screen.getByRole("button", { name: "Open" }));
    expect(
      screen.getByRole("textbox", { name: /reason for rejection/i }),
    ).toHaveValue("");
  });

  it("caps the reason at the API's limit", async () => {
    render(
      <ReasonDialog
        open
        onOpenChange={() => {}}
        title="Reject request"
        label="Reason for rejection"
        confirmLabel="Reject"
        onConfirm={() => {}}
      />,
    );
    expect(
      screen.getByRole("textbox", { name: /reason for rejection/i }),
    ).toHaveAttribute("maxLength", "1000");
  });
});
