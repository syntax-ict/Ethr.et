import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { PhoneInput } from "@/components/shared/phone-input";

function ControlledPhoneInput({
  initialValue = "",
  international = false,
}: {
  initialValue?: string;
  international?: boolean;
}) {
  const [value, setValue] = useState(initialValue);
  return (
    <>
      <PhoneInput
        value={value}
        onChange={setValue}
        international={international}
      />
      <output data-testid="value">{value}</output>
    </>
  );
}

describe("PhoneInput", () => {
  it("always shows the +251 prefix", () => {
    render(<ControlledPhoneInput />);
    expect(screen.getByText("+251")).toBeInTheDocument();
  });

  it("strips the +251 prefix from the editable field's displayed value", () => {
    render(<ControlledPhoneInput initialValue="+251912345678" />);
    expect(screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX")).toHaveValue(
      "91 234 5678",
    );
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
    await user.type(
      screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX"),
      "912345678",
    );

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
      screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX"),
      "91-234a5678999",
    );

    expect(lastValue).toBe("+251912345678");
  });

  it("emits an empty string when the field is cleared", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput initialValue="+251912345678" />);

    const input = screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX");
    await user.clear(input);

    expect(input).toHaveValue("");
  });

  it("drops a leading 0 when a local number is typed", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput />);

    await user.type(
      screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX"),
      "0911223344",
    );

    expect(screen.getByTestId("value")).toHaveTextContent("+251911223344");
  });

  it("reads a pasted local number with spaces as one Ethiopian number", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput />);

    await user.click(screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX"));
    await user.paste("0911 22 33 44");

    expect(screen.getByTestId("value")).toHaveTextContent("+251911223344");
  });

  it("reads a pasted +251 number without doubling the country code", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput />);

    await user.click(screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX"));
    await user.paste("+251 911 223 344");

    expect(screen.getByTestId("value")).toHaveTextContent("+251911223344");
  });

  it("shows a stored local number with its leading 0 removed, not one digit short", () => {
    render(<ControlledPhoneInput initialValue="0911223344" />);

    expect(screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX")).toHaveValue(
      "91 122 3344",
    );
  });

  it("groups at rest and shows plain digits while typing", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput initialValue="+251911223344" />);

    const input = screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX");
    expect(input).toHaveValue("91 122 3344");

    await user.click(input);
    expect(input).toHaveValue("911223344");

    await user.tab();
    expect(input).toHaveValue("91 122 3344");
  });

  it("keeps a typed + as digits only when foreign numbers are not allowed", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput />);

    await user.type(
      screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX"),
      "+44 20 7946 0958",
    );

    expect(screen.getByTestId("value")).toHaveTextContent("+251442079460");
  });

  it("switches to a foreign number when international is allowed", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput international />);

    await user.type(
      screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX"),
      "+4420",
    );

    expect(screen.getByTestId("value")).toHaveTextContent("+4420");
    expect(screen.queryByText("+251")).not.toBeInTheDocument();
  });

  it("keeps the leading 251 of a Dire Dawa landline when a digit is inserted", async () => {
    const user = userEvent.setup();
    render(<ControlledPhoneInput initialValue="+251251112233" />);

    const input = screen.getByPlaceholderText("9XXXXXXXX / 7XXXXXXXX");
    await user.click(input);
    await user.type(input, "7", {
      initialSelectionStart: 3,
      initialSelectionEnd: 3,
    });

    expect(screen.getByTestId("value")).toHaveTextContent("+251251711223");
  });
});
