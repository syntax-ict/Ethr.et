import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { ShiftCoverageCharts } from "@/features/shifts/components/shift-coverage-charts";

/**
 * The Shifts page's coverage cards (charts upgrade, 2026-11-04): a heatmap
 * of people scheduled per shift and day, and one stacked bar per shift of
 * covered and not-yet-covered hours, each with its table view. Nothing is
 * drawn when no shift has anyone this week.
 *
 * jsdom has no layout, so recharts is replaced with stand-ins that record
 * what each `<Bar>` was given.
 */
vi.mock("recharts", () => {
  const Pass = ({ children }: { children?: ReactNode }) => (
    <div>{children}</div>
  );
  const Nothing = () => null;
  return {
    ResponsiveContainer: Pass,
    BarChart: Pass,
    XAxis: Nothing,
    YAxis: Nothing,
    CartesianGrid: Nothing,
    Tooltip: Nothing,
    Bar: ({ dataKey, stackId }: { dataKey: string; stackId?: string }) => (
      <div data-testid="bar" data-key={dataKey} data-stack={stackId ?? ""} />
    ),
  };
});

const DAYS = [
  "2026-11-02",
  "2026-11-03",
  "2026-11-04",
  "2026-11-05",
  "2026-11-06",
  "2026-11-07",
  "2026-11-08",
];

function coverage(shifts: unknown[]) {
  return { from: DAYS[0], to: DAYS[6], days: DAYS, shifts };
}

function shift(
  publicId: string,
  name: string,
  scheduled: number[],
  planned: number,
  covered: number,
) {
  return {
    public_id: publicId,
    name,
    name_am: null,
    start_time: "08:30",
    end_time: "17:30",
    planned_minutes: planned,
    covered_minutes: covered,
    scheduled_people: scheduled.reduce((a, b) => a + b, 0),
    attended_people: 0,
    by_day: DAYS.map((date, i) => ({
      date,
      scheduled: scheduled[i],
      attended: 0,
    })),
  };
}

function renderCharts() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <ShiftCoverageCharts />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<ShiftCoverageCharts>", () => {
  it("draws the heatmap and one stacked bar per shift, with tables", async () => {
    const user = userEvent.setup();
    server.use(
      http.get("*/api/v1/shifts/coverage", () =>
        HttpResponse.json(
          coverage([
            shift("S1", "Day shift", [4, 4, 4, 4, 4, 0, 0], 20 * 480, 12 * 480),
            shift("S2", "Night shift", [2, 2, 2, 2, 2, 2, 2], 14 * 420, 0),
          ]),
        ),
      ),
    );
    renderCharts();

    expect(
      await screen.findByRole("heading", { name: "People by day" }),
    ).toBeInTheDocument();
    // 2 shifts x 7 days of cells.
    expect(screen.getAllByRole("img")).toHaveLength(14);
    const full = screen.getAllByRole("img", {
      name: /^Day shift, .*: 4 people$/,
    });
    expect(full).toHaveLength(5);
    expect(full[0]).toHaveAttribute("data-level", "5");
    expect(
      screen.getAllByRole("img", { name: /^Day shift, .*: 0 people$/ }),
    ).toHaveLength(2);

    // Covered and not-yet-covered hours share one stack.
    const bars = screen.getAllByTestId("bar");
    expect(bars.map((b) => b.getAttribute("data-key"))).toEqual([
      "covered",
      "uncovered",
    ]);
    expect(new Set(bars.map((b) => b.getAttribute("data-stack")))).toEqual(
      new Set(["hours"]),
    );

    // The hours table says the figures: 20 x 8 h planned, 12 x 8 h covered.
    const toggles = screen.getAllByRole("button", { name: "Show as table" });
    await user.click(toggles[1]);
    const table = screen.getByRole("table", {
      name: "Planned and covered hours",
    });
    expect(
      within(table)
        .getAllByRole("columnheader")
        .map((th) => th.textContent),
    ).toEqual(["Shift", "Planned", "Covered", "Not yet covered"]);
    expect(
      within(table).getByRole("row", { name: "Day shift 160 h 96 h 64 h" }),
    ).toBeInTheDocument();
    expect(
      within(table).getByRole("row", { name: "Night shift 98 h 0 h 98 h" }),
    ).toBeInTheDocument();

    await user.click(toggles[0]);
    const people = screen.getByRole("table", { name: "People by day" });
    expect(within(people).getAllByRole("columnheader")).toHaveLength(8);
    expect(
      within(people).getByRole("row", { name: /^Night shift( 2){7}$/ }),
    ).toBeInTheDocument();
  });

  it("draws nothing when no shift has anyone this week", async () => {
    server.use(
      http.get("*/api/v1/shifts/coverage", () =>
        HttpResponse.json(coverage([])),
      ),
    );
    const { container } = renderCharts();

    await vi.waitFor(() => {
      expect(container.querySelector(".animate-pulse")).toBeNull();
    });
    expect(screen.queryByRole("heading")).toBeNull();
    expect(container.textContent).toBe("");
  });
});
