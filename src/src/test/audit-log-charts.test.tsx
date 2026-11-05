import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { AuditLogCharts } from "@/components/shared/audit-log-charts";

/**
 * The audit trail's charts (charts upgrade, 2026-11-04): entries per day as
 * a line and the busiest actions as sideways bars, over the list's filters,
 * each with a table view; nothing when the range holds no entries.
 */
vi.mock("recharts", () => {
  const Pass = ({ children }: { children?: ReactNode }) => (
    <div>{children}</div>
  );
  const Nothing = () => null;
  return {
    ResponsiveContainer: Pass,
    LineChart: ({ children }: { children?: ReactNode }) => (
      <div data-testid="line-chart">{children}</div>
    ),
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
    Line: Nothing,
    Bar: Nothing,
    XAxis: ({ allowDecimals }: { allowDecimals?: boolean }) => (
      <div data-testid="x-axis" data-allow-decimals={String(allowDecimals)} />
    ),
    YAxis: ({ allowDecimals }: { allowDecimals?: boolean }) => (
      <div data-testid="y-axis" data-allow-decimals={String(allowDecimals)} />
    ),
    CartesianGrid: Nothing,
    Tooltip: Nothing,
  };
});

function renderCharts() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <AuditLogCharts filters={{ action: "", from: "", to: "" }} />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<AuditLogCharts>", () => {
  it("draws events per day and by action, with whole-number axes and tables", async () => {
    const user = userEvent.setup();
    server.use(
      http.get("*/api/v1/audit-logs/stats", () =>
        HttpResponse.json({
          from: "2026-11-01",
          to: "2026-11-04",
          by_day: [
            { date: "2026-11-02", count: 3 },
            { date: "2026-11-03", count: 1 },
          ],
          by_action: [
            { action: "employee.updated", count: 3 },
            { action: "payroll.approved", count: 1 },
          ],
        }),
      ),
    );
    renderCharts();

    expect(
      await screen.findByRole("heading", { name: "Events per day" }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("line-chart")).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Events by action" }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("bar-chart")).toHaveAttribute(
      "data-layout",
      "vertical",
    );
    // The line's count axis is its Y axis; the sideways bars' is their X axis.
    expect(
      screen.getAllByTestId("y-axis").map((a) => a.dataset.allowDecimals),
    ).toEqual(["false", "undefined"]);
    expect(
      screen.getAllByTestId("x-axis").map((a) => a.dataset.allowDecimals),
    ).toEqual(["undefined", "false"]);

    const toggles = screen.getAllByRole("button", { name: "Show as table" });
    await user.click(toggles[0]);
    const days = screen.getByRole("table", { name: "Events per day" });
    expect(within(days).getAllByRole("row")).toHaveLength(3);
    expect(within(days).getByRole("row", { name: /3$/ })).toBeInTheDocument();

    await user.click(toggles[1]);
    const actions = screen.getByRole("table", { name: "Events by action" });
    expect(within(actions).getAllByRole("row")[1]).toHaveAccessibleName(/3$/);
  });

  it("draws nothing when the range holds no entries", async () => {
    server.use(
      http.get("*/api/v1/audit-logs/stats", () =>
        HttpResponse.json({
          from: "2026-11-01",
          to: "2026-11-04",
          by_day: [],
          by_action: [],
        }),
      ),
    );
    const { container } = renderCharts();

    await vi.waitFor(() => {
      expect(container.querySelector(".animate-pulse")).toBeNull();
    });
    expect(container.textContent).toBe("");
  });
});
