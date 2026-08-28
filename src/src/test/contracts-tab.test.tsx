import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { ContractsTab } from "@/features/employees/components/contracts-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import type { EmployeeContract } from "@/features/employees/api";

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const CONTRACTS_URL = `*/api/v1/employees/${EMPLOYEE_ID}/contracts`;

function buildContract(
  overrides: Partial<EmployeeContract> = {},
): EmployeeContract {
  return {
    public_id: "01HZCONTRACT000000000001",
    reference_number: null,
    contract_type: "fixed_term",
    start_date: "2026-01-01",
    end_date: "2026-12-31",
    salary_cents: 1_500_000,
    terms: null,
    status: "active",
    ended_at: null,
    end_notes: null,
    is_expired: false,
    expires_soon: false,
    days_until_expiry: 300,
    created_at: "2026-01-01T00:00:00Z",
    ...overrides,
  };
}

function renderTab() {
  // DualCalendarDateInput defaults to Ethiopian entry mode (year/month/day
  // selects) unless a calendar preference is stored, but these tests type a
  // raw ISO string into a single date field — force Gregorian mode so that
  // field exists.
  localStorage.setItem("ethr.calendar", "gregorian");
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<ContractsTab employeeId={EMPLOYEE_ID} />, {
    wrapper: Wrapper,
  });
}

describe("<ContractsTab>", () => {
  it("shows an empty state with no contracts", async () => {
    server.use(http.get(CONTRACTS_URL, () => HttpResponse.json([])));
    renderTab();

    expect(await screen.findByText("No contracts")).toBeInTheDocument();
  });

  it("lists a contract with its type, status and salary", async () => {
    server.use(
      http.get(CONTRACTS_URL, () => HttpResponse.json([buildContract()])),
    );
    renderTab();

    expect(await screen.findByText("Fixed term")).toBeInTheDocument();
    expect(screen.getByText("Active")).toBeInTheDocument();
    expect(screen.getByText("15,000.00 ETB")).toBeInTheDocument();
  });

  it("flags a contract expiring soon", async () => {
    server.use(
      http.get(CONTRACTS_URL, () =>
        HttpResponse.json([buildContract({ expires_soon: true })]),
      ),
    );
    renderTab();

    expect(await screen.findByText("Expiring soon")).toBeInTheDocument();
  });

  it("adds a contract through the dialog with the right payload", async () => {
    server.use(http.get(CONTRACTS_URL, () => HttpResponse.json([])));
    let requestBody: unknown;
    server.use(
      http.post(CONTRACTS_URL, async ({ request }) => {
        requestBody = await request.json();
        return HttpResponse.json(buildContract(), { status: 201 });
      }),
    );
    renderTab();
    await screen.findByText("No contracts");

    await userEvent.click(
      screen.getByRole("button", { name: /add contract/i }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText("Start date"),
      "2026-02-01",
    );
    await userEvent.type(
      within(dialog).getByLabelText("End date"),
      "2026-08-01",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /add contract/i }),
    );

    expect(requestBody).toMatchObject({
      contract_type: "fixed_term",
      start_date: "2026-02-01",
      end_date: "2026-08-01",
    });
  });

  it("ends an active contract as expired", async () => {
    let requestBody: unknown;
    server.use(
      http.get(CONTRACTS_URL, () => HttpResponse.json([buildContract()])),
      http.post(
        `${CONTRACTS_URL}/01HZCONTRACT000000000001/end`,
        async ({ request }) => {
          requestBody = await request.json();
          return HttpResponse.json(buildContract({ status: "expired" }));
        },
      ),
    );
    renderTab();
    await screen.findByText("Fixed term");

    await userEvent.click(
      screen.getByRole("button", { name: /mark expired/i }),
    );

    expect(requestBody).toEqual({ status: "expired" });
  });
});
