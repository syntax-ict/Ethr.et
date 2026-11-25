import { afterEach, beforeEach, describe, it, expect, vi } from "vitest";
import { act, render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { TimesheetView } from "@/features/reports/timesheet/timesheet-view";
import { TIMESHEET_CODES } from "@/features/reports/timesheet/codes";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { setLocale } from "@/lib/i18n/translations";
import am from "@/lib/i18n/locales/am.json";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: { viewReports: true },
    role: "hr_admin",
  }),
}));

beforeEach(() => {
  window.history.replaceState(
    null,
    "",
    "/reports/timesheet?from=2026-10-12&to=2026-10-14",
  );
  server.use(
    http.get("*/api/v1/departments", () =>
      HttpResponse.json({ data: [], meta: { last_page: 1 } }),
    ),
    http.get("*/api/v1/branches", () =>
      HttpResponse.json({ data: [], meta: { last_page: 1 } }),
    ),
    http.get("*/api/v1/shifts", () =>
      HttpResponse.json({ data: [], meta: { last_page: 1 } }),
    ),
  );
});

afterEach(() => {
  act(() => setLocale("en"));
  localStorage.removeItem("locale");
  localStorage.removeItem("ethr.calendar");
  window.history.replaceState(null, "", "/");
});

function Wrap({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
}

function cell(date: string, code: string | null, short: string | null) {
  return {
    date,
    code,
    short,
    kind: "workday",
    worked: code !== null,
    late: code === "late",
    early: false,
    worked_minutes: 480,
    scheduled_minutes: 480,
    late_minutes: code === "late" ? 40 : 0,
    early_minutes: 0,
    overtime: {},
    records: code ? ["01HREC"] : [],
    locked: false,
    exception: null,
  };
}

const TOTALS = {
  work_days: 3,
  present_days: 2,
  late_days: 1,
  absent_days: 1,
  leave_days: 0,
  holiday_days: 0,
  rest_days: 0,
  missing_days: 0,
  early_days: 0,
  late_minutes: 40,
  early_minutes: 0,
  worked_hours: 16,
  scheduled_hours: 24,
  short_hours: 8,
  overtime_hours: 0,
  overtime_unapproved_hours: 0,
  overtime_limit_days: 0,
  overtime_by_rate: [0, 0, 0, 0],
};

function sheet(over: Record<string, unknown> = {}) {
  return {
    period: { from: "2026-10-12", to: "2026-10-14" },
    days: [
      { date: "2026-10-12", iso_weekday: 1 },
      { date: "2026-10-13", iso_weekday: 2 },
      { date: "2026-10-14", iso_weekday: 3 },
    ],
    data: [
      {
        public_id: "01HEMP",
        employee_code: "E-001",
        name: "Abebe Kebede",
        name_am: "አበበ ከበደ",
        badge_number: null,
        position: "Accountant",
        department: { public_id: "01HDEP", name: "Finance", name_am: null },
        shift: { public_id: "01HSHF", name: "Morning", name_am: null },
        cells: [
          cell("2026-10-12", "present", "P"),
          cell("2026-10-13", "late", "L"),
          cell("2026-10-14", "absent", "A"),
        ],
        totals: TOTALS,
        check_ok: true,
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    links: { next: null, prev: null },
    complete: true,
    max_people: 200,
    totals: TOTALS,
    department_subtotals: [
      {
        department: { public_id: "01HDEP", name: "Finance", name_am: null },
        employees: 1,
        totals: TOTALS,
      },
    ],
    rate_columns: [
      { rate: 1.5, types: ["normal"] },
      { rate: 1.75, types: ["night"] },
      { rate: 2, types: ["rest_day"] },
      { rate: 2.5, types: ["holiday", "holiday_night"] },
    ],
    rules_applied: {
      shifts: [
        {
          public_id: "01HSHF",
          name: "Morning",
          name_am: null,
          start_time: "08:30",
          end_time: "17:30",
          grace_minutes: 15,
          early_departure_minutes: 15,
          break_minutes: 60,
        },
      ],
      merge_window_minutes: 5,
      auto_checkout: { enabled: false, after_minutes: 0 },
      overtime: {
        night_window: { start_hour: 22, end_hour: 6 },
        legal_daily_limit_minutes: 240,
        legal_weekly_limit_minutes: 720,
        requires_approval: false,
      },
    },
    status: {
      code: "exceptions",
      blocking: 2,
      locked_days: 0,
      employed_days: 3,
    },
    with_pay: false,
    ...over,
  };
}

function serve(body: unknown, status = 200) {
  server.use(
    http.get("*/api/v1/reports/timesheet", () =>
      HttpResponse.json(body as Record<string, unknown>, { status }),
    ),
  );
}

describe("Monthly timesheet screen", () => {
  it("shows a cell a day with its code, the status and the totals", async () => {
    serve(sheet());
    render(<TimesheetView />, { wrapper: Wrap });

    expect(await screen.findAllByText("Abebe Kebede")).not.toHaveLength(0);
    const grid = screen.getAllByRole("table")[0];
    expect(
      within(grid).getByLabelText(/2026-10-13: Present, late/),
    ).toHaveTextContent("L");
    expect(within(grid).getByLabelText(/2026-10-14: Absent/)).toHaveTextContent(
      "A",
    );
    expect(screen.getByText(/Exceptions to fix/)).toBeInTheDocument();
    expect(screen.getByText(/2 to fix/)).toBeInTheDocument();
    expect(screen.getByText("Grand total")).toBeInTheDocument();
    // One heading per overtime rate the organisation pays.
    expect(screen.getByText("Overtime ×2.5")).toBeInTheDocument();
  });

  it("lists every code in the legend, with the letters the server prints", async () => {
    serve(sheet());
    render(<TimesheetView />, { wrapper: Wrap });
    await screen.findAllByText("Abebe Kebede");

    const legend = screen.getByText("Codes").closest("section") as HTMLElement;
    for (const { short } of TIMESHEET_CODES) {
      expect(within(legend).getByText(short)).toBeInTheDocument();
    }
    expect(TIMESHEET_CODES.map((c) => c.short)).toEqual([
      "P",
      "L",
      "E",
      "M",
      "A",
      "AL",
      "SL",
      "ML",
      "OL",
      "FD",
      "½",
      "H",
      "R",
    ]);
  });

  it("leaves payable days out unless the API sent the pay figures", async () => {
    serve(sheet({ with_pay: false }));
    const { unmount } = render(<TimesheetView />, { wrapper: Wrap });
    await screen.findAllByText("Abebe Kebede");
    expect(screen.queryByText("Payable days")).not.toBeInTheDocument();
    unmount();

    serve(
      sheet({
        with_pay: true,
        data: [{ ...sheet().data[0], totals: { ...TOTALS, payable_days: 3 } }],
      }),
    );
    render(<TimesheetView />, { wrapper: Wrap });
    expect(await screen.findByText("Payable days")).toBeInTheDocument();
  });

  it("marks a row that does not add up", async () => {
    serve(sheet({ data: [{ ...sheet().data[0], check_ok: false }] }));
    render(<TimesheetView />, { wrapper: Wrap });
    await screen.findAllByText("Abebe Kebede");
    expect(
      screen.getAllByText("This row does not add up").length,
    ).toBeGreaterThan(0);
  });

  it("says why there are no totals past the size limit, and shows no status", async () => {
    serve(
      sheet({
        complete: false,
        totals: null,
        department_subtotals: [],
        status: null,
      }),
    );
    render(<TimesheetView />, { wrapper: Wrap });
    await screen.findAllByText("Abebe Kebede");
    expect(
      screen.getByText(/shown for 200 people or fewer/),
    ).toBeInTheDocument();
    expect(screen.queryByText("Grand total")).not.toBeInTheDocument();
    expect(
      screen.getByText("No status for a list this long"),
    ).toBeInTheDocument();
  });

  it("opens the day's drawer from a cell, with what was scheduled and worked", async () => {
    serve(sheet());
    server.use(
      http.get("*/api/v1/attendance", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "01HREC",
              employee_public_id: "01HEMP",
              date: "2026-10-13",
              check_in: "2026-10-13T06:10:00Z",
              check_out: "2026-10-13T14:30:00Z",
              source: "web",
              source_label: "Web Portal",
              status: "late",
              status_label: "Late",
              worked_minutes: 490,
              overtime_minutes: 0,
              punches: [],
            },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
        }),
      ),
    );
    const user = userEvent.setup();
    render(<TimesheetView />, { wrapper: Wrap });
    await screen.findAllByText("Abebe Kebede");

    await user.click(
      within(screen.getAllByRole("table")[0]).getByLabelText(
        /2026-10-13: Present, late/,
      ),
    );

    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Present, late")).toBeInTheDocument();
    expect(within(dialog).getByText("Late by (minutes)")).toBeInTheDocument();
    expect(await within(dialog).findByText(/Web Portal/)).toBeInTheDocument();
    expect(
      within(dialog).getByRole("link", { name: "Request a correction" }),
    ).toHaveAttribute("href", "/attendance/corrections");
  });

  it("does not offer a correction for a day payroll has paid", async () => {
    const paid = sheet();
    paid.data[0].cells[1] = { ...paid.data[0].cells[1], locked: true };
    serve(paid);
    const user = userEvent.setup();
    render(<TimesheetView />, { wrapper: Wrap });
    await screen.findAllByText("Abebe Kebede");

    await user.click(
      within(screen.getAllByRole("table")[0]).getByLabelText(
        /2026-10-13: Present, late/,
      ),
    );
    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText(/Payroll has paid this day/),
    ).toBeInTheDocument();
    expect(
      within(dialog).queryByRole("link", { name: "Request a correction" }),
    ).not.toBeInTheDocument();
  });

  it("shows an empty state, an error with a retry, and a skeleton while loading", async () => {
    serve(
      sheet({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
      }),
    );
    const first = render(<TimesheetView />, { wrapper: Wrap });
    expect(
      await screen.findByText(/No one has a day on the timesheet/),
    ).toBeInTheDocument();
    first.unmount();

    serve({ type: "error", title: "Server error", status: 500 }, 500);
    render(<TimesheetView />, { wrapper: Wrap });
    expect(
      await screen.findByText("The timesheet could not be loaded"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /retry|try again/i }),
    ).toBeInTheDocument();
  });

  it("reads in Amharic, with the person's Amharic name", async () => {
    act(() => setLocale("am"));
    serve(sheet());
    render(<TimesheetView />, { wrapper: Wrap });

    await waitFor(() =>
      expect(
        screen.getAllByText(new RegExp(am["reports_page.timesheet.title"]))
          .length,
      ).toBeGreaterThan(0),
    );
    expect((await screen.findAllByText(/አበበ ከበደ/)).length).toBeGreaterThan(0);
    expect(
      screen.getByText(am["reports_page.timesheet.grand_total"]),
    ).toBeInTheDocument();
  });

  it("keeps the month in the address, so a reload opens the same sheet", async () => {
    let asked: URLSearchParams | null = null;
    server.use(
      http.get("*/api/v1/reports/timesheet", ({ request }) => {
        asked = new URL(request.url).searchParams;
        return HttpResponse.json(sheet());
      }),
    );
    render(<TimesheetView />, { wrapper: Wrap });
    await screen.findAllByText("Abebe Kebede");

    expect(asked).not.toBeNull();
    expect(asked!.get("from")).toBe("2026-10-12");
    expect(asked!.get("to")).toBe("2026-10-14");
    expect(asked!.get("per_page")).toBe("25");
  });
});
