import { describe, it, expect, vi } from "vitest";
import {
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { QuickTab } from "@/features/reports/components/quick-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** `ReportPresets::for()` for a government organisation, recommended first. */
const PRESETS = {
  organisation: { type: "government", workforce_profile: "standard_office" },
  presets: [
    {
      key: "staffing_by_grade",
      source: "employees",
      config: { source: "employees", group_by: "grade" },
      standard: false,
      recommended: true,
    },
    {
      key: "monthly_timesheet",
      source: "timesheet",
      config: { source: "timesheet", sort_by: "employee_name" },
      standard: true,
      recommended: false,
    },
    {
      key: "payroll_by_period",
      source: "payroll",
      config: { source: "payroll", group_by: "period" },
      standard: true,
      recommended: false,
    },
    {
      key: "overtime_by_department",
      source: "timesheet",
      config: { source: "timesheet", group_by: "department" },
      standard: false,
      recommended: false,
    },
  ],
};

function serve(onGenerate: (body: Record<string, unknown>) => void) {
  server.use(
    http.get("*/api/v1/reports/presets", () => HttpResponse.json(PRESETS)),
    http.post("*/api/v1/reports/generate", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      onGenerate(body);
      return HttpResponse.json({
        source: body.source,
        total: 0,
        truncated: false,
        data: [],
        summary: [],
      });
    }),
  );
}

function renderTab() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<QuickTab />, { wrapper });
}

/**
 * The Quick reports tab offered the same four cards to every organisation.
 * It now lists the server's presets: those recommended for the organisation's
 * type and working pattern first, then the standard ones, then the rest.
 */
describe("Reports, quick tab", () => {
  it("puts the reports recommended for the organisation first", async () => {
    serve(() => {});
    renderTab();

    const headings = await screen.findAllByRole("heading", { level: 2 });
    expect(headings.map((h) => h.textContent)).toEqual([
      "Recommended for your organisation",
      "Standard reports",
      "More reports",
    ]);

    const recommended = headings[0].closest("section") as HTMLElement;
    expect(
      within(recommended).getByText("Staffing by grade"),
    ).toBeInTheDocument();
    expect(
      within(recommended).queryByText("Monthly timesheet"),
    ).not.toBeInTheDocument();
  });

  it("runs the timesheet for the chosen month and payroll for its dates", async () => {
    const bodies: Record<string, unknown>[] = [];
    serve((b) => bodies.push(b));
    const user = userEvent.setup();
    renderTab();

    const month = await screen.findByLabelText("Month");
    fireEvent.change(month, { target: { value: "2026-09" } });

    // In list order: staffing, timesheet, payroll, overtime.
    const run = screen.getAllByRole("button", { name: /Run/ });
    await user.click(run[1]);
    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({
      source: "timesheet",
      filters: { month: "2026-09" },
    });

    await user.click(run[2]);
    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[1]).toMatchObject({
      source: "payroll",
      group_by: "period",
      filters: { from: "2026-09-01", to: "2026-09-30" },
    });
  });
});
