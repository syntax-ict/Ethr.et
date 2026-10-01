import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import type { components } from "@/api/generated";
import ShiftsPage from "@/app/(dashboard)/shifts/page";
import ShiftAssignmentsPage from "@/app/(dashboard)/shifts/assignments/page";
import ShiftRotationsPage from "@/app/(dashboard)/shifts/rotations/page";
import SettingsShiftsPage from "@/app/(dashboard)/settings/shifts/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(""),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  usePathname: () => "/shifts",
}));

type Shift = components["schemas"]["ShiftResource"];
type Assignment = components["schemas"]["ShiftAssignmentResource"];
type Rotation = components["schemas"]["ShiftRotationResource"];

/**
 * Shaped like ShiftResource as MariaDB serves it: `start_time` is a TIME
 * column and comes back as `HH:MM:SS` (onboarding also writes the seconds on
 * every database), while Store/UpdateShiftRequest accept only `H:i`.
 */
function shift(n: number, overrides: Partial<Shift> = {}): Shift {
  return {
    public_id: `01HZSHIFT${String(n).padStart(17, "0")}`,
    name: `Shift ${n}`,
    name_am: null,
    start_time: "08:30:00",
    end_time: "17:30:00",
    crosses_midnight: false,
    grace_minutes: 15,
    early_departure_minutes: 15,
    break_minutes: 60,
    working_days: "1,2,3,4,5",
    is_default: false,
    is_active: true,
    assignments_count: 0,
    created_at: "2026-09-01T06:00:00Z",
    updated_at: "2026-09-01T06:00:00Z",
    ...overrides,
  };
}

/** ShiftAssignmentResource: `rotation` is absent unless the relation was loaded. */
function assignment(overrides: Partial<Assignment> = {}): Assignment {
  return {
    shift: shift(1, { name: "Morning" }),
    is_rotation: false,
    anchor_date: null,
    assignable_type: "Employee",
    effective_from: "2026-09-01",
    effective_to: null,
    created_at: "2026-09-01T06:00:00Z",
    ...overrides,
  };
}

function rotation(overrides: Partial<Rotation> = {}): Rotation {
  return {
    public_id: "01HZROTATION00000000000001",
    name: "Four on four off",
    name_am: null,
    description: null,
    cycle_days: 8,
    is_active: true,
    steps: [],
    assignments_count: 0,
    created_at: "2026-09-01T06:00:00Z",
    updated_at: "2026-09-01T06:00:00Z",
    ...overrides,
  };
}

/** Serves `rows` the way the API pages them: 25 unless asked, at most 100. */
function paged<T>(rows: T[], request: Request) {
  const url = new URL(request.url);
  const page = Number(url.searchParams.get("page") ?? 1);
  const perPage = Math.min(Number(url.searchParams.get("per_page") ?? 25), 100);
  return HttpResponse.json({
    data: rows.slice((page - 1) * perPage, page * perPage),
    meta: {
      current_page: page,
      last_page: Math.max(1, Math.ceil(rows.length / perPage)),
      per_page: perPage,
      total: rows.length,
    },
    links: {},
  });
}

const HR_ADMIN = [
  "shift.viewAny",
  "shift.view",
  "shift.create",
  "shift.update",
  "employee.viewAny",
];
const TENANT_ADMIN = [...HR_ADMIN, "shift.delete"];

function serve({
  role = "hr_admin",
  permissions = HR_ADMIN,
  shifts = [shift(1, { name: "Morning" })],
  schedule = [] as Assignment[],
  employees = [buildEmployee()],
  rotations = [] as Rotation[],
}: {
  role?: string;
  permissions?: string[];
  shifts?: Shift[];
  schedule?: Assignment[];
  employees?: ReturnType<typeof buildEmployee>[];
  rotations?: Rotation[];
} = {}) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Hana", role },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions,
      }),
    ),
    http.get("*/api/v1/shifts", ({ request }) => paged(shifts, request)),
    http.get("*/api/v1/shifts/schedule", ({ request }) =>
      paged(schedule, request),
    ),
    http.get("*/api/v1/employees", ({ request }) => paged(employees, request)),
    http.get("*/api/v1/shift-rotations", ({ request }) =>
      paged(rotations, request),
    ),
    http.get("*/api/v1/organization/departments", ({ request }) =>
      paged([], request),
    ),
    http.get("*/api/v1/organization/branches", ({ request }) =>
      paged([], request),
    ),
  );
}

function renderPage(ui: ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{ui}</CalendarProvider>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(toast.error).mockClear();
  vi.mocked(toast.success).mockClear();
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe("Shifts page", () => {
  it("edits a shift whose times come back with seconds, sending HH:MM", async () => {
    let body: Record<string, unknown> | null = null;
    serve();
    server.use(
      http.put("*/api/v1/shifts/:id", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(shift(1, { name: "Morning" }));
      }),
    );
    const user = userEvent.setup();
    renderPage(<ShiftsPage />);

    await screen.findByText("Morning");
    await user.click(screen.getByRole("button", { name: "Actions" }));
    await user.click(await screen.findByRole("menuitem", { name: /Edit/ }));
    await user.click(screen.getByRole("button", { name: "Save Changes" }));

    // UpdateShiftRequest: 'start_time' => ['sometimes', 'date_format:H:i'].
    // Echoing "08:30:00" back was a 422 on every edit.
    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ start_time: "08:30", end_time: "17:30" });
    await waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Shift updated"),
    );
  });

  it("shows shift times as HH:MM, not as the column's HH:MM:SS", async () => {
    serve();
    renderPage(<ShiftsPage />);

    expect(
      await screen.findByText(
        (_, el) =>
          el?.tagName === "P" && el.textContent?.trim() === "08:30 → 17:30",
      ),
    ).toBeInTheDocument();
  });

  it("lists every shift, not the first hundred", async () => {
    serve({ shifts: Array.from({ length: 130 }, (_, i) => shift(i + 1)) });
    renderPage(<ShiftsPage />);

    expect(await screen.findByText("Shift 130")).toBeInTheDocument();
  });

  it("does not offer Delete to a role without shift.delete", async () => {
    // shift.delete is a tenant-admin grant; the page admits HR admins.
    serve({ permissions: HR_ADMIN });
    const user = userEvent.setup();
    renderPage(<ShiftsPage />);

    await screen.findByText("Morning");
    await user.click(screen.getByRole("button", { name: "Actions" }));
    await screen.findByRole("menuitem", { name: /Edit/ });

    expect(
      screen.queryByRole("menuitem", { name: /Delete/ }),
    ).not.toBeInTheDocument();
  });

  it("asks before deleting, and reports the API's reason when it refuses", async () => {
    let deletes = 0;
    serve({ role: "tenant_admin", permissions: TENANT_ADMIN });
    server.use(
      http.delete("*/api/v1/shifts/:id", () => {
        deletes++;
        return HttpResponse.json(
          {
            type: "about:blank",
            title: "Forbidden",
            status: 403,
            detail: "This action is unauthorized.",
          },
          { status: 403 },
        );
      }),
    );
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
    const user = userEvent.setup();
    renderPage(<ShiftsPage />);

    await screen.findByText("Morning");
    await user.click(screen.getByRole("button", { name: "Actions" }));
    await user.click(await screen.findByRole("menuitem", { name: /Delete/ }));

    expect(confirm).toHaveBeenCalledWith("Delete this shift?");
    expect(deletes).toBe(0);

    confirm.mockReturnValue(true);
    await user.click(screen.getByRole("button", { name: "Actions" }));
    await user.click(await screen.findByRole("menuitem", { name: /Delete/ }));

    // Not "Shift is in use": ShiftController::destroy never refuses for that.
    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith("This action is unauthorized."),
    );
    expect(deletes).toBe(1);
  });
});

describe("Shift assignments page", () => {
  it("offers every employee, not the first hundred", async () => {
    serve({
      employees: Array.from({ length: 130 }, (_, i) =>
        buildEmployee({
          public_id: `01HZEMP${String(i + 1).padStart(19, "0")}`,
          name: `Employee ${i + 1}`,
        }),
      ),
    });
    const user = userEvent.setup();
    renderPage(<ShiftAssignmentsPage />);

    await user.click(
      (await screen.findAllByRole("button", { name: /Assign/ }))[0],
    );
    await user.click(
      (await screen.findByText("Select employee")).closest("button")!,
    );

    expect(
      await screen.findByRole("option", { name: "Employee 130" }),
    ).toBeInTheDocument();
  });

  it("shows an assignment as active through its last day", async () => {
    // 10:00 in Addis on the assignment's final, inclusive day.
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-10-07T07:00:00Z"));
    serve({ schedule: [assignment({ effective_to: "2026-10-07" })] });
    renderPage(<ShiftAssignmentsPage />);

    const name = await screen.findByText("Morning");
    const card = name.closest("div.flex-1") as HTMLElement;

    expect(within(card).getByText("Active")).toBeInTheDocument();
    expect(within(card).queryByText("Expired")).not.toBeInTheDocument();
  });
});

describe("Shift rotations page", () => {
  it("opens a rotation for editing", async () => {
    // SimpleTable renders a row's `actions` instead of its onEdit/onDelete;
    // the page passed all three, so Edit and Delete never appeared.
    serve({ rotations: [rotation()] });
    const user = userEvent.setup();
    renderPage(<ShiftRotationsPage />);

    await screen.findByText("Four on four off");
    await user.click(screen.getByRole("button", { name: "Edit" }));

    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByRole("heading", { name: "Edit rotation" }),
    ).toBeInTheDocument();
    expect(within(dialog).getByLabelText("Name")).toHaveValue(
      "Four on four off",
    );
  });

  it("does not offer Delete to a role without shift.delete", async () => {
    serve({ permissions: HR_ADMIN, rotations: [rotation()] });
    renderPage(<ShiftRotationsPage />);

    await screen.findByText("Four on four off");
    expect(screen.getByRole("button", { name: "Edit" })).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Delete" }),
    ).not.toBeInTheDocument();
  });

  it("offers Delete to a role holding shift.delete", async () => {
    serve({
      role: "tenant_admin",
      permissions: TENANT_ADMIN,
      rotations: [rotation()],
    });
    renderPage(<ShiftRotationsPage />);

    await screen.findByText("Four on four off");
    expect(
      await screen.findByRole("button", { name: "Delete" }),
    ).toBeInTheDocument();
  });
});

describe("Shift settings page", () => {
  it("lists every shift, not the first 25", async () => {
    serve({ shifts: Array.from({ length: 30 }, (_, i) => shift(i + 1)) });
    renderPage(<SettingsShiftsPage />);

    expect(await screen.findByText("Shift 30")).toBeInTheDocument();
  });

  it("does not offer Delete to a role without shift.delete", async () => {
    serve({ permissions: HR_ADMIN });
    renderPage(<SettingsShiftsPage />);

    await screen.findByText("Morning");
    expect(
      screen.queryByRole("button", { name: "Delete" }),
    ).not.toBeInTheDocument();
  });

  it("rejects a grace period the API would refuse, without sending it", async () => {
    let posts = 0;
    serve();
    server.use(
      http.post("*/api/v1/shifts", () => {
        posts++;
        return HttpResponse.json(shift(2), { status: 201 });
      }),
    );
    const user = userEvent.setup();
    renderPage(<SettingsShiftsPage />);

    await screen.findByText("Morning");
    await user.click(screen.getByRole("button", { name: /Add Shift/ }));
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText(/^Name/), "Long grace");
    await user.type(within(dialog).getByLabelText(/^Start Time/), "08:30");
    await user.type(within(dialog).getByLabelText(/^End Time/), "17:30");
    const grace = within(dialog).getByLabelText(/^Grace Period/);
    await user.clear(grace);
    await user.type(grace, "150");
    await user.click(within(dialog).getByRole("button", { name: /Add Shift/ }));

    // StoreShiftRequest: 'grace_minutes' => ['integer', 'min:0', 'max:120'].
    await waitFor(() => expect(grace).toHaveAttribute("aria-invalid", "true"));
    expect(posts).toBe(0);
  });
});
