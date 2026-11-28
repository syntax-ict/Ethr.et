import { describe, it, expect, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { OtpCodeInput } from "@/components/shared/otp-code-input";

function Harness({
  onPasteComplete,
}: {
  onPasteComplete?: (c: string) => void;
}) {
  const [digits, setDigits] = useState<string[]>(Array(6).fill(""));
  return (
    <>
      <label htmlFor="code">Verification code</label>
      <OtpCodeInput
        id="code"
        digits={digits}
        onChange={setDigits}
        onPasteComplete={onPasteComplete}
      />
    </>
  );
}

describe("OtpCodeInput", () => {
  it("is one input with the one-time-code hints and an accessible name", () => {
    render(<Harness />);
    const input = screen.getByLabelText("Verification code");
    expect(screen.getAllByRole("textbox")).toHaveLength(1);
    expect(input).toHaveAttribute("autocomplete", "one-time-code");
    expect(input).toHaveAttribute("inputmode", "numeric");
    expect(input).toHaveAttribute("maxlength", "6");
  });

  it("fills the cells as digits are typed", async () => {
    const user = userEvent.setup();
    const { container } = render(<Harness />);
    await user.type(screen.getByLabelText("Verification code"), "123");
    const cells = container.querySelectorAll('div[aria-hidden="true"]');
    expect(Array.from(cells).map((c) => c.textContent)).toEqual([
      "1",
      "2",
      "3",
      "",
      "",
      "",
    ]);
  });

  it("fills all six from a paste and reports the whole code", () => {
    const done = vi.fn();
    render(<Harness onPasteComplete={done} />);
    const input = screen.getByLabelText("Verification code");
    fireEvent.paste(input, { clipboardData: { getData: () => "123 456" } });
    expect(input).toHaveValue("123456");
    expect(done).toHaveBeenCalledWith("123456");
  });

  it("fills all six from an autofill style change", () => {
    const done = vi.fn();
    render(<Harness onPasteComplete={done} />);
    const input = screen.getByLabelText("Verification code");
    fireEvent.change(input, { target: { value: "654321" } });
    expect(input).toHaveValue("654321");
    expect(done).toHaveBeenCalledWith("654321");
  });

  it("does not report completion when the last digit is typed", async () => {
    const user = userEvent.setup();
    const done = vi.fn();
    render(<Harness onPasteComplete={done} />);
    await user.type(screen.getByLabelText("Verification code"), "123456");
    expect(done).not.toHaveBeenCalled();
  });

  it("drops non-digits", () => {
    render(<Harness />);
    const input = screen.getByLabelText("Verification code");
    fireEvent.change(input, { target: { value: "1a-2b3" } });
    expect(input).toHaveValue("123");
  });
});
