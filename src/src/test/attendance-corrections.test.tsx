import { describe, it, expect, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import CorrectionsPage from "@/app/(dashboard)/attendance/corrections/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { zonedWallTimeToUtcIso } from "@/features/attendance/time";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like AttendanceRecordResource. */
const RECORD = {
  public_id: "01HZATT0000000000000000001",
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
  status: "late",
  status_label: "Late",
  worked_minutes: 510,
  overtime_minutes: 0,
  conflict: null,
  created_at: "2026-09-29T05:30:00+00:00",
  updated_at: "2026-09-29T14:00:00+00:00",
};

/** Shaped like AttendanceCorrectionResource — no `date`, no `corrected_*`. */
const CORRECTION = {
  public_id: "01HZCOR0000000000000000001",
  attendance_record: RECORD,
  attendance_record_public_id: RECORD.public_id,
  employee: {
    public_id: "01HZEMP0000000000000000001",
    name: "Abebe Kebede",
    employee_code: "EMP-0001",
  },
  employee_public_id: "01HZEMP0000000000000000001",
  reason: "Badge reader was offline",
  proposed_check_in: "2026-09-29T05:00:00+00:00",
  proposed_check_out: null,
  status: "pending",
  approval_chain: [],
  created_at: "2026-09-29T15:00:00+00:00",
  updated_at: "2026-09-29T15:00:00+00:00",
};

const page = (rows: unknown[]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: rows.length },
  links: {},
});

function me(role: string, permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
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
  return render(<CorrectionsPage />, { wrapper: Wrapper });
}

describe("zonedWallTimeToUtcIso", () => {
  it("reads a wall-clock time in the tenant zone as the UTC instant it means", () => {
    expect(
      zonedWallTimeToUtcIso("2026-09-29", "08:00", "Africa/Addis_Ababa"),
    ).toBe("2026-09-29T05:00:00Z");
    expect(
      zonedWallTimeToUtcIso("2026-09-29", "01:30", "Africa/Addis_Ababa"),
    ).toBe("2026-09-28T22:30:00Z");
    expect(
      zonedWallTimeToUtcIso("2026-09-29", "", "Africa/Addis_Ababa"),
    ).toBeNull();
  });
});

describe("<CorrectionsPage> request", () => {
  it("submits a correction against the caller's own record with the fields the API reads", async () => {
    // StoreCorrectionRequest requires attendance_record_public_id and reads
    // proposed_check_in/out. The dialog posted date + corrected_check_in/out,
    // so every request was a 422 and no correction could ever be submitted.
    let body: Record<string, unknown> | null = null;
    server.use(
      me("employee", ["correction.create", "attendance.viewOwn"]),
      http.get("*/api/v1/attendance/my", ({ request }) => {
        const from = new URL(request.url).searchParams.get("date_from");
        return HttpResponse.json(page(from === "2026-09-29" ? [RECORD] : []));
      }),
      http.post("*/api/v1/attendance/corrections", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(CORRECTION, { status: 201 });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: /Request Correction/ }),
    );
    const dialog = await screen.findByRole("dialog");
    fireEvent.change(dialog.querySelector('input[type="date"]')!, {
      target: { value: "2026-09-29" },
    });
    fireEvent.change(screen.getByLabelText("Correct Check In"), {
      target: { value: "08:00" },
    });
    fireEvent.change(screen.getByLabelText("Correct Check Out"), {
      target: { value: "17:30" },
    });
    await user.type(
      screen.getByLabelText(/Reason/),
      "Badge reader was offline",
    );

    const submit = screen.getByRole("button", { name: /Submit Request/ });
    await waitFor(() => expect(submit).toBeEnabled());
    await user.click(submit);

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({
      attendance_record_public_id: RECORD.public_id,
      // 08:00 and 17:30 in Addis Ababa (UTC+3), as UTC instants.
      proposed_check_in: "2026-09-29T05:00:00Z",
      proposed_check_out: "2026-09-29T14:30:00Z",
      reason: "Badge reader was offline",
    });
  });
});

describe("<CorrectionsPage> lists", () => {
  it("shows the record's date and punches in the review list", async () => {
    // The card read `date`, `original_check_in` and `corrected_check_in`,
    // none of which the resource sends, so every field rendered "—".
    server.use(
      me("supervisor", ["correction.viewPending", "correction.approve"]),
      http.get("*/api/v1/attendance/corrections/pending", () =>
        HttpResponse.json(page([CORRECTION])),
      ),
      http.get("*/api/v1/attendance/corrections/:id/payroll-impact", () =>
        HttpResponse.json({
          original_hours: 8.5,
          proposed_hours: 9,
          difference_minutes: 30,
          estimated_impact_cents: 5000,
          hourly_rate_cents: 10000,
          in_open_payroll_period: true,
          currency: "ETB",
        }),
      ),
    );
    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText("2026-09-29")).toBeInTheDocument();
    // Original 08:30 → 17:00 EAT; requested check-in 08:00, check-out unchanged.
    expect(screen.getByText("08:30 → 17:00")).toBeInTheDocument();
    expect(screen.getByText("08:00 → —")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Approve/ })).toBeInTheDocument();
  });

  it("does not request the tenant-wide list for an employee who may not read it", async () => {
    // GET /attendance/corrections requires correction.viewAll. It was called
    // for everyone as "My Requests" — a 403 shown as "No correction requests".
    let allCalls = 0;
    server.use(
      me("employee", ["correction.create", "attendance.viewOwn"]),
      http.get("*/api/v1/attendance/corrections", () => {
        allCalls++;
        return HttpResponse.json(
          { title: "Forbidden", status: 403 },
          { status: 403 },
        );
      }),
    );
    renderPage();

    await screen.findByRole("button", { name: /Request Correction/ });
    await new Promise((r) => setTimeout(r, 300));
    expect(allCalls).toBe(0);
    expect(screen.queryByRole("tablist")).toBeNull();
  });

  it("lists an employee's own corrections from the endpoint that serves them", async () => {
    // "My Requests" had no endpoint: the page could either call the
    // tenant-wide list (403 for an employee) or offer nothing. It now reads
    // GET /attendance/corrections/my, gated on correction.viewOwn.
    let allCalls = 0;
    let myCalls = 0;
    server.use(
      me("employee", [
        "correction.create",
        "correction.viewOwn",
        "attendance.viewOwn",
      ]),
      http.get("*/api/v1/attendance/corrections", () => {
        allCalls++;
        return HttpResponse.json(page([]));
      }),
      http.get("*/api/v1/attendance/corrections/my", () => {
        myCalls++;
        return HttpResponse.json(page([{ ...CORRECTION, status: "rejected" }]));
      }),
    );
    renderPage();

    expect(
      await screen.findByRole("tab", { name: "My Requests" }),
    ).toBeInTheDocument();
    expect(
      await screen.findByText("Badge reader was offline"),
    ).toBeInTheDocument();
    expect(screen.getByText("2026-09-29")).toBeInTheDocument();
    // Every row is the caller's own, so the employee column is not shown.
    expect(screen.queryByText("Abebe Kebede")).toBeNull();
    expect(screen.getAllByRole("tab")).toHaveLength(1);
    expect(myCalls).toBeGreaterThan(0);
    expect(allCalls).toBe(0);
  });

  it("hides approve and reject from a reviewer who may not decide", async () => {
    server.use(
      me("custom", ["correction.viewPending"]),
      http.get("*/api/v1/attendance/corrections/pending", () =>
        HttpResponse.json(page([CORRECTION])),
      ),
      http.get("*/api/v1/attendance/corrections/:id/payroll-impact", () =>
        HttpResponse.json({
          original_hours: 8.5,
          proposed_hours: 8.5,
          difference_minutes: 0,
          estimated_impact_cents: 0,
          hourly_rate_cents: 10000,
          in_open_payroll_period: true,
          currency: "ETB",
        }),
      ),
    );
    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Approve/ })).toBeNull();
  });
});
