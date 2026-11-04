import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { CalendarWidget } from "@/features/dashboard/components/calendar-widget";

/**
 * The month arrows are the smallest controls on the dashboard. jsdom lays
 * nothing out, so the phone size is pinned by the classes that make it: 44 px
 * (the `touch` token, WCAG 2.5.8) below `sm`, the tighter header size above.
 */
function renderWidget() {
  server.use(
    http.get("*/api/v1/dashboard/employee", () =>
      HttpResponse.json({
        attendance_today: { status: "not_checked_in" },
        leave_balances: [],
        latest_payslip: null,
        upcoming_holidays: [],
        pending_approvals: 0,
      }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <CalendarWidget />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

describe("<CalendarWidget>", () => {
  it("gives the month arrows a 44 px target on a phone and the header size from sm", async () => {
    renderWidget();

    const previous = await screen.findByRole("button", {
      name: "Previous month",
    });
    const next = screen.getByRole("button", { name: "Next month" });
    for (const arrow of [previous, next]) {
      expect(arrow).toHaveClass("h-touch", "w-touch", "sm:h-7", "sm:w-7");
    }
  });
});
