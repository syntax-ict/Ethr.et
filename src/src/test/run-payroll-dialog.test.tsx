import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { RunPayrollDialog } from "@/features/payroll/components/run-payroll-dialog";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * Run Payroll with a dry run first (2026-11-09): the period is previewed by
 * the engine, page after page, with nothing saved; only after the figures are
 * checked does POST /payroll/process run.
 */
const PREVIEW = "*/api/v1/payroll/preview";

function payslip(id: string, name: string, net: number) {
  return {
    employee: { public_id: id, name, employee_code: null },
    basic_salary_cents: 1_200_000,
    gross_cents: 1_200_000,
    income_tax_cents: 200_000,
    employee_pension_cents: 84_000,
    employer_pension_cents: 132_000,
    other_deductions_cents: 0,
    net_cents: net,
    pension_exempt_reason: null,
  };
}

function page(payslips: unknown[], nextAfter: string | null) {
  return {
    period_start: "2026-06-01",
    period_end: "2026-06-30",
    organization_type: "private",
    sector: null,
    rules: [
      {
        code: "payroll.hours_per_day",
        value: 8,
        layer: "baseline",
        version: "2026.11.1",
        legal_reference: "Labour Proclamation No. 1156/2019, Art. 61(1)",
      },
    ],
    income_tax: {
      legal_reference: "Income Tax Proclamation No. 1395/2025",
      effective_from: "2025-07-08",
      source: "platform",
    },
    pension: {
      scheme: "private",
      employee_rate: 7,
      employer_rate: 11,
      legal_reference: "Proclamation No. 1268/2022, Art. 10",
      source: "platform",
    },
    payslips,
    next_after: nextAfter,
  };
}

function renderDialog() {
  localStorage.setItem("ethr.calendar", "gregorian");
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const onOpenChange = vi.fn();
  render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <RunPayrollDialog open onOpenChange={onOpenChange} />
      </CalendarProvider>
    </QueryClientProvider>,
  );
  return onOpenChange;
}

async function choosePeriod(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText("Period Start"), "2026-06-01");
  await user.type(screen.getByLabelText("Period End"), "2026-06-30");
  await user.click(screen.getByRole("button", { name: /preview payslips/i }));
}

describe("<RunPayrollDialog>", () => {
  it("previews every page, saves nothing, and runs only once the figures are checked", async () => {
    const bodies: Record<string, unknown>[] = [];
    let processed: Record<string, unknown> | null = null;
    server.use(
      http.post(PREVIEW, async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        bodies.push(body);
        return HttpResponse.json(
          body.after
            ? page([payslip("B", "Bekele Girma", 900_000)], null)
            : page([payslip("A", "Almaz Tesfaye", 916_000)], "A"),
        );
      }),
      http.post("*/api/v1/payroll/process", async ({ request }) => {
        processed = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ status: "processing" }, { status: 202 });
      }),
    );
    const user = userEvent.setup();
    const onOpenChange = renderDialog();

    await choosePeriod(user);

    expect(await screen.findByText("Bekele Girma")).toBeInTheDocument();
    expect(screen.getByText("Almaz Tesfaye")).toBeInTheDocument();
    expect(bodies).toEqual([
      { period_start: "2026-06-01", period_end: "2026-06-30" },
      { period_start: "2026-06-01", period_end: "2026-06-30", after: "A" },
    ]);
    expect(
      screen.getByText(/Income tax: Income Tax Proclamation No\. 1395\/2025/),
    ).toBeInTheDocument();
    expect(screen.getByText(/employee 7%, employer 11%/)).toBeInTheDocument();
    expect(
      screen.getByText("Statutory rules version 2026.11.1"),
    ).toBeInTheDocument();
    expect(processed).toBeNull();

    const run = screen.getByRole("button", { name: /process payroll/i });
    expect(run).toBeDisabled();
    await user.click(
      screen.getByRole("checkbox", { name: /I have checked these figures/i }),
    );
    expect(run).toBeEnabled();
    await user.click(run);

    await waitFor(() => expect(processed).not.toBeNull());
    expect(processed).toMatchObject({
      period_start: "2026-06-01",
      period_end: "2026-06-30",
    });
    await waitFor(() => expect(onOpenChange).toHaveBeenCalledWith(false));
  });

  it("says so when no one is paid in the period, and offers no run", async () => {
    server.use(http.post(PREVIEW, () => HttpResponse.json(page([], null))));
    const user = userEvent.setup();
    renderDialog();

    await choosePeriod(user);

    expect(
      await screen.findByText("No one to pay in this period"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /process payroll/i }),
    ).toBeDisabled();
  });

  it("goes back to the period without running anything", async () => {
    server.use(
      http.post(PREVIEW, () =>
        HttpResponse.json(page([payslip("A", "Almaz Tesfaye", 916_000)], null)),
      ),
    );
    const user = userEvent.setup();
    renderDialog();

    await choosePeriod(user);
    await screen.findByText("Almaz Tesfaye");
    await user.click(screen.getByRole("button", { name: /change period/i }));

    expect(
      screen.getByRole("button", { name: /preview payslips/i }),
    ).toBeInTheDocument();
  });
});
