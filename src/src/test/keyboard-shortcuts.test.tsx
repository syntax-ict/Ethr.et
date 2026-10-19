import { describe, it, expect } from "vitest";
import { act, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {
  KeyboardShortcuts,
  openKeyboardShortcuts,
} from "@/components/shared/keyboard-shortcuts";

// 2026-10-19: one sheet listing the shortcuts the app actually has, opened
// with "?" or from the account menu.
describe("keyboard shortcuts sheet", () => {
  it("opens with ? and lists only shortcuts that exist", async () => {
    const user = userEvent.setup();
    render(<KeyboardShortcuts />);

    await user.keyboard("?");

    const dialog = await screen.findByRole("dialog", {
      name: /keyboard shortcuts/i,
    });
    for (const label of [
      /search people, records and pages/i,
      /search, from anywhere/i,
      /collapse or expand the sidebar/i,
      /show these shortcuts/i,
      /close a dialog or menu/i,
    ]) {
      expect(dialog).toHaveTextContent(label);
    }
  });

  it("leaves ? alone while typing in a field", async () => {
    const user = userEvent.setup();
    render(
      <>
        <input aria-label="question" />
        <KeyboardShortcuts />
      </>,
    );

    await user.type(screen.getByLabelText("question"), "why?");

    expect(screen.getByLabelText("question")).toHaveValue("why?");
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("opens from the account menu's call", async () => {
    render(<KeyboardShortcuts />);

    act(() => openKeyboardShortcuts());

    expect(
      await screen.findByRole("dialog", { name: /keyboard shortcuts/i }),
    ).toBeInTheDocument();
  });
});
