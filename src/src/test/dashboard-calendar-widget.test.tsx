import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { CalendarWidget } from "@/features/dashboard/components/calendar-widget";
import { todayEthiopian, toGregorian } from "@/lib/calendar/ethiopian";

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
  // Under the Ethiopian month the Gregorian one read "ኦክቶ 2026", abbreviated
  // (dashboard review, 2026-11-03, PR 10). The full name, in the reader's
  // language, for the Gregorian day the Ethiopian month starts on.
  it("names the secondary Gregorian month in full", async () => {
    localStorage.setItem("locale", "am");
    localStorage.setItem("ethr.calendar", "ethiopian");
    try {
      renderWidget();
      const today = todayEthiopian();
      const expected = new Intl.DateTimeFormat("am", {
        month: "long",
        year: "numeric",
      }).format(toGregorian(today.year, today.month, 1));
      expect(await screen.findByText(expected)).toBeInTheDocument();
    } finally {
      localStorage.setItem("locale", "en");
      localStorage.removeItem("ethr.calendar");
    }
  });

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
