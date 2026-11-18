import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import AttendanceConflictsPage from "@/app/(dashboard)/attendance/conflicts/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const permissions = vi.hoisted(() => ({
  value: ["attendance.viewConflicts", "attendance.resolveConflicts"],
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "hr_admin",
    level: 70,
    permissions: permissions.value,
    hasPermission: (a: string) => permissions.value.includes(a),
    isAtLeast: () => true,
    hasRole: () => true,
    isSuperAdmin: false,
    isTenantAdmin: false,
    isHrAdmin: true,
    isFinanceAdmin: false,
    isSupervisor: true,
    isEmployee: false,
    can: {
      viewAttendanceConflicts: permissions.value.includes(
        "attendance.viewConflicts",
      ),
      resolveAttendanceConflicts: permissions.value.includes(
        "attendance.resolveConflicts",
      ),
    },
  }),
}));

const conflict = {
  public_id: "01HZCONFLICT00000000000001",
  conflict_type: "time_overlap",
  resolution: "pending",
  resolution_notes: null,
  resolved_at: null,
  created_at: "2026-08-20T06:00:00+00:00",
  employee: { name: "Abebe Kebede", employee_code: "EMP-0042" },
  record_a: {
    public_id: "01HZRECORDA0000000000001",
    date: "2026-08-20",
    check_in: "2026-08-20T05:00:00+00:00",
    check_out: "2026-08-20T13:00:00+00:00",
    source_label: "Device",
    worked_minutes: 480,
  },
  record_b: {
    public_id: "01HZRECORDB0000000000001",
    date: "2026-08-20",
    check_in: "2026-08-20T05:30:00+00:00",
    check_out: "2026-08-20T14:00:00+00:00",
    source_label: "Mobile",
    worked_minutes: 510,
  },
};

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AttendanceConflictsPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  permissions.value = [
    "attendance.viewConflicts",
    "attendance.resolveConflicts",
  ];
});

describe("<AttendanceConflictsPage>", () => {
  it("renders both sides of a conflict with the employee and type", async () => {
    server.use(
      http.get("*/attendance/conflicts", () =>
        HttpResponse.json({ data: [conflict] }),
      ),
    );

    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText("Overlapping times")).toBeInTheDocument();
    expect(screen.getByText("Record A")).toBeInTheDocument();
    expect(screen.getByText("Record B")).toBeInTheDocument();
    expect(screen.getByText("Device")).toBeInTheDocument();
    expect(screen.getByText("Mobile")).toBeInTheDocument();
  });

  it("says which day the conflict is on", async () => {
    // The card named the person and two times but never the day (2026-10-09).
    server.use(
      http.get("*/attendance/conflicts", () =>
        HttpResponse.json({ data: [conflict] }),
      ),
    );

    renderPage();

    expect(await screen.findByText("Thu 20 Aug 2026")).toBeInTheDocument();
  });

  it("filters to pending conflicts by default", async () => {
    let requestUrl = "";
    server.use(
      http.get("*/attendance/conflicts", ({ request }) => {
        requestUrl = request.url;
        return HttpResponse.json({ data: [] });
      }),
    );

    renderPage();

    await waitFor(() => expect(requestUrl).not.toBe(""));
    expect(decodeURIComponent(requestUrl)).toContain(
      "filter[resolution]=pending",
    );
  });

  it("shows an empty state when nothing needs review", async () => {
    server.use(
      http.get("*/attendance/conflicts", () => HttpResponse.json({ data: [] })),
    );

    renderPage();

    expect(
      await screen.findByText("No conflicts to review"),
    ).toBeInTheDocument();
  });

  it("sends the chosen resolution and notes to the resolve endpoint", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/attendance/conflicts", () =>
        HttpResponse.json({ data: [conflict] }),
      ),
      http.put("*/attendance/conflicts/:id/resolve", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ public_id: conflict.public_id });
      }),
    );

    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("button", { name: /review/i }));

    // Radix Select needs a real pointer sequence, hence userEvent here.
    await user.click(screen.getByLabelText("Decision"));
    await user.click(await screen.findByText("Keep record A (void B)"));

    fireEvent.change(screen.getByLabelText("Notes (optional)"), {
      target: { value: "Device clock drifted" },
    });
    await user.click(
      screen.getByRole("button", { name: /^resolve conflict$/i }),
    );

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({
      resolution: "keep_a",
      resolution_notes: "Device clock drifted",
    });
  });

  it("hides the review action from someone who may look but not resolve", async () => {
    permissions.value = ["attendance.viewConflicts"];
    server.use(
      http.get("*/attendance/conflicts", () =>
        HttpResponse.json({ data: [conflict] }),
      ),
    );

    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /review/i }),
    ).not.toBeInTheDocument();
  });

  // The API refuses a conflict on the caller's own attendance; the button
  // only led to a 403 (audit N84).
  it("offers no review on the caller's own conflict, and says why", async () => {
    server.use(
      http.get("*/attendance/conflicts", () =>
        HttpResponse.json({
          data: [
            { ...conflict, employee_public_id: "01HZEMPSELF0000000000001" },
          ],
        }),
      ),
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: {
            public_id: "U1",
            name: "Abebe Kebede",
            role: "hr_admin",
            employee_public_id: "01HZEMPSELF0000000000001",
          },
          tenant: { public_id: "T1", name: "Demo" },
          permissions: [],
        }),
      ),
    );

    renderPage();

    expect(
      await screen.findByText(/someone else reviews this/i),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /review/i }),
    ).not.toBeInTheDocument();
  });

  it("reaches conflicts past the first page", async () => {
    // The endpoint pages by 25 and the page rendered only the first, so the
    // 26th pending conflict could not be reviewed at all.
    const second = {
      ...conflict,
      public_id: "01HZCONFLICT00000000000002",
      employee: { name: "Zewditu Haile", employee_code: "EMP-0099" },
    };
    server.use(
      http.get("*/attendance/conflicts", ({ request }) => {
        const p = Number(new URL(request.url).searchParams.get("page") ?? 1);
        return HttpResponse.json({
          data: [p === 2 ? second : conflict],
          meta: { current_page: p, last_page: 2, per_page: 25, total: 26 },
        });
      }),
    );
    const user = userEvent.setup();

    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /next/i }));
    expect(await screen.findByText("Zewditu Haile")).toBeInTheDocument();
  });
});

// A day recorded before 2026-11-18 as a morning and an afternoon record: the
// conflicts page joins such days one at a time, for the ticked ones, or for
// everything one person has.
describe("<AttendanceConflictsPage> split days", () => {
  const PERSON = "01HZEMPPERSON000000000001";
  const split = {
    ...conflict,
    conflict_type: "multi_source_far",
    employee_public_id: PERSON,
    record_a: {
      ...conflict.record_a,
      check_in: "2026-08-20T05:20:00+00:00",
      check_out: "2026-08-20T09:05:00+00:00",
    },
    record_b: {
      ...conflict.record_b,
      check_in: "2026-08-20T10:55:00+00:00",
      check_out: "2026-08-20T14:40:00+00:00",
    },
  };
  const second = {
    ...split,
    public_id: "01HZCONFLICT00000000000002",
    employee: { name: "Zewditu Haile", employee_code: "EMP-0099" },
    employee_public_id: "01HZEMPOTHER000000000001",
  };

  function listConflicts(rows: unknown[]) {
    server.use(
      http.get("*/attendance/conflicts", () =>
        HttpResponse.json({ data: rows }),
      ),
    );
  }

  it("joins the ticked conflicts a slice at a time and says the result", async () => {
    listConflicts([split, second]);
    const bodies: Record<string, unknown>[] = [];
    server.use(
      http.post("*/attendance/conflicts/join", async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        return bodies.length === 1
          ? HttpResponse.json({
              joined: 1,
              left: [],
              remaining: 1,
              next_after: split.public_id,
            })
          : HttpResponse.json({
              joined: 1,
              left: [
                { conflict_id: second.public_id, reason: "payroll_locked" },
              ],
              remaining: 0,
              next_after: null,
            });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Abebe Kebede");
    expect(
      screen.getByRole("button", { name: /join split days \(0\)/i }),
    ).toBeDisabled();

    await user.click(
      screen.getByRole("checkbox", {
        name: "Select the conflict of Abebe Kebede",
      }),
    );
    await user.click(
      screen.getByRole("checkbox", {
        name: "Select the conflict of Zewditu Haile",
      }),
    );
    await user.click(
      screen.getByRole("button", { name: /join split days \(2\)/i }),
    );

    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[0]).toEqual({
      conflict_ids: [split.public_id, second.public_id],
    });
    expect(bodies[1]).toEqual({
      conflict_ids: [split.public_id, second.public_id],
      after: split.public_id,
    });
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "Joined 2 days. 1 left as they were.",
      ),
    );
  });

  it("joins every split day of one person", async () => {
    listConflicts([split, second]);
    let body: Record<string, unknown> | null = null;
    server.use(
      http.post("*/attendance/conflicts/join", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({
          joined: 3,
          left: [],
          remaining: 0,
          next_after: null,
        });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Abebe Kebede");
    const buttons = screen.getAllByRole("button", {
      name: /join all of this person's split days/i,
    });
    await user.click(buttons[0]!);

    await waitFor(() => expect(body).toEqual({ employee_public_id: PERSON }));
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "Joined 3 days. 0 left as they were.",
      ),
    );
  });

  it("shows progress, stops when asked, and carries on from where it stopped", async () => {
    listConflicts([split]);
    const bodies: Record<string, unknown>[] = [];
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    server.use(
      http.post("*/attendance/conflicts/join", async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        if (bodies.length === 1) {
          await gate;
          return HttpResponse.json({
            joined: 1,
            left: [],
            remaining: 4,
            next_after: "01HZCURSOR0000000000000001",
          });
        }
        return HttpResponse.json({
          joined: 4,
          left: [],
          remaining: 0,
          next_after: null,
        });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Abebe Kebede");
    await user.click(
      screen.getByRole("button", {
        name: /join all of this person's split days/i,
      }),
    );

    await user.click(await screen.findByRole("button", { name: "Stop" }));
    release();

    expect(await screen.findByText(/Stopped\./)).toBeInTheDocument();
    expect(
      screen.getByText(/1 joined, 4 still to look at/),
    ).toBeInTheDocument();
    expect(bodies).toHaveLength(1);

    await user.click(screen.getByRole("button", { name: "Carry on" }));

    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[1]).toEqual({
      employee_public_id: PERSON,
      after: "01HZCURSOR0000000000000001",
    });
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith(
        "Joined 5 days. 0 left as they were.",
      ),
    );
  });

  it("offers no join to someone who may look but not resolve", async () => {
    permissions.value = ["attendance.viewConflicts"];
    listConflicts([split]);

    renderPage();

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /join/i }),
    ).not.toBeInTheDocument();
  });

  it("calls merged 'Join into one day' for a split day, and keeps the old wording for any other pair", async () => {
    listConflicts([
      split,
      { ...conflict, public_id: "01HZCONFLICT00000000000009" },
    ]);
    const user = userEvent.setup();
    renderPage();

    await screen.findAllByText("Abebe Kebede");
    const reviews = screen.getAllByRole("button", { name: /^review$/i });

    await user.click(reviews[0]!);
    await user.click(screen.getByLabelText("Decision"));
    expect(
      await screen.findByRole("option", { name: "Join into one day" }),
    ).toBeInTheDocument();
    await user.keyboard("{Escape}{Escape}");

    await user.click(screen.getAllByRole("button", { name: /^review$/i })[1]!);
    await user.click(screen.getByLabelText("Decision"));
    expect(
      await screen.findByRole("option", { name: "Merged manually" }),
    ).toBeInTheDocument();
  });
});
