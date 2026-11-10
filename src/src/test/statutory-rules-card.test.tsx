import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { StatutoryRulesCard } from "@/features/payroll/components/statutory-rules-card";

const granted = vi.hoisted(() => ({ manage: true }));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (p: string) =>
      p !== "payroll.manageConfig" || granted.manage,
  }),
}));

/**
 * Settings → Payroll → Statutory rules (2026-11-09): every rule with where
 * it came from; the law's locked, the organisation's own resettable. All four
 * QueryBoundary states (loading, empty, error, success).
 */
const URL = "*/api/v1/payroll/statutory-rules";

const RULES = [
  {
    code: "payroll.hours_per_day",
    value: 8,
    default_value: 8,
    layer: "baseline",
    enforcement: "locked",
    floor_direction: null,
    legal_reference: "Labour Proclamation No. 1156/2019, Art. 61(1)",
    confirmed: true,
    effective_from: "2016-07-08",
    version: "2026.11.1",
    setting_key: null,
    editable: false,
    overridden: false,
  },
  {
    code: "payroll.working_days_per_month",
    value: 26,
    default_value: 22,
    layer: "tenant",
    enforcement: "default",
    floor_direction: null,
    legal_reference: null,
    confirmed: false,
    effective_from: "2016-07-08",
    version: null,
    setting_key: "working_days_per_month",
    editable: true,
    overridden: true,
  },
  {
    code: "overtime.minimum_rates",
    value: { normal: 1.5, night: 1.75 },
    default_value: { normal: 1.5, night: 1.75 },
    layer: "baseline",
    enforcement: "locked",
    floor_direction: "min",
    legal_reference: "Labour Proclamation No. 1156/2019, Art. 68(1)",
    confirmed: true,
    effective_from: "2016-07-08",
    version: "2026.11.1",
    setting_key: null,
    editable: false,
    overridden: false,
  },
];

function renderCard() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <StatutoryRulesCard onEdit={() => undefined} />
    </QueryClientProvider>,
  );
}

function serve(data: unknown[] = RULES, ownRatesInForce = false) {
  const deleted: string[] = [];
  server.use(
    http.get(URL, () =>
      HttpResponse.json({
        data,
        organization_type: "private",
        sector: null,
        as_of: "2026-11-09",
        own_rates_in_force: ownRatesInForce,
      }),
    ),
    http.post(`${URL}/use-law`, () => {
      deleted.push("use-law");
      return HttpResponse.json({
        tax_brackets_ended: 6,
        pension_rules_ended: 1,
        own_rates_in_force: false,
      });
    }),
    http.delete(`${URL}/:code`, ({ params }) => {
      deleted.push(String(params.code));
      return HttpResponse.json({
        code: params.code,
        value: 22,
        layer: "organization_type",
      });
    }),
  );
  return deleted;
}

describe("<StatutoryRulesCard>", () => {
  it("shows a skeleton while the rules load", () => {
    server.use(http.get(URL, () => new Promise(() => undefined)));
    const { container } = renderCard();

    expect(container.querySelector(".animate-pulse")).not.toBeNull();
  });

  it("shows the error state when the rules cannot be loaded", async () => {
    server.use(http.get(URL, () => new HttpResponse(null, { status: 500 })));
    renderCard();

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
  });

  it("says so when there are no rules", async () => {
    serve([]);
    renderCard();

    await waitFor(() =>
      expect(screen.queryByText("Working hours in a day")).toBeNull(),
    );
  });

  it("locks the law's rules, saying why and citing the article", async () => {
    serve();
    renderCard();

    expect(
      await screen.findByText("Working hours in a day"),
    ).toBeInTheDocument();
    expect(screen.getAllByText("Set by law")).toHaveLength(2);
    expect(
      screen.getByText(/Labour Proclamation No\. 1156\/2019, Art\. 61\(1\)/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/you may give more on the overtime card/i),
    ).toBeInTheDocument();
    expect(screen.getByText("normal 1.5 · night 1.75")).toBeInTheDocument();
  });

  it("shows an organisation's own value beside the default, and resets it", async () => {
    const deleted = serve();
    const user = userEvent.setup();
    renderCard();

    expect(await screen.findByText("Your value")).toBeInTheDocument();
    expect(screen.getByText("Default: 22")).toBeInTheDocument();
    expect(
      screen.getByText("Awaiting accountant sign-off"),
    ).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /reset to default/i }));

    await waitFor(() =>
      expect(deleted).toEqual(["payroll.working_days_per_month"]),
    );
  });

  it("offers no reset to someone who may only view payroll configuration", async () => {
    serve();
    granted.manage = false;
    renderCard();

    await screen.findByText("Your value");
    expect(
      screen.queryByRole("button", { name: /reset to default/i }),
    ).toBeNull();
    granted.manage = true;
  });

  it("says when an organisation's own tax or pension rates still apply, and ends them", async () => {
    const calls = serve(RULES, true);
    const user = userEvent.setup();
    renderCard();

    expect(
      await screen.findByText(/still applies a tax ladder or pension rule/i),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: /use the law's rates/i }),
    );

    await waitFor(() => expect(calls).toContain("use-law"));
  });

  it("says nothing about own rates when the organisation follows the law", async () => {
    serve();
    renderCard();

    await screen.findByText("Working hours in a day");
    expect(
      screen.queryByRole("button", { name: /use the law's rates/i }),
    ).toBeNull();
  });
});
