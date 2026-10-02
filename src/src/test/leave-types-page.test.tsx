import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
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
