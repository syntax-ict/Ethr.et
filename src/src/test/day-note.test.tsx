import { afterEach, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { DayNote } from "@/features/attendance/components/day-note";

/**
 * Every way of punching says what the day is: a holiday by name, the weekend,
 * a day off, leave. A working day says nothing.
 */
afterEach(() => {
  localStorage.setItem("locale", "en");
});

const day = (over: Partial<Parameters<typeof DayNote>[0]["day"]> = {}) => ({
  kind: "workday",
  weekend: false,
  holiday: null,
  ...over,
});

describe("DayNote", () => {
  it("names the holiday", () => {
    render(
      <DayNote
        day={day({
          kind: "holiday",
          holiday: { name: "Meskel", name_am: "መስቀል" },
        })}
      />,
    );

    expect(screen.getByRole("status")).toHaveTextContent("Today is Meskel");
    expect(screen.getByRole("status")).toHaveTextContent(
      "No shift is expected today.",
    );
  });

  it("names the holiday in Amharic when the reader uses Amharic", () => {
    localStorage.setItem("locale", "am");

    render(
      <DayNote
        day={day({
          kind: "holiday",
          holiday: { name: "Meskel", name_am: "መስቀል" },
        })}
      />,
    );

    expect(screen.getByRole("status")).toHaveTextContent("መስቀል");
  });

  it("wishes a happy weekend on Saturday and Sunday", () => {
    render(<DayNote day={day({ kind: "rest_day", weekend: true })} />);

    expect(screen.getByRole("status")).toHaveTextContent("Happy weekend");
  });

  it("says a day off that is not the weekend is a day off", () => {
    render(<DayNote day={day({ kind: "rest_day", weekend: false })} />);

    expect(screen.getByRole("status")).toHaveTextContent(
      "Today is your day off",
    );
  });

  it("says approved leave", () => {
    render(<DayNote day={day({ kind: "on_leave" })} />);

    expect(screen.getByRole("status")).toHaveTextContent(
      "You are on approved leave today",
    );
  });

  it("says nothing on a working day, or when the day is unknown", () => {
    const { container, rerender } = render(<DayNote day={day()} />);
    expect(container).toBeEmptyDOMElement();

    rerender(<DayNote day={null} />);
    expect(container).toBeEmptyDOMElement();

    rerender(<DayNote day={undefined} />);
    expect(container).toBeEmptyDOMElement();
  });
});
