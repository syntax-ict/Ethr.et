import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import {
  ChangeLeaveDialog,
  dayBefore,
} from "@/features/leave/components/change-leave-dialog";

/**
 * A-Z review E-02 (2026-11-05). HR cancels approved leave or ends it early,
 * and the employee is told why, so the reason is required.
 */
const request = {
  start_date: "2026-12-07",
  end_date: "2026-12-11",
  employee: { name: "Abebe" },
};

describe("ChangeLeaveDialog", () => {
  it("cancels the whole leave only once a reason is given", async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    render(
      <ChangeLeaveDialog
        request={request}
        onOpenChange={() => {}}
        onConfirm={onConfirm}
      />,
    );

    await user.click(screen.getByLabelText("Cancel the whole leave"));
    const confirm = screen.getByRole("button", { name: "Cancel leave" });
    expect(confirm).toBeDisabled();

    await user.type(screen.getByRole("textbox"), "Trip called off");
    await user.click(confirm);

    expect(onConfirm).toHaveBeenCalledWith({
      endDate: null,
      reason: "Trip called off",
    });
  });

  it("does not end the leave early without a new last day", async () => {
    const user = userEvent.setup();
    render(
      <ChangeLeaveDialog
        request={request}
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );

    await user.type(
      screen.getByPlaceholderText("e.g. Came back to work early."),
      "Came back",
    );

    expect(
      screen.getByRole("button", { name: "Save new end date" }),
    ).toBeDisabled();
  });

  it("offers at most the day before the current last day", () => {
    expect(dayBefore("2026-12-11")).toBe("2026-12-10");
    expect(dayBefore("2027-01-01")).toBe("2026-12-31");
  });
});
