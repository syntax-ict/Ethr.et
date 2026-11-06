import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { BillingOnlyGate } from "@/features/billing/components/billing-only-gate";

/**
 * A-Z review H-09 (2026-11-05). While the organisation is locked out for
 * money only sign-in and billing answer, so the dashboard gives way to the
 * bill: the billing page for whoever manages billing, a message for everyone
 * else.
 */
vi.mock("@/app/(dashboard)/billing/page", () => ({
  default: () => <div>Billing page</div>,
}));

function me(billingOnly: boolean, permissions: string[]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "u1", name: "Abebe", role: "tenant_admin" },
        permissions,
        tenant: {
          public_id: "t1",
          name: "Acme",
          subdomain: "acme",
          status: "trial",
        },
        billing_only: billingOnly,
      }),
    ),
  );
}

function renderGate() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <BillingOnlyGate>
        <div>Dashboard</div>
      </BillingOnlyGate>
    </QueryClientProvider>,
  );
}

describe("BillingOnlyGate", () => {
  it("shows the bill, not the dashboard, to whoever manages billing", async () => {
    me(true, ["billing.manage"]);
    renderGate();

    expect(await screen.findByText("Billing page")).toBeInTheDocument();
    expect(
      screen.getByText("Acme's subscription has ended"),
    ).toBeInTheDocument();
    expect(screen.queryByText("Dashboard")).not.toBeInTheDocument();
  });

  it("tells everyone else who can put it right", async () => {
    me(true, ["leave.request"]);
    renderGate();

    expect(
      await screen.findByText(/Ask your organisation's administrator/),
    ).toBeInTheDocument();
    expect(screen.queryByText("Billing page")).not.toBeInTheDocument();
    expect(screen.queryByText("Dashboard")).not.toBeInTheDocument();
  });

  it("leaves an organisation in good standing on its dashboard", async () => {
    me(false, ["billing.manage"]);
    renderGate();

    expect(await screen.findByText("Dashboard")).toBeInTheDocument();
  });
});
