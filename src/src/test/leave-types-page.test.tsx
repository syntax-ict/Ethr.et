import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { toast } from "sonner";
import LeaveTypesPage from "@/app/(dashboard)/settings/leave-types/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** Shaped like LeaveTypeResource. */
function leaveType(n: number, overrides: Record<string, unknown> = {}) {
  return {
    public_id: `LT${n}`,
    name: `Leave ${n}`,
    name_am: null,
    code: `L${n}`,
    default_days: 10,
    accrual_type: "monthly",
    carry_forward: false,
    max_carry_days: null,
    requires_approval: true,
    requires_attachment: false,
    min_notice_days: 0,
    max_consecutive: null,
    is_paid: true,
    is_active: true,
    gender_restriction: null,
    sort_order: n,
    created_at: "2026-09-01T00:00:00Z",
    updated_at: "2026-09-01T00:00:00Z",
    ...overrides,
  };
}

const ANNUAL = leaveType(1, {
  name: "Annual Leave",
  code: "AL",
  default_days: 16,
  accrual_type: "annual",
});

/** Serves `rows` the way the API does: 25 a page unless asked, at most 100. */
function serveLeaveTypes(rows: unknown[]) {
  server.use(
    http.get("*/api/v1/leave-types", ({ request }) => {
      const url = new URL(request.url);
      const page = Number(url.searchParams.get("page") ?? 1);
      const perPage = Math.min(
        Number(url.searchParams.get("per_page") ?? 25),
        100,
      );
      const from = (page - 1) * perPage;
      return HttpResponse.json({
        data: rows.slice(from, from + perPage),
        meta: {
          current_page: page,
          last_page: Math.max(1, Math.ceil(rows.length / perPage)),
          per_page: perPage,
          total: rows.length,
        },
        links: {},
      });
    }),
  );
}

/** A custom role keeps a base `role`; its permissions come from the custom role. */
function me(permissions: string[], role = "hr_admin") {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<LeaveTypesPage />, { wrapper: Wrapper });
}

describe("<LeaveTypesPage>", () => {
  it("edits a template's lowercase, eleven-letter code without renaming it", async () => {
    // Organisation templates create `bereavement`; the form upper-cased every
    // code and capped it at ten characters, so this type could not be saved.
    let sent: Record<string, unknown> | null = null;
    const bereavement = leaveType(2, {
      name: "Bereavement Leave",
      code: "bereavement",
      default_days: 3,
    });
    serveLeaveTypes([bereavement]);
    server.use(
      me(["leave.manageTypes", "leave.viewTypes"]),
      http.put("*/api/v1/leave-types/LT2", async ({ request }) => {
        sent = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...bereavement, ...sent });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    const row = (await screen.findByText("Bereavement Leave")).closest("tr")!;
    await user.click(within(row).getByRole("button", { name: "Edit" }));
    await user.click(
      await screen.findByRole("switch", { name: "Offered to employees" }),
    );
    await user.click(screen.getByRole("button", { name: "Save Changes" }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({ code: "bereavement", is_active: false });
  });

  it("duplicates a leave type and explains a refused delete", async () => {
    let duplicated = false;
    serveLeaveTypes([ANNUAL]);
    server.use(
      me(["leave.manageTypes", "leave.viewTypes"]),
      http.post("*/api/v1/leave-types/LT1/duplicate", () => {
        duplicated = true;
        return HttpResponse.json(
          { ...ANNUAL, public_id: "LT9", name: "Annual Leave (copy)" },
          { status: 201 },
        );
      }),
      http.delete("*/api/v1/leave-types/LT1", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/leave-type-in-use",
            title: "Leave Type In Use",
            status: 409,
            detail:
              "This leave type cannot be deleted: 3 leave requests use it.",
          },
          { status: 409 },
        ),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    const row = (await screen.findByText("Annual Leave")).closest("tr")!;
    await user.click(within(row).getByRole("button", { name: "Duplicate" }));
    await waitFor(() => expect(duplicated).toBe(true));

    await user.click(within(row).getByRole("button", { name: "Delete" }));
    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        expect.stringContaining("3 leave requests use it"),
      ),
    );
  });

  it("keeps a leave type's accrual when it is edited", async () => {
    // The edit dialog was seeded with the literal "monthly", so renaming an
    // annual leave type silently turned it into monthly accrual.
    let sent: Record<string, unknown> | null = null;
    serveLeaveTypes([ANNUAL]);
    server.use(
      me(["leave.manageTypes", "leave.viewTypes"]),
      http.put("*/api/v1/leave-types/LT1", async ({ request }) => {
        sent = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...ANNUAL, ...sent });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    const row = (await screen.findByText("Annual Leave")).closest("tr")!;
    await user.click(within(row).getByRole("button", { name: "Edit" }));
    const name = await screen.findByRole("textbox", { name: /Name/ });
    await user.clear(name);
    await user.type(name, "Annual Leave (2027)");
    await user.click(screen.getByRole("button", { name: "Save Changes" }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({
      name: "Annual Leave (2027)",
      accrual_type: "annual",
    });
  });

  // Labour Proclamation 1156/2019 Art. 77(1): one more day for every
  // additional two years of service (2026-10-20).
  it("keeps the seniority step when a leave type is edited, and clears it", async () => {
    let sent: Record<string, unknown> | null = null;
    const stepped = {
      ...ANNUAL,
      seniority_every_years: 2,
      seniority_extra_days: 1,
    };
    serveLeaveTypes([stepped]);
    server.use(
      me(["leave.manageTypes", "leave.viewTypes"]),
      http.put("*/api/v1/leave-types/LT1", async ({ request }) => {
        sent = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...stepped, ...sent });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    const row = (await screen.findByText("Annual Leave")).closest("tr")!;
    await user.click(within(row).getByRole("button", { name: "Edit" }));
    const every = await screen.findByRole("spinbutton", {
      name: /Add days every/,
    });
    expect(every).toHaveValue(2);

    // One half alone is refused on the form.
    await user.clear(every);
    await user.click(screen.getByRole("button", { name: "Save Changes" }));
    expect(
      await screen.findByText("Fill in both, or leave both empty."),
    ).toBeInTheDocument();
    expect(sent).toBeNull();

    await user.clear(
      screen.getByRole("spinbutton", { name: /Days added each time/ }),
    );
    await user.click(screen.getByRole("button", { name: "Save Changes" }));

    await waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({
      seniority_every_years: null,
      seniority_extra_days: null,
    });
  });

  it("lists leave types past the first page of 25", async () => {
    serveLeaveTypes(Array.from({ length: 30 }, (_, i) => leaveType(i + 1)));
    server.use(me(["leave.manageTypes"]));
    renderPage();

    expect(await screen.findByText("Leave 30")).toBeInTheDocument();
  });

  it("admits a custom role holding leave.manageTypes", async () => {
    // Gated on the tenant's role tier (`hr_admin`); a custom role keeps its
    // base role, so one granted the permission was refused the page the API
    // would have served it.
    serveLeaveTypes([ANNUAL]);
    server.use(me(["leave.manageTypes"], "employee"));
    renderPage();

    expect(await screen.findByText("Annual Leave")).toBeInTheDocument();
    expect(screen.queryByTestId("role-gate-denied")).not.toBeInTheDocument();
  });
});
