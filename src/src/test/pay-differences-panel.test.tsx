import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PayDifferencesPanel } from "@/features/payroll/components/pay-differences-panel";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function difference(over: Record<string, unknown>) {
  return {
    public_id: "PD1",
    employee_public_id: "E1",
    employee_name: "Liam Otieno",
    run_public_id: "RUN0",
    period_label: "August 2026",
    period_start: "2026-08-01",
    period_end: "2026-08-31",
    kind: "underpaid",
    gross_cents: 150000,
    net_cents: 120000,
    paid_gross_cents: 1000000,
    paid_net_cents: 800000,
    due_gross_cents: 1150000,
    due_net_cents: 920000,
    status: "open",
    changed_from: "2026-08-10",
    trail_rebuilt_at_release: true,
    net_is_estimate: false,
    note: null,
    settled_at: null,
    created_at: "2026-11-20T08:00:00Z",
    ...over,
  };
}

function me(permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role: "finance_admin" },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function list(rows: unknown[]) {
  return http.get("*/api/v1/payroll/pay-differences", () =>
    HttpResponse.json({ data: rows, meta: {}, links: {} }),
  );
}

function renderPanel() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<PayDifferencesPanel />, { wrapper: Wrapper });
}

describe("<PayDifferencesPanel>", () => {
  it("lists the differences with kind, amounts and the flag explained", async () => {
    server.use(
      me(["payroll.viewAll", "payroll.approve"]),
      list([
        difference({}),
        difference({
          public_id: "PD2",
          employee_name: "Sara Haile",
          kind: null,
          gross_cents: null,
          net_cents: null,
          status: "pending",
          trail_rebuilt_at_release: false,
        }),
      ]),
    );
    renderPanel();

    expect(await screen.findByText("Liam Otieno")).toBeInTheDocument();
    expect(screen.getByText("Underpaid")).toBeInTheDocument();
    expect(screen.getByText("Being worked out")).toBeInTheDocument();
    expect(screen.getByText(/pay trail was rebuilt/)).toBeInTheDocument();
  });

  it("posts the note when an open difference is settled", async () => {
    const posts: unknown[] = [];
    server.use(
      me(["payroll.viewAll", "payroll.approve"]),
      list([difference({})]),
      http.post(
        "*/api/v1/payroll/pay-differences/PD1/settle",
        async ({ request }) => {
          posts.push(await request.json());
          return HttpResponse.json(difference({ status: "settled" }));
        },
      ),
    );
    const user = userEvent.setup();
    renderPanel();

    await user.click(await screen.findByRole("button", { name: "Settle" }));
    const dialog = await screen.findByRole("dialog");
    const submit = within(dialog).getByRole("button", { name: "Settle" });
    expect(submit).toBeDisabled();
    await user.type(within(dialog).getByLabelText("Note"), "Paid by transfer");
    await user.click(submit);

    await waitFor(() => expect(posts).toEqual([{ note: "Paid by transfer" }]));
  });

  it("disables Settle and Dismiss on a row that is not open", async () => {
    server.use(
      me(["payroll.viewAll", "payroll.approve"]),
      list([difference({ status: "pending" })]),
    );
    renderPanel();

    expect(
      await screen.findByRole("button", { name: "Settle" }),
    ).toBeDisabled();
    expect(screen.getByRole("button", { name: "Dismiss" })).toBeDisabled();
  });

  it("offers no actions without payroll.approve", async () => {
    server.use(me(["payroll.viewAll"]), list([difference({})]));
    renderPanel();

    await screen.findByText("Liam Otieno");
    expect(screen.queryByRole("button", { name: "Settle" })).toBeNull();
  });

  it("says so when there are none", async () => {
    server.use(me(["payroll.viewAll"]), list([]));
    renderPanel();

    expect(await screen.findByText("No pay differences")).toBeInTheDocument();
  });
});
