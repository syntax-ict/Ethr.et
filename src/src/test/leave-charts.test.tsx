import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import {
  LeaveCharts,
  leaveDaysPerMonth,
} from "@/features/leave/components/leave-charts";
import type { LeaveBalance, LeaveRequest } from "@/features/leave/api";

/**
 * The Leave page's own charts (charts upgrade, 2026-11-04): days taken by
 * type as sideways bars, used against remaining as a donut, approved leave
 * per month as a line, each with its table view and each hidden when empty.
 */
vi.mock("recharts", () => {
  const Pass = ({ children }: { children?: ReactNode }) => (
    <div>{children}</div>
  );
  const Nothing = () => null;
  return {
    ResponsiveContainer: Pass,
    BarChart: ({
      children,
      layout,
    }: {
      children?: ReactNode;
      layout?: string;
    }) => (
      <div data-testid="bar-chart" data-layout={layout}>
        {children}
      </div>
    ),
    LineChart: ({ children }: { children?: ReactNode }) => (
      <div data-testid="line-chart">{children}</div>
    ),
    PieChart: ({ children }: { children?: ReactNode }) => (
      <div data-testid="pie-chart">{children}</div>
    ),
    Pie: Pass,
    Cell: Nothing,
    Line: Nothing,
    Bar: Nothing,
    XAxis: Nothing,
    YAxis: Nothing,
    CartesianGrid: Nothing,
    Tooltip: Nothing,
  };
});

function request(
  start: string,
  end: string,
  days: number,
  status = "approved",
): LeaveRequest {
  return {
    public_id: `${start}-${end}`,
    leave_type: { name: "Annual Leave", code: "AL", public_id: "LT1" },
    start_date: start,
    end_date: end,
    days,
    reason: null,
    status,
    created_at: start,
  };
}

const BALANCES: LeaveBalance[] = [
  {
    leave_type: { name: "Annual Leave", code: "AL", public_id: "LT1" },
    entitled_days: 20,
    used_days: 6,
    remaining_days: 14,
  },
  {
    leave_type: { name: "Sick Leave", code: "SL", public_id: "LT2" },
    entitled_days: 10,
    used_days: 2.5,
    remaining_days: 7.5,
  },
  {
    leave_type: { name: "Maternity", code: "ML", public_id: "LT3" },
    entitled_days: 0,
    used_days: 0,
    remaining_days: 0,
  },
];

describe("leaveDaysPerMonth", () => {
  it("spreads a request over the months it covers, newest month last", () => {
    const months = leaveDaysPerMonth(
      [
        request("2026-10-30", "2026-11-02", 4),
        request("2026-09-07", "2026-09-08", 2),
      ],
      "gregorian",
      "2026-11-04",
    );
    expect(months).toHaveLength(12);
    expect(months[11]).toEqual({
      key: "2026-11",
      month: { year: 2026, month: 11 },
      days: 2,
    });
    expect(months[10].days).toBe(2);
    expect(months[9].days).toBe(2);
    expect(months[0].key).toBe("2025-12");
  });

  it("counts thirteen months in the Ethiopian calendar", () => {
    // 4 November 2026 is Tikimt 25, 2019; eleven months back, through
    // Pagume, is Tahsas 2018 (month 4), not Hidar as twelve-month years say.
    const months = leaveDaysPerMonth([], "ethiopian", "2026-11-04");
    expect(months[11].month).toEqual({ year: 2019, month: 2 });
    expect(months[9].month).toEqual({ year: 2018, month: 13 });
    expect(months[0].month).toEqual({ year: 2018, month: 4 });
  });
});

function renderCharts(balances: LeaveBalance[]) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <LeaveCharts balances={balances} />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<LeaveCharts>", () => {
  it("draws the three cards with their tables", async () => {
    const user = userEvent.setup();
    server.use(
      http.get("*/api/v1/leave/my", () =>
        HttpResponse.json({
          data: [request("2026-10-05", "2026-10-07", 3)],
          meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
        }),
      ),
    );
    renderCharts(BALANCES);

    expect(
      await screen.findByRole("heading", { name: "Days taken by type" }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("bar-chart")).toHaveAttribute(
      "data-layout",
      "vertical",
    );
    expect(
      screen.getByRole("heading", { name: "Used and remaining" }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("pie-chart")).toBeInTheDocument();
    expect(
      await screen.findByRole("heading", { name: "Leave days per month" }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("line-chart")).toBeInTheDocument();

    const toggles = screen.getAllByRole("button", { name: "Show as table" });
    await user.click(toggles[0]);
    const byType = screen.getByRole("table", { name: "Days taken by type" });
    // The type with no days taken is not a row.
    expect(within(byType).getAllByRole("row")).toHaveLength(3);
    expect(
      within(byType).getByRole("row", { name: "Annual Leave 6" }),
    ).toBeInTheDocument();
    expect(
      within(byType).getByRole("row", { name: "Sick Leave 2.5" }),
    ).toBeInTheDocument();

    await user.click(toggles[1]);
    const donut = screen.getByRole("table", { name: "Used and remaining" });
    expect(
      within(donut).getByRole("row", { name: "Used 8.5" }),
    ).toBeInTheDocument();
    expect(
      within(donut).getByRole("row", { name: "Remaining 21.5" }),
    ).toBeInTheDocument();
  });

  it("draws nothing for someone with no leave at all", async () => {
    server.use(
      http.get("*/api/v1/leave/my", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 },
        }),
      ),
    );
    const { container } = renderCharts([BALANCES[2]]);

    await vi.waitFor(() => {
      expect(container.textContent).toBe("");
    });
    expect(screen.queryByRole("heading")).toBeNull();
  });
});
