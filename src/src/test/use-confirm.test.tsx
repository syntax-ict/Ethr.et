import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { useConfirm } from "@/components/shared/use-confirm";

/**
 * `useConfirm()` replaced all thirteen native `confirm()` calls
 * (FRONTEND_REDESIGN_PLAN.md, Phase 2). Every one of those callers reads the
 * answer as a boolean, so the three ways out of the dialog must each give one.
 */
function Harness() {
  const { requestConfirm, confirmDialog } = useConfirm();
  const [answer, setAnswer] = useState("none");
  return (
    <>
      <button
        onClick={async () => {
          const ok = await requestConfirm({
            title: 'Delete branch "Bole"?',
            confirmLabel: "Delete",
            variant: "destructive",
          });
          setAnswer(String(ok));
        }}
      >
        Ask
      </button>
      <output>{answer}</output>
      {confirmDialog}
    </>
  );
}

async function ask() {
  const user = userEvent.setup();
  render(<Harness />);
  await user.click(screen.getByRole("button", { name: "Ask" }));
  const dialog = await screen.findByRole("dialog");
  return { user, dialog };
}

describe("useConfirm", () => {
  it("asks the question in the app's own dialog", async () => {
    const { dialog } = await ask();
    expect(
      within(dialog).getByRole("heading", { name: 'Delete branch "Bole"?' }),
    ).toBeInTheDocument();
  });

  it("answers true when the action is confirmed", async () => {
    const { user, dialog } = await ask();
    await user.click(within(dialog).getByRole("button", { name: "Delete" }));
    expect(await screen.findByText("true")).toBeInTheDocument();
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("answers false on Cancel", async () => {
    const { user, dialog } = await ask();
    await user.click(within(dialog).getByRole("button", { name: "Cancel" }));
    expect(await screen.findByText("false")).toBeInTheDocument();
  });

  it("answers false on Escape, so the keyboard can always back out", async () => {
    const { user } = await ask();
    await user.keyboard("{Escape}");
    expect(await screen.findByText("false")).toBeInTheDocument();
  });

  it("answers false from the corner Close button, which has a name", async () => {
    const { user, dialog } = await ask();
    await user.click(within(dialog).getByRole("button", { name: "Close" }));
    expect(await screen.findByText("false")).toBeInTheDocument();
  });
});
