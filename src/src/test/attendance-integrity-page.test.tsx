import { describe, it, expect, vi, beforeEach } from "vitest";
import {
  render,
  screen,
  fireEvent,
  waitFor,
  within,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AttendanceIntegrityPage from "@/app/(dashboard)/attendance/integrity/page";
import type { IntegrityFlag } from "@/features/attendance/api";

/**
 * The integrity review queue: findings in plain words with their numbers,
 * a reason required to reject (it goes to the employee), and no buttons on
 * the reviewer's own punch.
 */
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: { resolveAttendanceConflicts: true, viewAttendanceConflicts: true },
  }),
}));

vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useCurrentUser: () => ({ data: { employee_public_id: "me" } }),
  useCurrentTenant: () => ({ data: { timezone: "Africa/Addis_Ababa" } }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function flag(overrides: Partial<IntegrityFlag>): IntegrityFlag {
  return {
    employee_findings_90_days: 0,
    public_id: "f1",
    type: "outside_geofence",
    severity: "high",
    punch: "check_in",
    status: "open",
    details: { distance_meters: 61000, radius_meters: 200, source: "web" },
    created_at: "2026-10-13T06:00:00Z",
    reviewed_at: null,
    review_note: null,
    reviewer_name: null,
    employee: { public_id: "e1", name: "Abebe Kebede", employee_code: "E-1" },
    record: {
      public_id: "r1",
      date: "2026-10-13",
      check_in: "2026-10-13T06:00:00Z",
      check_out: null,
      source: "web",
      status: "present",
      shift_name: null,
      latitude: 8.5,
      longitude: 39.3,
      risk_score: null,
      risk_reasons: [],
      has_photo: false,
    },
    ...overrides,
  };
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AttendanceIntegrityPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("locale", "en");
  server.use(
    http.get("*/attendance/integrity/summary", () =>
      HttpResponse.json({ open: 2, by_severity: { high: 2 }, by_type: {} }),
    ),
  );
});

describe("Attendance integrity page", () => {
  it("explains each finding and requires a reason to reject", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/attendance/integrity", () =>
        HttpResponse.json({
          data: [
            flag({}),
            flag({
              public_id: "mine",
              employee: {
                public_id: "me",
                name: "Reviewer",
                employee_code: "E-9",
              },
            }),
          ],
          meta: { current_page: 1, last_page: 1, total: 2 },
        }),
      ),
      http.put("*/attendance/integrity/f1/review", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(flag({ status: "rejected" }));
      }),
    );

    renderPage();

    expect(
      await screen.findAllByText("61000 m from the branch; the area is 200 m."),
    ).toHaveLength(2);
    // Two flags, but buttons only on the one that is not the reviewer's own.
    expect(screen.getAllByRole("button", { name: /^reject$/i })).toHaveLength(
      1,
    );

    fireEvent.click(screen.getByRole("button", { name: /^reject$/i }));
    const confirm = await screen.findByRole("button", {
      name: /reject and void/i,
    });
    expect(confirm).toBeDisabled();

    fireEvent.change(screen.getByLabelText(/reason/i), {
      target: { value: "60 km from the branch." },
    });
    fireEvent.click(confirm);

    await waitFor(() =>
      expect(body).toEqual({
        decision: "reject",
        note: "60 km from the branch.",
      }),
    );
  });
});

describe("findings that are not about a punch", () => {
  function serve(flags: IntegrityFlag[]) {
    server.use(
      http.get("*/attendance/integrity", () =>
        HttpResponse.json({
          data: flags,
          meta: { current_page: 1, last_page: 1, total: flags.length },
        }),
      ),
    );
  }

  it("words a presence answer from outside the workplace", async () => {
    serve([
      flag({
        punch: "presence",
        details: {
          source: "presence",
          asked_at: "2026-11-18T07:00:00Z",
          answered_at: "2026-11-18T07:04:00Z",
          distance_meters: 20000,
          nearest: { name: "Head office", radius_meters: 150 },
        },
      }),
    ]);
    renderPage();

    expect(
      await screen.findByText("Outside the workplace at a presence check"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/from 20 km away from Head office/),
    ).toBeInTheDocument();
    expect(screen.getByText(/Presence check/)).toBeInTheDocument();
  });

  it("words a pattern of missed presence checks", async () => {
    serve([
      flag({
        type: "presence_pattern",
        punch: "pattern",
        severity: "medium",
        details: { missed: 3, unverified: 1, total: 5, days: 14 },
      }),
    ]);
    renderPage();

    expect(
      await screen.findByText("Presence checks missed or unverified"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "3 missed and 1 unverified of 5 presence checks in 14 days.",
      ),
    ).toBeInTheDocument();
  });

  it("gives a kiosk report of the wrong person its own words", async () => {
    serve([
      flag({
        type: "kiosk_wrong_person",
        punch: "check_in",
        details: { source: "kiosk", kiosk_session_id: 4 },
      }),
    ]);
    renderPage();

    expect(
      await screen.findByText("Reported as the wrong person at a kiosk"),
    ).toBeInTheDocument();
    expect(screen.queryByText("kiosk_wrong_person")).toBeNull();
  });

  it("offers Excused and Not excused for a presence flag, and keeps the day", async () => {
    let body: Record<string, unknown> | null = null;
    serve([flag({ punch: "presence" })]);
    server.use(
      http.put("*/attendance/integrity/f1/review", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          flag({ punch: "presence", status: "rejected" }),
        );
      }),
    );
    renderPage();

    expect(
      await screen.findByRole("button", { name: "Excused" }),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^reject$/i })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Not excused" }));

    expect(
      await screen.findByText(/The day.s record is kept/),
    ).toBeInTheDocument();
    expect(screen.queryByText(/is voided/)).toBeNull();
    fireEvent.change(screen.getByLabelText(/reason/i), {
      target: { value: "No answer to the check." },
    });
    const dialog = screen.getByRole("dialog");
    fireEvent.click(
      within(dialog).getByRole("button", { name: "Not excused" }),
    );

    await waitFor(() =>
      expect(body).toEqual({
        decision: "reject",
        note: "No answer to the check.",
      }),
    );
  });

  it("keeps Accept and Reject, and the voiding warning, for a check-in flag", async () => {
    serve([flag({ punch: "check_in" })]);
    renderPage();

    expect(
      await screen.findByRole("button", { name: /^accept$/i }),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Excused" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: /^reject$/i }));
    expect(await screen.findByText(/The punch is voided/)).toBeInTheDocument();
  });
});
