import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { PayrollTab } from "@/app/(dashboard)/analytics/payroll-tab";
import { InteractiveCharts } from "@/features/dashboard/components/interactive-charts";
import { periodFor } from "@/app/(dashboard)/analytics/period";

/**
 * Charts upgrade (2026-11-04): a month's payroll cost is one bar, net plus
 * deductions stacked to the gross, and a team's day is one bar, present plus
 * late plus absent. Side by side, the same series read as separate totals.
 *
 * jsdom has no layout, so recharts is replaced with stand-ins that record
 * what each `<Bar>` was given: the `stackId` is the behaviour under test.
 */
vi.mock("recharts", () => {
  const Pass = ({ children }: { children?: ReactNode }) => (
    <div>{children}</div>
  );
  const Nothing = () => null;
  return {
    ResponsiveContainer: Pass,
    BarChart: Pass,
    AreaChart: Pass,
    Area: Nothing,
    XAxis: Nothing,
    YAxis: Nothing,
    CartesianGrid: Nothing,
    Tooltip: Nothing,
    Bar: ({ dataKey, stackId }: { dataKey: string; stackId?: string }) => (
      <div data-testid="bar" data-key={dataKey} data-stack={stackId ?? ""} />
    ),
  };
});

const session = vi.hoisted(() => ({
  isSupervisor: true,
  can: { viewExecutiveDashboard: false } as Record<string, boolean>,
}));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isSupervisor: session.isSupervisor,
    can: session.can,
    isAtLeast: () => false,
    hasRole: () => false,
    role: "supervisor",
    isSuperAdmin: false,
  }),
}));

function renderWithProviders(node: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>{node}</CalendarProvider>
    </QueryClientProvider>,
  );
}

async function bars() {
  const found = await screen.findAllByTestId("bar");
  return found.map((bar) => ({
    key: bar.getAttribute("data-key"),
    stack: bar.getAttribute("data-stack"),
  }));
}

describe("payroll monthly cost", () => {
  it("stacks net and deductions into one bar per month", async () => {
    const user = userEvent.setup();
    server.use(
      http.get("*/api/v1/dashboard/executive/payroll", () =>
        HttpResponse.json({
          totals: null,
          monthly_trend: [
            {
              period: "September 2026",
              gross_cents: 1_000_000,
              net_cents: 700_000,
              tax_cents: 200_000,
            },
          ],
          overtime_trend: [],
          by_department: [],
          by_cost_center: [],
        }),
      ),
      http.get("*/api/v1/dashboard/executive/forecast", () =>
        HttpResponse.json({
          headcount: { history: [], projected: [] },
          payroll_gross: { history: [], projected: [] },
        }),
      ),
    );
    renderWithProviders(
      <PayrollTab
        range={periodFor("last_month", "gregorian", "2026-10-30").current}
      />,
    );

    const drawn = await bars();
    expect(drawn).toEqual([
      { key: "Net", stack: "cost" },
      { key: "Deductions", stack: "cost" },
      { key: "Projected", stack: "" },
    ]);
    // No gross bar beside the stack: the stack's height is the gross.
    expect(drawn.find((b) => b.key === "Gross")).toBeUndefined();

    // The table says the split in figures: deductions are gross less net.
    await user.click(screen.getByRole("button", { name: "Show as table" }));
    expect(
      screen.getAllByRole("columnheader").map((th) => th.textContent),
    ).toEqual(["Month", "Gross", "Net", "Deductions", "Projected gross"]);
    expect(screen.getByRole("row", { name: /10K 7K 3K$/ })).toBeInTheDocument();
  });
});

describe("team attendance this week", () => {
  it("stacks present, late and absent into one bar per day", async () => {
    server.use(
      http.get("*/api/v1/team/attendance/summary", () =>
        HttpResponse.json({
          period: "weekly",
          from: "2026-11-02",
          to: "2026-11-08",
          team_size: 8,
          data: [
            { date: "2026-11-02", present: 6, late: 1, absent: 1, rate: 87.5 },
            { date: "2026-11-03", present: 7, late: 0, absent: 1, rate: 87.5 },
          ],
        }),
      ),
    );
    renderWithProviders(<InteractiveCharts />);

    expect(await bars()).toEqual([
      { key: "present", stack: "day" },
      { key: "late", stack: "day" },
      { key: "absent", stack: "day" },
    ]);
  });
});
