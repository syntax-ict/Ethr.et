import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";

import { OptionSelect } from "@/components/shared/option-select";
import { RELATIONSHIPS, relationshipLabel } from "@/lib/options/relationship";

const label = (v: string) => relationshipLabel((_k, f) => f ?? _k, v);

function Harness({ initial, other }: { initial: string; other?: string }) {
  const [value, setValue] = useState(initial);
  return (
    <>
      <OptionSelect
        id="rel"
        value={value}
        onChange={setValue}
        options={RELATIONSHIPS}
        getLabel={label}
        otherValue={other}
        otherLabel="Say how"
      />
      <output data-testid="value">{value}</output>
    </>
  );
}

const value = () => screen.getByTestId("value").textContent;

describe("OptionSelect", () => {
  it("picks a value from the list", async () => {
    const user = userEvent.setup();
    render(<Harness initial="" />);

    await user.click(screen.getByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Parent" }));

    expect(value()).toBe("parent");
    expect(screen.getByRole("combobox")).toHaveTextContent("Parent");
  });

  it("shows a stored value the list does not have, as written", () => {
    render(<Harness initial="Uncle" />);

    expect(screen.getByRole("combobox")).toHaveTextContent("Uncle");
    expect(value()).toBe("Uncle");
  });

  it("keeps what is typed beside Other, and returns to Other when it is cleared", async () => {
    const user = userEvent.setup();
    render(<Harness initial="" other="other" />);

    await user.click(screen.getByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Other" }));
    expect(value()).toBe("other");

    const box = screen.getByPlaceholderText("Say how");
    await user.type(box, "Neighbour");
    expect(value()).toBe("Neighbour");

    await user.clear(box);
    expect(value()).toBe("other");
  });

  it("opens a stored free-text value as Other with the text in the box", () => {
    render(<Harness initial="Uncle" other="other" />);

    expect(screen.getByRole("combobox")).toHaveTextContent("Other");
    expect(screen.getByPlaceholderText("Say how")).toHaveValue("Uncle");
    expect(value()).toBe("Uncle");
  });

  it("hides the box again when a listed value is picked", async () => {
    const user = userEvent.setup();
    render(<Harness initial="Uncle" other="other" />);

    await user.click(screen.getByRole("combobox"));
    await user.click(await screen.findByRole("option", { name: "Friend" }));

    expect(value()).toBe("friend");
    expect(screen.queryByPlaceholderText("Say how")).not.toBeInTheDocument();
  });

  it("follows a value that arrives after it first appeared", async () => {
    const { rerender } = render(
      <OptionSelect
        id="rel"
        value=""
        onChange={() => {}}
        options={RELATIONSHIPS}
        getLabel={label}
        otherValue="other"
        otherLabel="Say how"
      />,
    );
    expect(screen.queryByPlaceholderText("Say how")).not.toBeInTheDocument();

    rerender(
      <OptionSelect
        id="rel"
        value="Uncle"
        onChange={() => {}}
        options={RELATIONSHIPS}
        getLabel={label}
        otherValue="other"
        otherLabel="Say how"
      />,
    );
    expect(screen.getByPlaceholderText("Say how")).toHaveValue("Uncle");
    expect(screen.getByRole("combobox")).toHaveTextContent("Other");

    rerender(
      <OptionSelect
        id="rel"
        value=""
        onChange={() => {}}
        options={RELATIONSHIPS}
        getLabel={label}
        otherValue="other"
        otherLabel="Say how"
      />,
    );
    expect(screen.queryByPlaceholderText("Say how")).not.toBeInTheDocument();
  });
});
