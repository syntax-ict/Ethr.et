import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { OverviewTab } from "@/app/(dashboard)/analytics/overview-tab";
import { WorkforceTab } from "@/app/(dashboard)/analytics/workforce-tab";
import { AttendanceTab } from "@/app/(dashboard)/analytics/attendance-tab";

/**
 * Audit N34: "Hires Over Time" labelled its Y axis 0, 0.75, 1.5 — fractions of
 * a person. Every chart on the analytics tabs that counts people must ask
 * Recharts for whole-number ticks.
 *
 * jsdom has no layout, so a real ResponsiveContainer renders nothing at all.
 * Recharts is replaced with pass-through stand-ins that record what each
 * `YAxis` was given — the prop is the behaviour under test, and Recharts'
 * own tick maths is not ours to re-test.
 */
vi.mock("recharts", () => {
  const Pass = ({ children }: { children?: ReactNode }) => (
    <div>{children}</div>
  );
  const Nothing = () => null;
  return {
    ResponsiveContainer: Pass,
    LineChart: Pass,
    BarChart: Pass,
    PieChart: Pass,
    Pie: Pass,
    Cell: Nothing,
    Line: Nothing,
    Bar: Nothing,
    XAxis: Nothing,
    CartesianGrid: Nothing,
    Tooltip: Nothing,
    Legend: Nothing,
    YAxis: ({ allowDecimals }: { allowDecimals?: boolean }) => (
      <div
        data-testid="y-axis"
        data-allow-decimals={String(allowDecimals ?? true)}
      />
    ),
  };
});

// Both fetch on their own and are covered elsewhere; neither draws an axis.
vi.mock("@/features/dashboard/components/compliance-card", () => ({
  ComplianceCard: () => null,
}));
vi.mock("@/features/dashboard/components/department-drilldown-dialog", () => ({
  DepartmentDrillDownDialog: () => null,
}));

function renderTab(tab: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{tab}</QueryClientProvider>,
  );
}

async function yAxes(expected: number) {
  const axes = await screen.findAllByTestId("y-axis");
  expect(axes).toHaveLength(expected);
  return axes.map((axis) => axis.getAttribute("data-allow-decimals"));
}

describe("analytics head-count charts", () => {
  it("overview: headcount by department and hires over time use whole numbers", async () => {
    server.use(
      http.get("*/api/v1/dashboard/executive", () =>
        HttpResponse.json({
          headcount: {
            total: 3,
            active: 3,
            by_department: [
              { department: "Finance", department_public_id: "D1", count: 3 },
            ],
          },
          attendance_rate: { today: 100, period: 100 },
          payroll_summary: { net_cents: 0 },
          turnover: { rate: 0, exits: 0 },
          workforce_growth: [
            { month: "2026-08", hires: 1 },
            { month: "2026-09", hires: 2 },
          ],
          leave_utilization: null,
        }),
      ),
    );
    renderTab(<OverviewTab />);

    expect(await yAxes(2)).toEqual(["false", "false"]);
  });

  it("workforce: headcount trend and tenure use whole numbers", async () => {
    server.use(
      http.get("*/api/v1/dashboard/executive/workforce", () =>
        HttpResponse.json({
          headcount_trend: [
            { month: "2026-08", count: 2 },
            { month: "2026-09", count: 3 },
          ],
          by_gender: [],
          by_tenure: [{ bucket: "0-1y", count: 3 }],
        }),
      ),
      http.get("*/api/v1/dashboard/executive/forecast", () =>
        HttpResponse.json({
          headcount: { history: [], projected: [] },
          payroll_gross: { history: [], projected: [] },
        }),
      ),
    );
    renderTab(<WorkforceTab />);

    expect(await yAxes(2)).toEqual(["false", "false"]);
  });

  it("attendance: people present per day uses whole numbers", async () => {
    server.use(
      http.get("*/api/v1/dashboard/executive/attendance", () =>
        HttpResponse.json({
          daily_trend: [{ date: "2026-09-01", present: 2 }],
          by_department: [],
          by_source: [],
          top_late: [],
        }),
      ),
    );
    renderTab(<AttendanceTab />);

    expect(await yAxes(1)).toEqual(["false"]);
  });
});
