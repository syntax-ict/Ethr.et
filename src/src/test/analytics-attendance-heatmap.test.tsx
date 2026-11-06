import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { AttendanceTab } from "@/app/(dashboard)/analytics/attendance-tab";
import { periodFor } from "@/app/(dashboard)/analytics/period";

/**
 * The attendance tab's day-by-hour punch heatmap (charts upgrade,
 * 2026-11-04): seven weekday rows, one column per hour between the first
 * and last punch of the period, and the same figures as a table. No card
 * when the period has no punches.
 */
function renderTab() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(
    <AttendanceTab
      range={periodFor("last_month", "gregorian", "2026-10-30").current}
    />,
    { wrapper },
  );
}

const BASE = {
  daily_trend: [{ date: "2026-10-05", present: 3 }],
  by_department: [],
  by_source: [],
  top_late: [],
};

describe("attendance punches by day and hour", () => {
  it("draws a weekday by hour grid spanning the hours with punches", async () => {
    const user = userEvent.setup();
    server.use(
      http.get("*/api/v1/dashboard/executive/attendance", () =>
        HttpResponse.json({
          ...BASE,
          by_hour: [
            { weekday: 1, hour: 8, count: 12 },
            { weekday: 1, hour: 17, count: 9 },
            { weekday: 3, hour: 9, count: 2 },
          ],
        }),
      ),
    );
    renderTab();

    expect(
      await screen.findByRole("heading", { name: "Punches by day and hour" }),
    ).toBeInTheDocument();
    // 7 weekdays x the 10 hours from 08 to 17.
    expect(screen.getAllByRole("img")).toHaveLength(70);
    expect(
      screen.getByRole("img", { name: "Monday, 08: 12 punches" }),
    ).toHaveAttribute("data-level", "5");
    expect(
      screen.getByRole("img", { name: "Wednesday, 09: 2 punches" }),
    ).toHaveAttribute("data-level", "1");
    expect(
      screen.getByRole("img", { name: "Sunday, 12: 0 punches" }),
    ).toHaveAttribute("data-level", "0");

    const toggles = screen.getAllByRole("button", { name: "Show as table" });
    // The daily trend card comes first; the heatmap card is second.
    await user.click(toggles[1]);
    const table = screen.getByRole("table", {
      name: "Punches by day and hour",
    });
    const headers = within(table)
      .getAllByRole("columnheader")
      .map((th) => th.textContent);
    expect(headers[0]).toBe("Day");
    expect(headers.slice(1)).toEqual([
      "08",
      "09",
      "10",
      "11",
      "12",
      "13",
      "14",
      "15",
      "16",
      "17",
    ]);
    expect(
      within(table).getByRole("row", { name: /^Mon 12 0 0 0 0 0 0 0 0 9$/ }),
    ).toBeInTheDocument();
  });

  it("draws no heatmap card when the period has no punches", async () => {
    server.use(
      http.get("*/api/v1/dashboard/executive/attendance", () =>
        HttpResponse.json({ ...BASE, by_hour: [] }),
      ),
    );
    renderTab();

    expect(
      await screen.findByRole("heading", { name: "Daily Attendance Trend" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("heading", { name: "Punches by day and hour" }),
    ).toBeNull();
  });
});
