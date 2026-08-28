import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { CurrencyInput } from "@/components/shared/currency-input";

function ControlledCurrencyInput({
  initialCents = 0,
}: {
  initialCents?: number;
}) {
  const [cents, setCents] = useState(initialCents);
  return (
    <CurrencyInput
      value={cents}
      onChange={setCents}
      placeholder="Enter amount"
    />
  );
}

describe("CurrencyInput", () => {
  it("renders an empty field for zero cents", () => {
    render(<ControlledCurrencyInput />);
    expect(screen.getByPlaceholderText("Enter amount")).toHaveValue("");
  });

  it("shows an existing value formatted to 2 decimal places", () => {
    render(<ControlledCurrencyInput initialCents={150000} />);
    expect(screen.getByPlaceholderText("Enter amount")).toHaveValue("1500.00");
  });

  it("does not clobber in-progress typing with the round-tripped formatted value", async () => {
    const user = userEvent.setup();
    render(<ControlledCurrencyInput />);

    const input = screen.getByPlaceholderText("Enter amount");
    await user.type(input, "1000");

    // Each keystroke round-trips through the parent's onChange -> value prop.
    // If the component resynced from the prop while focused, "1000" would
    // get stomped by an intermediate formatted value (e.g. "10.00").
    expect(input).toHaveValue("1000");
  });

  it("reformats to 2 decimal places on blur", async () => {
    const user = userEvent.setup();
    render(<ControlledCurrencyInput />);

    const input = screen.getByPlaceholderText("Enter amount");
    await user.type(input, "1000");
    await user.tab();

    expect(input).toHaveValue("1000.00");
  });

  it("emits integer cents, not a float ETB amount", async () => {
    const user = userEvent.setup();
    let lastCents: number | undefined;

    function Harness() {
      const [cents, setCents] = useState(0);
      return (
        <CurrencyInput
          value={cents}
          onChange={(c) => {
            setCents(c);
            lastCents = c;
          }}
          placeholder="Enter amount"
        />
      );
    }

    render(<Harness />);
    await user.type(screen.getByPlaceholderText("Enter amount"), "1000.50");

    expect(lastCents).toBe(100050);
  });
});
