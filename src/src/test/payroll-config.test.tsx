import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AllowanceRulesCard } from "@/features/payroll/components/allowance-rules-card";
import { OvertimeRatesCard } from "@/features/payroll/components/overtime-rates-card";
import { TaxBracketsCard } from "@/features/payroll/components/tax-brackets-card";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

function renderWithClient(ui: React.ReactElement) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  // TaxBracketsCard's effective-from field renders DualCalendarDateInput,
  // which needs a CalendarProvider in the tree even when the test never
  // interacts with that field. Force Gregorian mode so the field is a single
  // date input rather than the Ethiopian year/month/day selects.
  localStorage.setItem("ethr.calendar", "gregorian");
  return render(
    <QueryClientProvider client={qc}>
      <CalendarProvider>{ui}</CalendarProvider>
    </QueryClientProvider>,
  );
}

const RULES_URL = "*/api/v1/payroll/rules";
const BRACKETS_URL = "*/api/v1/payroll/tax-brackets";
const RATES_URL = "*/api/v1/payroll/overtime-rates";

function rulesResponse(data: unknown[]) {
  return HttpResponse.json({
    data,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
    links: { next: null, prev: null },
  });
}

const FIXED_RULE = {
  public_id: "01HRULE1",
  name: "Transport Allowance",
  type: "fixed",
  category: "allowance",
  formula: { amount_cents: 100000 },
  is_taxable: false,
  is_active: true,
  sort_order: 0,
};

const PERCENT_RULE = {
  public_id: "01HRULE2",
  name: "Housing Allowance",
  type: "percentage",
  category: "allowance",
  formula: { percent: 12.5 },
  is_taxable: true,
  is_active: false,
  sort_order: 1,
};

// ── Allowance rules ───────────────────────────────────────────────────────────

describe("AllowanceRulesCard", () => {
  it("shows the empty state when no allowances are configured", async () => {
    server.use(http.get(RULES_URL, () => rulesResponse([])));

    renderWithClient(<AllowanceRulesCard />);

    expect(await screen.findByText("No allowances yet")).toBeInTheDocument();
  });

  it("shows the error state when the request fails", async () => {
    server.use(
      http.get(RULES_URL, () => new HttpResponse(null, { status: 500 })),
    );

    renderWithClient(<AllowanceRulesCard />);

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
  });

  it("renders a fixed allowance formatted as ETB", async () => {
    server.use(http.get(RULES_URL, () => rulesResponse([FIXED_RULE])));

    renderWithClient(<AllowanceRulesCard />);

    expect(await screen.findByText("Transport Allowance")).toBeInTheDocument();
    expect(screen.getByText("1,000.00 ETB")).toBeInTheDocument();
    // Non-taxable allowance.
    expect(screen.getByText("No")).toBeInTheDocument();
    expect(screen.getByText("Active")).toBeInTheDocument();
  });

  it("renders a percentage allowance as a percent of basic", async () => {
    server.use(http.get(RULES_URL, () => rulesResponse([PERCENT_RULE])));

    renderWithClient(<AllowanceRulesCard />);

    expect(await screen.findByText("12.5% of basic")).toBeInTheDocument();
    expect(screen.getByText("Inactive")).toBeInTheDocument();
  });

  it("submits a fixed allowance in integer cents", async () => {
    const posted = vi.fn();

    server.use(
      http.get(RULES_URL, () => rulesResponse([])),
      http.post(RULES_URL, async ({ request }) => {
        posted(await request.json());
        return HttpResponse.json(FIXED_RULE, { status: 201 });
      }),
    );

    renderWithClient(<AllowanceRulesCard />);

    fireEvent.click(await screen.findByText("Add allowance"));

    fireEvent.change(screen.getByLabelText("Name"), {
      target: { value: "Transport Allowance" },
    });
    fireEvent.change(screen.getByLabelText("Amount (ETB)"), {
      target: { value: "1000" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(posted).toHaveBeenCalled());

    expect(posted.mock.calls[0][0]).toMatchObject({
      name: "Transport Allowance",
      type: "fixed",
      formula: { amount_cents: 100000 },
    });
  });
});

// ── Tax brackets ──────────────────────────────────────────────────────────────

describe("TaxBracketsCard", () => {
  const LADDER = [
    {
      public_id: "01HB1",
      min_amount_cents: 0,
      max_amount_cents: 60000,
      rate: 0,
      deduction_cents: 0,
      effective_from: "2016-07-08",
      effective_to: null,
    },
    {
      public_id: "01HB2",
      min_amount_cents: 60001,
      max_amount_cents: null,
      rate: 10,
      deduction_cents: 6000,
      effective_from: "2016-07-08",
      effective_to: null,
    },
  ];

  it("renders each band with a derived lower bound", async () => {
    server.use(
      http.get(BRACKETS_URL, () => HttpResponse.json({ data: LADDER })),
    );

    renderWithClient(<TaxBracketsCard />);

    // First band starts at 0; the second starts one cent after the first ends.
    expect(await screen.findByText("0.00 ETB")).toBeInTheDocument();
    expect(screen.getByText("600.01 ETB")).toBeInTheDocument();
    expect(screen.getByText("and above")).toBeInTheDocument();
  });

  it("shows the error state when the ladder cannot be loaded", async () => {
    server.use(
      http.get(BRACKETS_URL, () => new HttpResponse(null, { status: 500 })),
    );

    renderWithClient(<TaxBracketsCard />);

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
  });

  it("submits a contiguous ladder in integer cents", async () => {
    const put = vi.fn();

    server.use(
      http.get(BRACKETS_URL, () => HttpResponse.json({ data: LADDER })),
      http.put(BRACKETS_URL, async ({ request }) => {
        put(await request.json());
        return HttpResponse.json({ data: LADDER });
      }),
    );

    renderWithClient(<TaxBracketsCard />);

    await screen.findByText("and above");
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(put).toHaveBeenCalled());

    const body = put.mock.calls[0][0] as {
      brackets: { min_amount_cents: number; max_amount_cents: number | null }[];
    };

    expect(body.brackets[0].min_amount_cents).toBe(0);
    expect(body.brackets[0].max_amount_cents).toBe(60000);
    // Derived: previous max + 1 cent — no gap, no overlap.
    expect(body.brackets[1].min_amount_cents).toBe(60001);
    expect(body.brackets[1].max_amount_cents).toBeNull();
  });

  it("keeps the last band open-ended after adding one", async () => {
    server.use(
      http.get(BRACKETS_URL, () => HttpResponse.json({ data: LADDER })),
    );

    renderWithClient(<TaxBracketsCard />);

    await screen.findByText("and above");
    expect(screen.getAllByText("and above")).toHaveLength(1);

    fireEvent.click(screen.getByRole("button", { name: "Add band" }));

    await waitFor(() =>
      expect(screen.getAllByLabelText("Rate (%)")).toHaveLength(3),
    );
    expect(screen.getAllByText("and above")).toHaveLength(1);
  });
});

// ── Overtime rates ────────────────────────────────────────────────────────────

describe("OvertimeRatesCard", () => {
  const DEFAULTS = {
    normal: 1.25,
    night: 1.5,
    holiday: 2,
    holiday_night: 2.5,
  };

  it("flags that the proclamation defaults are in use", async () => {
    server.use(
      http.get(RATES_URL, () =>
        HttpResponse.json({
          rates: DEFAULTS,
          defaults: DEFAULTS,
          is_customized: false,
        }),
      ),
    );

    renderWithClient(<OvertimeRatesCard />);

    expect(await screen.findByText("Using defaults")).toBeInTheDocument();
    expect(screen.getByLabelText("Ordinary day")).toHaveValue(1.25);
  });

  it("does not flag defaults once rates are customized", async () => {
    server.use(
      http.get(RATES_URL, () =>
        HttpResponse.json({
          rates: { ...DEFAULTS, normal: 2 },
          defaults: DEFAULTS,
          is_customized: true,
        }),
      ),
    );

    renderWithClient(<OvertimeRatesCard />);

    await waitFor(() =>
      expect(screen.getByLabelText("Ordinary day")).toHaveValue(2),
    );
    expect(screen.queryByText("Using defaults")).not.toBeInTheDocument();
  });

  it("shows the error state when rates cannot be loaded", async () => {
    server.use(
      http.get(RATES_URL, () => new HttpResponse(null, { status: 500 })),
    );

    renderWithClient(<OvertimeRatesCard />);

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
  });

  it("submits edited multipliers as numbers", async () => {
    const put = vi.fn();

    server.use(
      http.get(RATES_URL, () =>
        HttpResponse.json({
          rates: DEFAULTS,
          defaults: DEFAULTS,
          is_customized: false,
        }),
      ),
      http.put(RATES_URL, async ({ request }) => {
        put(await request.json());
        return HttpResponse.json({
          rates: DEFAULTS,
          defaults: DEFAULTS,
          is_customized: true,
        });
      }),
    );

    renderWithClient(<OvertimeRatesCard />);

    const normal = await screen.findByLabelText("Ordinary day");
    fireEvent.change(normal, { target: { value: "2" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    await waitFor(() => expect(put).toHaveBeenCalled());

    expect(put.mock.calls[0][0]).toEqual({
      normal: 2,
      night: 1.5,
      holiday: 2,
      holiday_night: 2.5,
    });
  });

  it("restores the defaults into the form", async () => {
    server.use(
      http.get(RATES_URL, () =>
        HttpResponse.json({
          rates: { ...DEFAULTS, normal: 3 },
          defaults: DEFAULTS,
          is_customized: true,
        }),
      ),
    );

    renderWithClient(<OvertimeRatesCard />);

    await waitFor(() =>
      expect(screen.getByLabelText("Ordinary day")).toHaveValue(3),
    );

    fireEvent.click(screen.getByRole("button", { name: "Reset to defaults" }));

    expect(screen.getByLabelText("Ordinary day")).toHaveValue(1.25);
  });
});
