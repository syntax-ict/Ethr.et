import { describe, it, expect } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

function createWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  // Named declaration rather than an anonymous arrow: react/display-name
  // needs a name to report, and an unnamed wrapper shows up as <Unknown> in
  // React DevTools and in test failure output.
  function TestQueryWrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
  }

  return TestQueryWrapper;
}

const MOCK_BILLING_DASHBOARD = {
  plan: "Professional",
  plan_price_cents: 99900,
  subscription_status: "active",
  current_period_end: "2026-08-15T00:00:00Z",
  invoices: [
    {
      public_id: "01HZINV001",
      total_cents: 99900,
      status: "paid",
      due_date: "2026-07-15",
      paid_at: "2026-07-14T10:00:00Z",
    },
    {
      public_id: "01HZINV002",
      total_cents: 99900,
      status: "pending",
      due_date: "2026-08-15",
      paid_at: null,
    },
  ],
};

describe("useBillingDashboard", () => {
  it("fetches billing dashboard data", async () => {
    server.use(
      http.get("*/api/v1/billing/dashboard", () =>
        HttpResponse.json(MOCK_BILLING_DASHBOARD),
      ),
    );

    const { useBillingDashboard } = await import("@/features/billing/api");
    const { result } = renderHook(() => useBillingDashboard(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.plan).toBe("Professional");
    expect(result.current.data!.plan_price_cents).toBe(99900);
    expect(result.current.data!.invoices).toHaveLength(2);
  });

  it("returns subscription status", async () => {
    server.use(
      http.get("*/api/v1/billing/dashboard", () =>
        HttpResponse.json(MOCK_BILLING_DASHBOARD),
      ),
    );

    const { useBillingDashboard } = await import("@/features/billing/api");
    const { result } = renderHook(() => useBillingDashboard(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.subscription_status).toBe("active");
  });
});

describe("usePlans", () => {
  it("fetches available plans", async () => {
    server.use(
      http.get("*/api/v1/plans", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "01HZPLAN001",
              name: "Starter",
              slug: "starter",
              price_cents: 0,
              max_employees: 25,
              max_branches: 1,
              max_devices: 2,
              features: ["attendance", "leave"],
              sort_order: 1,
            },
            {
              public_id: "01HZPLAN002",
              name: "Professional",
              slug: "professional",
              price_cents: 99900,
              max_employees: 500,
              max_branches: 10,
              max_devices: 20,
              features: ["attendance", "leave", "payroll", "reports"],
              sort_order: 2,
            },
            {
              public_id: "01HZPLAN003",
              name: "Enterprise",
              slug: "enterprise",
              price_cents: 249900,
              max_employees: null,
              max_branches: null,
              max_devices: null,
              features: null,
              sort_order: 3,
            },
          ],
        }),
      ),
    );

    const { usePlans } = await import("@/features/billing/api");
    const { result } = renderHook(() => usePlans(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.data).toHaveLength(3);
    expect(result.current.data!.data[0].name).toBe("Starter");
    expect(result.current.data!.data[2].max_employees).toBeNull();
  });
});

describe("useChangePlan", () => {
  it("changes plan and returns proration info", async () => {
    server.use(
      http.post("*/api/v1/billing/change-plan", () =>
        HttpResponse.json({
          old_plan: "Starter",
          new_plan: "Professional",
          proration_cents: 49950,
          effective_immediately: true,
        }),
      ),
    );

    const { useChangePlan } = await import("@/features/billing/api");
    const { result } = renderHook(() => useChangePlan(), {
      wrapper: createWrapper(),
    });

    result.current.mutate({ plan_public_id: "01HZPLAN002" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data!.new_plan).toBe("Professional");
    expect(result.current.data!.proration_cents).toBe(49950);
    expect(result.current.data!.effective_immediately).toBe(true);
  });
});

describe("useMarkInvoicePaid", () => {
  it("marks an invoice as paid", async () => {
    server.use(
      http.put("*/api/v1/billing/invoices/:id/mark-paid", () =>
        HttpResponse.json({
          public_id: "01HZINV002",
          status: "paid",
          paid_at: "2026-07-24T12:00:00Z",
        }),
      ),
    );

    const { useMarkInvoicePaid } = await import("@/features/billing/api");
    const { result } = renderHook(() => useMarkInvoicePaid(), {
      wrapper: createWrapper(),
    });

    result.current.mutate("01HZINV002");

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data.status).toBe("paid");
    expect(result.current.data.paid_at).toBeTruthy();
  });
});
