import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ChartCard, ChartTable } from "@/components/dashboard/chart-card";

/**
 * A chart is a picture; a screen reader or a phone reads its figures better
 * as rows. A card whose children take the view offers "Show as table" and
 * draws a real table with a caption and column headers (dashboard review,
 * 2026-11-03, PR 8). A card with plain children offers nothing new.
 */
const ROWS = [
  { day: "Mon", present: 8, absent: 1 },
  { day: "Tue", present: 7, absent: 2 },
];

describe("<ChartCard>", () => {
  it("offers the figures as a table, and the chart again", async () => {
    const user = userEvent.setup();
    render(
      <ChartCard title="Your team this week">
        {(view) =>
          view === "table" ? (
            <ChartTable
              caption="Your team this week"
              columns={[
                { key: "day", label: "Day" },
                { key: "present", label: "Present", numeric: true },
                { key: "absent", label: "Absent", numeric: true },
              ]}
              rows={ROWS}
            />
          ) : (
            <div data-testid="chart" />
          )
        }
      </ChartCard>,
    );

    expect(screen.getByTestId("chart")).toBeInTheDocument();
    const toggle = screen.getByRole("button", { name: "Show as table" });
    expect(toggle).toHaveAttribute("aria-pressed", "false");

    await user.click(toggle);

    const table = screen.getByRole("table", { name: "Your team this week" });
    expect(table).toBeInTheDocument();
    expect(screen.queryByTestId("chart")).toBeNull();
    expect(
      screen.getAllByRole("columnheader").map((th) => th.textContent),
    ).toEqual(["Day", "Present", "Absent"]);
    expect(screen.getByRole("row", { name: /Tue 7 2/ })).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Show as chart" }),
    ).toHaveAttribute("aria-pressed", "true");

    await user.click(screen.getByRole("button", { name: "Show as chart" }));
    expect(screen.getByTestId("chart")).toBeInTheDocument();
  });

  it("offers no toggle when the children are not a function", () => {
    render(
      <ChartCard title="Leave utilisation">
        <div data-testid="content" />
      </ChartCard>,
    );

    expect(screen.getByTestId("content")).toBeInTheDocument();
    expect(screen.queryByRole("button")).toBeNull();
  });
});
