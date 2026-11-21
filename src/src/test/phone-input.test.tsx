import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { PhoneInput } from "@/components/shared/phone-input";

function ControlledPhoneInput({
  initialValue = "",
}: {
  initialValue?: string;
}) {
  const [value, setValue] = useState(initialValue);
  return <PhoneInput value={value} onChange={setValue} />;
}

describe("PhoneInput", () => {
  it("always shows the +251 prefix", () => {
    render(<ControlledPhoneInput />);
    expect(screen.getByText("+251")).toBeInTheDocument();
  });

  it("strips the +251 prefix from the editable field's displayed value", () => {
    render(<ControlledPhoneInput initialValue="+251912345678" />);
    expect(screen.getByPlaceholderText("911234567…")).toHaveValue("912345678");
  });

  it("emits a full +251-prefixed value as the user types digits", async () => {
    const user = userEvent.setup();
    let lastValue = "";

    function Harness() {
      const [value, setValue] = useState("");
      return (
        <PhoneInput
          value={value}
          onChange={(v) => {
            setValue(v);
            lastValue = v;
          }}
        />
      );
    }

    render(<Harness />);
    await user.type(screen.getByPlaceholderText("911234567…"), "912345678");

    expect(lastValue).toBe("+251912345678");
  });

  it("strips non-digit characters and caps input at 9 digits", async () => {
    const user = userEvent.setup();
    let lastValue = "";

    function Harness() {
      const [value, setValue] = useState("");
      return (
        <PhoneInput
          value={value}
          onChange={(v) => {
            setValue(v);
            lastValue = v;
          }}
        />
      );
    }

    render(<Harness />);
    await user.type(
      screen.getByPlaceholderText("911234567…"),
      "91-234a5678999",
    );

    expect(lastValue).toBe("+251912345678");
  });

  it("emits an empty string when the field is cleared", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput initialValue="+251912345678" />);

    const input = screen.getByPlaceholderText("911234567…");
    await user.clear(input);

    expect(input).toHaveValue("");
  });

  it.each(["0912345678", "+251912345678", "251912345678", "912345678"])(
    "keeps every digit of a pasted %s",
    async (pasted) => {
      const user = userEvent.setup();
      let lastValue = "";
      function Harness() {
        const [value, setValue] = useState("");
        return (
          <PhoneInput
            value={value}
            onChange={(v) => {
              setValue(v);
              lastValue = v;
            }}
          />
        );
      }
      render(<Harness />);
      await user.click(screen.getByPlaceholderText("911234567…"));
      await user.paste(pasted);
      expect(lastValue).toBe("+251912345678");
    },
  );
});
