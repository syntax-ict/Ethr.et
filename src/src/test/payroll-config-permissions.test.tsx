import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import PayrollConfigPage from "@/app/(dashboard)/settings/payroll/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * Payroll Rules was gated on `minRole="tenant_admin"`, a role check, so a
 * Finance Admin (who holds payroll.viewConfig) was refused a page whose APIs
 * would have answered, and a custom role could not be given it. The page now
 * follows the permissions its APIs check: payroll.viewConfig to read,
 * payroll.manageConfig to change.
 */

const RULE = {
  public_id: "RULE1",
  name: "Transport",
  type: "fixed",
  formula: { amount_cents: 150000 },
  is_taxable: false,
  is_active: true,
};

const RATES = {
  normal: 1.5,
  night: 1.75,
  rest_day: 2,
  holiday: 2.5,
  holiday_night: 2.75,
};

function serve(permissions: string[], planFeatures: string[] | null = null) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "Test User", role: "finance_admin" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions,
        plan_features: planFeatures,
      }),
    ),
    http.get("*/api/v1/payroll/rules", () =>
      HttpResponse.json({
        data: [RULE],
        meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
        links: {},
      }),
    ),
    http.get("*/api/v1/payroll/tax-brackets", () =>
      HttpResponse.json({ data: [] }),
    ),
    http.get("*/api/v1/payroll/pension-rules", () =>
      HttpResponse.json({ data: [], in_force: null }),
    ),
    http.get("*/api/v1/payroll/overtime-rates", () =>
      HttpResponse.json({
        rates: RATES,
        defaults: RATES,
        is_customized: false,
      }),
    ),
  );
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PayrollConfigPage />
    </QueryClientProvider>,
  );
}

describe("<PayrollConfigPage> permissions", () => {
  it("is refused to a role without payroll.viewConfig, whatever its level", async () => {
    // payroll.viewAll (every run and payslip) is not the configuration.
    serve(["payroll.viewAll"]);
    renderPage();

    expect(await screen.findByTestId("role-gate-denied")).toBeInTheDocument();
  });

  it("is read-only for a viewer: the rules show, nothing offers to change them", async () => {
    serve(["payroll.viewConfig"]);
    renderPage();

    expect(await screen.findByText("Transport")).toBeInTheDocument();
    expect(
      screen.getByText(/needs the Manage payroll configuration permission/i),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /add allowance/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /edit transport/i }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /delete transport/i }),
    ).not.toBeInTheDocument();

    // The overtime form is there to read, not to submit.
    const normal = await screen.findByLabelText(/ordinary day/i);
    expect(normal).toBeDisabled();
    expect(
      screen.queryByRole("button", { name: /^save$/i }),
    ).not.toBeInTheDocument();
  });

  it("offers the controls to a role holding payroll.manageConfig", async () => {
    serve(["payroll.viewConfig", "payroll.manageConfig"]);
    renderPage();

    expect(
      await screen.findByRole("button", { name: /add allowance/i }),
    ).toBeInTheDocument();
    expect(
      await screen.findByRole("button", { name: /edit transport/i }),
    ).toBeInTheDocument();
    expect(await screen.findByLabelText(/ordinary day/i)).toBeEnabled();
    expect(
      screen.queryByText(/needs the Manage payroll configuration permission/i),
    ).not.toBeInTheDocument();
  });

  it("says so when the plan has no payroll, rather than showing a page that looks broken", async () => {
    serve(["payroll.viewConfig", "payroll.manageConfig"], ["webhooks"]);
    renderPage();

    expect(
      await screen.findByText(/not included in your organisation's plan/i),
    ).toBeInTheDocument();
  });

  it("shows no plan notice when payroll is in the plan", async () => {
    serve(["payroll.viewConfig", "payroll.manageConfig"], ["payroll"]);
    renderPage();

    await screen.findByText("Transport");
    expect(
      screen.queryByText(/not included in your organisation's plan/i),
    ).not.toBeInTheDocument();
  });
});
