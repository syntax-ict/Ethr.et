import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AttendancePage from "@/app/(dashboard)/attendance/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like AttendanceRecordResource. */
const RECORD = {
  public_id: "01HZATT0000000000000000001",
  employee: {
    public_id: "01HZEMP0000000000000000001",
    name: "Abebe Kebede",
    employee_code: "EMP-0001",
  },
  employee_public_id: "01HZEMP0000000000000000001",
  date: "2026-09-29",
  check_in: "2026-09-29T05:30:00+00:00",
  check_out: "2026-09-29T14:00:00+00:00",
  source: "web",
  source_label: "Web Portal",
  confidence_score: 80,
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

const page = (rows: unknown[], current = 1, last = 1) => ({
  data: rows,
  meta: {
    current_page: current,
    last_page: last,
    per_page: 100,
    total: rows.length,
    from: 1,
    to: rows.length,
  },
  links: {},
});

const forbidden = () =>
  HttpResponse.json(
    { type: "about:blank", title: "Forbidden", status: 403, detail: "" },
    { status: 403 },
  );

function me(permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role: "employee" },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
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
  return render(<AttendancePage />, { wrapper: Wrapper });
}

describe("<AttendancePage> records", () => {
  it("shows an employee their own records rather than a 403 dressed as an empty list", async () => {
    // GET /attendance requires attendance.viewAll. The page called it for
    // everyone, so an employee saw "No attendance records" under the Check In
    // button they had just pressed.
    server.use(
      me(["attendance.viewOwn", "attendance.checkIn"]),
      http.get("*/api/v1/attendance", forbidden),
      http.get("*/api/v1/attendance/my", () =>
        HttpResponse.json(page([RECORD])),
      ),
    );
    renderPage();

    expect(await screen.findByText("2026-09-29")).toBeInTheDocument();
    expect(screen.queryByText("No attendance records")).toBeNull();
  });

  it("reads the full list for a holder of attendance.viewAll, with the employee column", async () => {
    let myCalls = 0;
    server.use(
      me(["attendance.viewAll"]),
      http.get("*/api/v1/attendance", () => HttpResponse.json(page([RECORD]))),
      http.get("*/api/v1/attendance/my", () => {
        myCalls++;
        return HttpResponse.json(page([]));
      }),
    );
    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(myCalls).toBe(0);
  });
});

describe("<AttendancePage> permissions and navigation", () => {
  beforeEach(() => {
    server.use(
      http.get("*/api/v1/attendance", () => HttpResponse.json(page([]))),
      http.get("*/api/v1/attendance/my", () => HttpResponse.json(page([]))),
    );
  });

  it("offers Manual Entry to a role holding attendance.manage without employee.create", async () => {
    // ManualAttendanceController checks attendance.manage; the button was
    // gated on employee.create, which only coincides on the built-in roles.
    server.use(me(["attendance.viewAll", "attendance.manage"]));
    renderPage();

    expect(
      await screen.findByRole("button", { name: /Manual Entry/ }),
    ).toBeInTheDocument();
  });

  it("hides Manual Entry from a role that can create employees but not manage attendance", async () => {
    server.use(me(["attendance.viewAll", "employee.create"]));
    renderPage();

    await screen.findByRole("link", { name: /Intelligence/ });
    expect(screen.queryByRole("button", { name: /Manual Entry/ })).toBeNull();
  });

  it("links Kiosks to the kiosk management page, not the kiosk terminal", async () => {
    // /kiosk is the terminal, whose setup screen asks for a token that only
    // /attendance/kiosks can issue — and nothing else linked there.
    server.use(me(["attendance.manage"]));
    renderPage();

    const link = await screen.findByRole("link", { name: /Kiosks/ });
    expect(link).toHaveAttribute("href", "/attendance/kiosks");
  });
});

describe("<AttendancePage> manual entry", () => {
  it("lists employees past the first page in the picker", async () => {
    // The picker took one page of 100 as the whole list, so a tenant's 101st
    // employee could not have attendance entered for them.
    server.use(
      me(["attendance.viewAll", "attendance.manage", "employee.create"]),
      http.get("*/api/v1/attendance", () => HttpResponse.json(page([]))),
      http.get("*/api/v1/employees", ({ request }) => {
        const p = Number(new URL(request.url).searchParams.get("page") ?? 1);
        return HttpResponse.json(
          p === 1
            ? page(
                [
                  {
                    public_id: "E1",
                    name: "Abebe Kebede",
                    employee_code: "A1",
                  },
                ],
                1,
                2,
              )
            : page(
                [
                  {
                    public_id: "E2",
                    name: "Zewditu Haile",
                    employee_code: "Z1",
                  },
                ],
                2,
                2,
              ),
        );
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: /Manual Entry/ }),
    );
    const picker = await screen.findByRole("combobox", { name: /Employee/ });
    await waitFor(async () => {
      await user.click(picker);
      expect(
        await screen.findByRole("option", { name: /Zewditu Haile/ }),
      ).toBeInTheDocument();
    });
  });
});
