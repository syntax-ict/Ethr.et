import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import TeamAttendancePage from "@/app/(dashboard)/attendance/team/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

/** Shaped like AttendanceRecordResource. */
function record(id: string, name: string) {
  return {
    public_id: id,
    employee: { public_id: `E-${id}`, name, employee_code: id },
    employee_public_id: `E-${id}`,
    date: "2026-09-29",
    check_in: "2026-09-29T05:30:00+00:00",
    check_out: "2026-09-29T14:00:00+00:00",
    source: "biometric",
    source_label: "Biometric Device",
    confidence_score: 95,
    latitude: null,
    longitude: null,
    geofence_verified: null,
    status: "present",
    status_label: "Present",
    worked_minutes: 510,
    overtime_minutes: 0,
    conflict: null,
    created_at: "2026-09-29T05:30:00+00:00",
    updated_at: "2026-09-29T14:00:00+00:00",
  };
}

function me(role: string) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions: ["attendance.viewTeam"],
    }),
  );
}

function teamPages() {
  return http.get("*/api/v1/attendance/team", ({ request }) => {
    const p = Number(new URL(request.url).searchParams.get("page") ?? 1);
    return HttpResponse.json({
      data: [
        p === 2 ? record("R2", "Zewditu Haile") : record("R1", "Abebe Kebede"),
      ],
      meta: { current_page: p, last_page: 2, per_page: 25, total: 26 },
      links: {},
    });
  });
}

function renderPage() {
  localStorage.setItem("ethr.calendar", "gregorian");
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <CalendarProvider>{children}</CalendarProvider>
      </QueryClientProvider>
    );
  }
  return render(<TeamAttendancePage />, { wrapper: Wrapper });
}

describe("<TeamAttendancePage>", () => {
  it("shows punch times in the tenant's timezone rather than raw ISO strings", async () => {
    server.use(me("supervisor"), teamPages());
    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    // 05:30 UTC is 08:30 in Addis Ababa.
    expect(screen.getByText("08:30")).toBeInTheDocument();
    expect(screen.getByText("17:00")).toBeInTheDocument();
    expect(screen.queryByText("2026-09-29T05:30:00+00:00")).toBeNull();
  });

  it("reaches team members past the first page", async () => {
    // The page asked for 50 rows and rendered no pagination, so anyone past
    // the fiftieth record of the day was never shown.
    server.use(me("supervisor"), teamPages());
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Next" }));
    expect(await screen.findByText("Zewditu Haile")).toBeInTheDocument();
  });

  it("admits a custom role holding attendance.viewTeam", async () => {
    // AttendanceController::team checks attendance.viewTeam; the page was
    // gated on the supervisor role tier instead.
    server.use(me("custom"), teamPages());
    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
  });
});
