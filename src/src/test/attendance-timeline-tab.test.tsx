import { describe, it, expect } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AttendanceTimelineTab } from "@/features/employees/components/attendance-timeline-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const TIMELINE_URL = `*/api/v1/employees/${EMPLOYEE_ID}/attendance/timeline`;

/** The shape `EmployeeAttendanceTimelineController` returns. */
function buildTimeline() {
  return {
    employee: { public_id: EMPLOYEE_ID, name: "Abebe Kebede" },
    range: { from: "2026-01-05", to: "2026-01-06" },
    totals: { present: 1, late: 0, absent: 1, total_minutes_worked: 480 },
    days: [
      {
        date: "2026-01-05",
        status: "present",
        check_in: "08:00:00",
        check_out: "16:00:00",
        worked_minutes: 480,
        source: "biometric",
        late_minutes: null,
        is_weekend: false,
      },
      {
        date: "2026-01-06",
        status: "absent",
        check_in: null,
        check_out: null,
        worked_minutes: null,
        source: null,
        late_minutes: null,
        is_weekend: false,
      },
    ],
  };
}

function renderTab() {
  localStorage.setItem("ethr.calendar", "gregorian");
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<AttendanceTimelineTab employeeId={EMPLOYEE_ID} />, {
    wrapper: Wrapper,
  });
}

describe("<AttendanceTimelineTab>", () => {
  it("asks for the selected range and shows its totals", async () => {
    const seen: URLSearchParams[] = [];
    server.use(
      http.get(TIMELINE_URL, ({ request }) => {
        seen.push(new URL(request.url).searchParams);
        return HttpResponse.json(buildTimeline());
      }),
    );
    renderTab();

    expect(await screen.findByText("8.0")).toBeInTheDocument();
    expect(screen.getByText("2026-01-05 to 2026-01-06")).toBeInTheDocument();
    await waitFor(() => expect(seen).toHaveLength(1));
    expect(seen[0].get("from")).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(seen[0].get("to")).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it("reports a failed load and retries it, instead of a skeleton forever", async () => {
    // Regression: `isLoading || !data` rendered the skeleton, and after a
    // failure `data` never arrives — so the tab looked like it was loading
    // indefinitely, with no message and no retry.
    let calls = 0;
    server.use(
      http.get(TIMELINE_URL, () => {
        calls++;
        return calls === 1
          ? new HttpResponse(null, { status: 500 })
          : HttpResponse.json(buildTimeline());
      }),
    );
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByRole("button", { name: /Try again/ }));
    expect(await screen.findByText("8.0")).toBeInTheDocument();
  });
});
