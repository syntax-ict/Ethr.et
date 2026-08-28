import { describe, it, expect, vi } from "vitest";
import { render, screen, within, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { RetirementTab } from "@/features/employees/components/retirement-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import type { RetirementCase } from "@/features/employees/api";

let canManage = true;

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: { manageRetirementCases: canManage },
  }),
}));

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const CASES_URL = `*/api/v1/employees/${EMPLOYEE_ID}/retirement-cases`;

function buildCase(overrides: Partial<RetirementCase> = {}): RetirementCase {
  return {
    public_id: "01HZRETCASE0000000000001",
    retirement_type: "mandatory",
    status: "initiated",
    service_years: 12.5,
    eligible_retirement_date: "2028-03-01",
    reason: "Reached statutory retirement age.",
    notes: null,
    decision: null,
    decision_notes: null,
    decided_at: null,
    finalized_at: null,
    created_at: "2026-08-01T00:00:00Z",
    ...overrides,
  };
}

function renderTab() {
  // DualCalendarDateInput defaults to Ethiopian entry mode (year/month/day
  // selects) unless a calendar preference is stored, but the finalize test
  // types a raw ISO string into a single date field — force Gregorian mode
  // so that field exists.
  localStorage.setItem("ethr.calendar", "gregorian");
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<RetirementTab employeeId={EMPLOYEE_ID} />, {
    wrapper: Wrapper,
  });
}

describe("<RetirementTab>", () => {
  it("shows an empty state with no cases", async () => {
    server.use(http.get(CASES_URL, () => HttpResponse.json([])));
    renderTab();

    expect(await screen.findByText("No retirement cases")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /initiate retirement/i }),
    ).toBeInTheDocument();
  });

  it("lists a case with its type, status and service years", async () => {
    server.use(http.get(CASES_URL, () => HttpResponse.json([buildCase()])));
    renderTab();

    expect(await screen.findByText("Mandatory")).toBeInTheDocument();
    expect(screen.getByText("Initiated")).toBeInTheDocument();
    expect(screen.getByText(/12.5/)).toBeInTheDocument();
    expect(
      screen.getByText("Reached statutory retirement age."),
    ).toBeInTheDocument();
  });

  it("hides the initiate action for a user without manage permission", async () => {
    canManage = false;
    server.use(http.get(CASES_URL, () => HttpResponse.json([])));
    renderTab();

    await screen.findByText("No retirement cases");
    expect(
      screen.queryByRole("button", { name: /initiate retirement/i }),
    ).not.toBeInTheDocument();
    canManage = true;
  });

  it("initiates a case through the dialog with the right payload", async () => {
    server.use(http.get(CASES_URL, () => HttpResponse.json([])));
    let requestBody: unknown;
    server.use(
      http.post(CASES_URL, async ({ request }) => {
        requestBody = await request.json();
        return HttpResponse.json(buildCase(), { status: 201 });
      }),
    );
    renderTab();
    await screen.findByText("No retirement cases");

    await userEvent.click(
      screen.getByRole("button", { name: /initiate retirement/i }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText("Reason"),
      "Requested voluntary retirement.",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /initiate retirement/i }),
    );

    expect(requestBody).toMatchObject({
      retirement_type: "mandatory",
      reason: "Requested voluntary retirement.",
    });
  });

  it("records a decision on an initiated case", async () => {
    // Stateful: the GET handler reflects whatever the decision endpoint last
    // returned, the way the real API's list would after the mutation
    // invalidates and refetches it.
    let current = buildCase();
    let requestBody: unknown;
    server.use(
      http.get(CASES_URL, () => HttpResponse.json([current])),
      http.post(
        `${CASES_URL}/01HZRETCASE0000000000001/decision`,
        async ({ request }) => {
          requestBody = await request.json();
          current = buildCase({ status: "approved", decision: "approved" });
          return HttpResponse.json(current);
        },
      ),
    );
    renderTab();
    await screen.findByText("Mandatory");

    await userEvent.click(
      screen.getByRole("button", { name: /record decision/i }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /record decision/i }),
    );

    expect(requestBody).toMatchObject({ decision: "approved" });
    // "Approved" renders twice: the status badge and the decision line.
    await waitFor(() =>
      expect(screen.getAllByText("Approved")).toHaveLength(2),
    );
  });

  it("finalizes an approved case", async () => {
    let current = buildCase({ status: "approved", decision: "approved" });
    let requestBody: unknown;
    server.use(
      http.get(CASES_URL, () => HttpResponse.json([current])),
      http.post(
        `${CASES_URL}/01HZRETCASE0000000000001/finalize`,
        async ({ request }) => {
          requestBody = await request.json();
          current = buildCase({
            status: "finalized",
            decision: "approved",
            finalized_at: "2026-09-01T00:00:00Z",
          });
          return HttpResponse.json(current);
        },
      ),
    );
    renderTab();
    await screen.findByText("Mandatory");

    await userEvent.click(screen.getByRole("button", { name: /finalize/i }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText("Effective date"),
      "2026-09-01",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /^finalize$/i }),
    );

    expect(requestBody).toMatchObject({ effective_date: "2026-09-01" });
    await waitFor(() =>
      expect(screen.getAllByText("Finalized").length).toBeGreaterThan(0),
    );
  });
});
