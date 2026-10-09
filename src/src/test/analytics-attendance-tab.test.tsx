import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { AttendanceTab } from "@/app/(dashboard)/analytics/attendance-tab";

/**
 * Every section of the tab hides itself when it has no rows, and the tab's
 * empty state was switched off on the belief that it could therefore never be
 * empty. With no attendance in the period it rendered an empty grid — no
 * chart, no message (found browser-testing N13).
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
  return render(<AttendanceTab />, { wrapper });
}

const EMPTY = {
  daily_trend: [],
  by_department: [],
  by_source: [],
  top_late: [],
};

describe("<AttendanceTab>", () => {
  it("says there is no attendance rather than rendering nothing", async () => {
    server.use(
      http.get("*/api/v1/dashboard/executive/attendance", () =>
        HttpResponse.json(EMPTY),
      ),
    );
    renderTab();

    expect(
      await screen.findByText("No attendance in this period"),
    ).toBeInTheDocument();
  });

  it("shows the charts when there is data", async () => {
    server.use(
      http.get("*/api/v1/dashboard/executive/attendance", () =>
        HttpResponse.json({
          ...EMPTY,
          by_source: [{ source: "biometric", count: 12 }],
        }),
      ),
    );
    renderTab();

    expect(await screen.findByText(/source/i)).toBeInTheDocument();
    expect(
      screen.queryByText("No attendance in this period"),
    ).not.toBeInTheDocument();
  });
});
