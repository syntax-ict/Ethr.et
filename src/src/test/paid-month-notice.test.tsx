import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PaidMonthNotice } from "@/features/employees/components/paid-month-notice";
import { EmployeeHistoryTab } from "@/features/employees/components/employee-history-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

/**
 * A date that reaches payroll months already approved or paid says so, under
 * the date field and before saving. A notice only: nothing here blocks submit.
 */
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ can: { recordPersonnelAction: true } }),
}));
vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const PAID_URL = "*/api/v1/employees/e1/paid-months";

function paid(count: number, start: string | null, end: string | null) {
  return http.get(PAID_URL, () =>
    HttpResponse.json({
      data: { count, earliest_period_start: start, latest_period_end: end },
    }),
  );
}

function wrapper() {
  // The date field defaults to Ethiopian entry unless a preference is stored.
  localStorage.setItem("ethr.calendar", "gregorian");
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={client}>
        <CalendarProvider>{children}</CalendarProvider>
      </QueryClientProvider>
    );
  };
}

describe("<PaidMonthNotice>", () => {
  it("shows nothing when the date reaches no paid month", async () => {
    server.use(paid(0, null, null));
    const { container } = render(
      <PaidMonthNotice employeeId="e1" from="2026-11-20" />,
      { wrapper: wrapper() },
    );

    await waitFor(() => expect(container).toBeEmptyDOMElement());
    expect(screen.queryByTestId("paid-month-notice")).not.toBeInTheDocument();
  });

  it("names the months reached and where finance will see the difference", async () => {
    server.use(paid(2, "2026-09-01", "2026-10-31"));
    render(<PaidMonthNotice employeeId="e1" from="2026-09-15" />, {
      wrapper: wrapper(),
    });

    const notice = await screen.findByTestId("paid-month-notice");
    expect(notice).toHaveTextContent(
      "This date reaches 2 months already paid, from 01 Sept 2026 to 31 Oct 2026.",
    );
    expect(notice).toHaveTextContent("Payroll, Pay differences");
  });

  it("says month, not months, for one", async () => {
    server.use(paid(1, "2026-10-01", "2026-10-31"));
    render(<PaidMonthNotice employeeId="e1" from="2026-10-15" />, {
      wrapper: wrapper(),
    });

    expect(await screen.findByTestId("paid-month-notice")).toHaveTextContent(
      "This date reaches 1 month already paid",
    );
  });

  it("does not ask while the date is incomplete or the form cannot change pay", async () => {
    let asked = 0;
    server.use(
      http.get(PAID_URL, () => {
        asked++;
        return HttpResponse.json({
          data: {
            count: 1,
            earliest_period_start: "2026-10-01",
            latest_period_end: "2026-10-31",
          },
        });
      }),
    );
    const { rerender } = render(
      <PaidMonthNotice employeeId="e1" from="2026-10" />,
      { wrapper: wrapper() },
    );
    rerender(
      <PaidMonthNotice employeeId="e1" from="2026-10-15" enabled={false} />,
    );

    await new Promise((r) => setTimeout(r, 50));
    expect(asked).toBe(0);
    expect(screen.queryByTestId("paid-month-notice")).not.toBeInTheDocument();
  });

  it("says so, in one muted line, when the check fails", async () => {
    server.use(
      http.get(PAID_URL, () => new HttpResponse(null, { status: 500 })),
    );
    render(<PaidMonthNotice employeeId="e1" from="2026-10-15" />, {
      wrapper: wrapper(),
    });

    expect(
      await screen.findByText(
        "Could not check whether this date reaches a month already paid.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("paid-month-notice")).not.toBeInTheDocument();
  });
});

describe("the personnel action form", () => {
  it("warns about a paid month once a salary change is dated into it, and still lets it be saved", async () => {
    let posted: Record<string, unknown> | null = null;
    server.use(
      http.get("*/employees/e1/personnel-actions", () => HttpResponse.json([])),
      paid(1, "2026-10-01", "2026-10-31"),
      http.post("*/employees/e1/personnel-actions", async ({ request }) => {
        posted = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          {
            public_id: "pa1",
            effective_date: "2026-10-10",
            is_scheduled: false,
          },
          { status: 201 },
        );
      }),
    );
    const user = userEvent.setup();
    render(<EmployeeHistoryTab employeeId="e1" />, { wrapper: wrapper() });

    await user.click(
      await screen.findByRole("button", { name: /record action/i }),
    );
    const dialog = await screen.findByRole("dialog");

    await user.type(
      within(dialog).getByLabelText(/^Effective date/),
      "2026-10-10",
    );
    // Only a date: nothing changes pay yet, so nothing is said.
    await new Promise((r) => setTimeout(r, 50));
    expect(screen.queryByTestId("paid-month-notice")).not.toBeInTheDocument();

    await user.type(within(dialog).getByLabelText(/New salary/), "20000");
    expect(await screen.findByTestId("paid-month-notice")).toHaveTextContent(
      "This date reaches 1 month already paid",
    );

    await user.click(within(dialog).getByRole("button", { name: /^record/i }));
    await waitFor(() =>
      expect(posted).toMatchObject({ effective_date: "2026-10-10" }),
    );
  });
});
