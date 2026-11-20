import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { PayrollRunDetail } from "@/app/(dashboard)/payroll/[id]/payroll-run-detail";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

const RUN = {
  public_id: "RUN1",
  period_label: "Meskerem 2019",
  period_start: "2026-09-11",
  period_end: "2026-10-10",
  status: "completed",
  employee_count: 1,
  gross_total_cents: 1500000,
  net_total_cents: 1234567,
  tax_total_cents: 200000,
  entries: [],
  processed_at: "2026-10-10T06:00:00Z",
  approved_at: null,
  voided_at: null,
  void_reason: null,
  reprocessed_from_public_id: null,
  created_at: "2026-10-10T06:00:00Z",
};

const DIFFERENCE = {
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
  trail_rebuilt_at_release: false,
  net_is_estimate: true,
  note: null,
  error: null,
  settled_at: null,
  created_at: "2026-11-20T08:00:00Z",
};

function me(role: string, permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function refused(type: string, extra: Record<string, unknown>) {
  return HttpResponse.json(
    { type, title: "Refused", status: 422, detail: "Refused", ...extra },
    { status: 422, headers: { "Content-Type": "application/problem+json" } },
  );
}

function renderDetail() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<PayrollRunDetail routeId="RUN1" />, { wrapper: Wrapper });
}

const APPROVE = "https://ethr.et/errors/pay-differences-unacknowledged";
const PENDING = "https://ethr.et/errors/pay-differences-pending";
const SETTLED = "https://ethr.et/errors/settled-pay-differences-unacknowledged";

/** Approve the run through the first confirmation, the pension-style one. */
async function approveThroughFirstDialog(
  user: ReturnType<typeof userEvent.setup>,
  confirmName: RegExp | string = /Approve|Confirm/,
) {
  await user.click(
    await screen.findByRole("button", { name: /Approve Payroll/ }),
  );
  await user.click(
    within(await screen.findByRole("dialog")).getByRole("button", {
      name: confirmName,
    }),
  );
}

describe("<PayrollRunDetail> pay differences at approval", () => {
  it("lists the open differences and resends with the count after confirming", async () => {
    const bodies: unknown[] = [];
    server.use(
      me("finance_admin", ["payroll.viewAll", "payroll.approve"]),
      http.get("*/api/v1/payroll/runs/RUN1", () => HttpResponse.json(RUN)),
      http.get("*/api/v1/payroll/pay-differences", () =>
        HttpResponse.json({ data: [DIFFERENCE], meta: {}, links: {} }),
      ),
      http.put("*/api/v1/payroll/runs/RUN1/approve", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        bodies.push(body);
        if (!body.acknowledged_pay_differences) {
          return refused(APPROVE, { pay_differences: 1 });
        }
        return HttpResponse.json({ ...RUN, status: "approved" });
      }),
    );
    const user = userEvent.setup();
    renderDetail();

    await approveThroughFirstDialog(user);

    const dialog = await screen.findByRole("dialog", {
      name: /open pay difference/,
    });
    expect(await within(dialog).findByText("Liam Otieno")).toBeInTheDocument();
    expect(within(dialog).getByText(/Underpaid/)).toBeInTheDocument();
    expect(
      within(dialog).getByText(/net amount is an estimate/),
    ).toBeInTheDocument();
    await user.click(
      within(dialog).getByRole("button", {
        name: "I have seen 1 pay difference(s), approve",
      }),
    );

    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[0]).toEqual({});
    expect(bodies[1]).toEqual({ acknowledged_pay_differences: 1 });
  });

  it("says so, with a retry, and will not confirm when the list fails to load", async () => {
    let fail = true;
    server.use(
      me("finance_admin", ["payroll.viewAll", "payroll.approve"]),
      http.get("*/api/v1/payroll/runs/RUN1", () => HttpResponse.json(RUN)),
      http.get("*/api/v1/payroll/pay-differences", () =>
        fail
          ? HttpResponse.json({ message: "boom" }, { status: 500 })
          : HttpResponse.json({ data: [DIFFERENCE], meta: {}, links: {} }),
      ),
      http.put("*/api/v1/payroll/runs/RUN1/approve", () =>
        refused(APPROVE, { pay_differences: 1 }),
      ),
    );
    const user = userEvent.setup();
    renderDetail();

    await approveThroughFirstDialog(user);

    const dialog = await screen.findByRole("dialog", {
      name: /open pay difference/,
    });
    expect(await within(dialog).findByRole("alert")).toBeInTheDocument();
    const confirm = within(dialog).getByRole("button", {
      name: "I have seen 1 pay difference(s), approve",
    });
    expect(confirm).toBeDisabled();

    fail = false;
    await user.click(
      within(dialog).getByRole("button", { name: /try again/i }),
    );
    expect(await within(dialog).findByText("Liam Otieno")).toBeInTheDocument();
    expect(confirm).toBeEnabled();
  });

  it("says how many pay differences could not be worked out", async () => {
    server.use(
      me("finance_admin", ["payroll.viewAll", "payroll.approve"]),
      http.get("*/api/v1/payroll/runs/RUN1", () => HttpResponse.json(RUN)),
      http.get("*/api/v1/payroll/pay-differences", () =>
        HttpResponse.json({
          data: [
            DIFFERENCE,
            {
              ...DIFFERENCE,
              public_id: "PD2",
              employee_name: "Sara Haile",
              status: "failed",
              kind: null,
              gross_cents: null,
              net_cents: null,
              error: "No payroll run covers this month",
            },
          ],
          meta: {},
          links: {},
        }),
      ),
      http.put("*/api/v1/payroll/runs/RUN1/approve", () =>
        refused(APPROVE, { pay_differences: 2, pay_differences_failed: 1 }),
      ),
    );
    const user = userEvent.setup();
    renderDetail();

    await approveThroughFirstDialog(user);

    const dialog = await screen.findByRole("dialog", {
      name: /open pay difference/,
    });
    expect(
      await within(dialog).findByText(/1 of these could not be worked out/),
    ).toBeInTheDocument();
    expect(within(dialog).getByText("Sara Haile")).toBeInTheDocument();
    expect(
      within(dialog).getByText(/No payroll run covers this month/),
    ).toBeInTheDocument();
  });

  it("sends the pension count and the pay difference count together", async () => {
    const bodies: unknown[] = [];
    server.use(
      me("finance_admin", ["payroll.viewAll", "payroll.approve"]),
      http.get("*/api/v1/payroll/runs/RUN1", () => HttpResponse.json(RUN)),
      http.get("*/api/v1/payroll/runs/RUN1/register", () =>
        HttpResponse.json({
          period: "Meskerem 2019",
          status: "completed",
          rows: [],
          totals: {},
          pension_exemptions: {
            count: 2,
            by_reason: { non_national: 2 },
            employees: [],
          },
        }),
      ),
      http.get("*/api/v1/payroll/pay-differences", () =>
        HttpResponse.json({ data: [DIFFERENCE], meta: {}, links: {} }),
      ),
      http.put("*/api/v1/payroll/runs/RUN1/approve", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        bodies.push(body);
        if (!body.acknowledged_pay_differences) {
          return refused(APPROVE, { pay_differences: 1 });
        }
        return HttpResponse.json({ ...RUN, status: "approved" });
      }),
    );
    const user = userEvent.setup();
    renderDetail();

    await screen.findByText(/exempt from pension/);
    await approveThroughFirstDialog(
      user,
      "Approve with 2 pension exemption(s)",
    );
    const dialog = await screen.findByRole("dialog", {
      name: /open pay difference/,
    });
    await user.click(
      within(dialog).getByRole("button", { name: /I have seen 1/ }),
    );

    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[1]).toEqual({
      acknowledged_pension_exemptions: 2,
      acknowledged_pay_differences: 1,
    });
  });

  it("says amounts are still being worked out, with nothing to confirm", async () => {
    server.use(
      me("finance_admin", ["payroll.viewAll", "payroll.approve"]),
      http.get("*/api/v1/payroll/runs/RUN1", () => HttpResponse.json(RUN)),
      http.put("*/api/v1/payroll/runs/RUN1/approve", () =>
        refused(PENDING, {}),
      ),
    );
    const user = userEvent.setup();
    renderDetail();

    await approveThroughFirstDialog(user);

    const dialog = await screen.findByRole("dialog", {
      name: /still being worked out/,
    });
    expect(
      within(dialog).getByText(/Try again in a moment/),
    ).toBeInTheDocument();
    expect(
      within(dialog).queryByRole("button", { name: /I have seen/ }),
    ).toBeNull();
  });
});

describe("<PayrollRunDetail> settled pay differences at void", () => {
  it("warns the reprocess pays them again, and resends with the count", async () => {
    const bodies: unknown[] = [];
    server.use(
      me("tenant_admin", ["payroll.viewAll", "payroll.void"]),
      http.get("*/api/v1/payroll/runs/RUN1", () => HttpResponse.json(RUN)),
      http.post("*/api/v1/payroll/runs/RUN1/void", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        bodies.push(body);
        if (!body.acknowledged_settled_pay_differences) {
          return refused(SETTLED, { settled_pay_differences: 2 });
        }
        return HttpResponse.json({ ...RUN, status: "voided" });
      }),
    );
    const user = userEvent.setup();
    renderDetail();

    await user.click(
      await screen.findByRole("button", { name: /Void Payroll/ }),
    );
    const voidDialog = await screen.findByRole("dialog");
    await user.type(within(voidDialog).getByRole("textbox"), "Wrong period");
    await user.click(
      within(voidDialog).getByRole("button", { name: /Void Payroll/ }),
    );

    const dialog = await screen.findByRole("dialog", {
      name: /Settled pay differences/,
    });
    expect(
      within(dialog).getByText(/pays those months in full a second time/),
    ).toBeInTheDocument();
    await user.click(
      within(dialog).getByRole("button", {
        name: "I will recover 2, void the run",
      }),
    );

    await waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[1]).toEqual({
      reason: "Wrong period",
      acknowledged_settled_pay_differences: 2,
    });
  });
});
