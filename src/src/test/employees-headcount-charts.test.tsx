import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { HeadcountCharts } from "@/features/employees/components/headcount-charts";

/**
 * The people list's header charts (charts upgrade, 2026-11-04): headcount by
 * department as sideways bars and the gender split as a donut, from the
 * executive overview and workforce figures, only for a reader who may see
 * them.
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
    PieChart: ({ children }: { children?: ReactNode }) => (
      <div data-testid="pie-chart">{children}</div>
    ),
    Pie: Pass,
    Cell: Nothing,
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

const session = vi.hoisted(() => ({ executive: true }));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: { viewExecutiveDashboard: session.executive },
    isSupervisor: false,
    isAtLeast: () => false,
    hasRole: () => false,
    role: "tenant_admin",
    isSuperAdmin: false,
  }),
}));

function renderCharts() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <HeadcountCharts />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<HeadcountCharts>", () => {
  it("draws departments sideways, largest first, and the gender donut", async () => {
    const user = userEvent.setup();
    session.executive = true;
    server.use(
      http.get("*/api/v1/dashboard/executive", () =>
        HttpResponse.json({
          headcount: {
            total: 9,
            active: 9,
            by_department: [
              { department: "Finance", department_public_id: "D1", count: 3 },
              { department: "Sales", department_public_id: "D2", count: 6 },
            ],
          },
          attendance_rate: { today: null, period: null },
          payroll_summary: { net_cents: 0 },
          turnover: { rate: 0, exits: 0 },
          workforce_growth: [],
          leave_utilization: null,
        }),
      ),
      http.get("*/api/v1/dashboard/executive/workforce", () =>
        HttpResponse.json({
          headcount_trend: [],
          by_gender: [
            { gender: "female", count: 4 },
            { gender: "male", count: 5 },
          ],
          by_tenure: [],
        }),
      ),
    );
    renderCharts();

    expect(
      await screen.findByRole("heading", { name: "Headcount by Department" }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("bar-chart")).toHaveAttribute(
      "data-layout",
      "vertical",
    );
    // Sideways, the count axis is the X axis: whole people only.
    expect(screen.getByTestId("x-axis")).toHaveAttribute(
      "data-allow-decimals",
      "false",
    );
    expect(
      await screen.findByRole("heading", { name: "Gender Distribution" }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("pie-chart")).toBeInTheDocument();
    expect(screen.getByText("9")).toBeInTheDocument();

    const toggles = screen.getAllByRole("button", { name: "Show as table" });
    await user.click(toggles[0]);
    const table = screen.getByRole("table", {
      name: "Headcount by Department",
    });
    const rows = within(table).getAllByRole("row");
    expect(rows[1]).toHaveAccessibleName("Sales 6");
    expect(rows[2]).toHaveAccessibleName("Finance 3");
  });

  it("draws nothing for a reader without the executive dashboard", () => {
    session.executive = false;
    const { container } = renderCharts();
    expect(container.textContent).toBe("");
  });
});
