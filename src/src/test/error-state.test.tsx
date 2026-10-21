import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ErrorState } from "@/components/shared/error-state";

describe("ErrorState", () => {
  it("is announced, says what happened, and retries", async () => {
    const onRetry = vi.fn();
    render(<ErrorState onRetry={onRetry} />);

    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("Couldn't load this");
    expect(alert).toHaveTextContent("The data may be out of date. Try again.");

    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: /Try again/ }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it("drops the hint in compact form, for a card or a chart", () => {
    render(<ErrorState compact onRetry={() => {}} />);
    expect(screen.getByRole("alert")).not.toHaveTextContent(
      "The data may be out of date",
    );
  });

  it("offers no button when there is nothing to retry", () => {
    render(<ErrorState />);
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });
});
