import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
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
});
