import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DisciplinaryCasesTab } from "@/features/employees/components/disciplinary-cases-tab";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import type { DisciplinaryCase } from "@/features/employees/api";

let canManage = true;

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: { manageDisciplinaryCases: canManage },
  }),
}));

const EMPLOYEE_ID = "01HZEMPLOYEE0000000000001";
const CASES_URL = `*/api/v1/employees/${EMPLOYEE_ID}/disciplinary-cases`;

function buildCase(
  overrides: Partial<DisciplinaryCase> = {},
): DisciplinaryCase {
  return {
    public_id: "01HZCASE00000000000000001",
    reference_number: null,
    category: "misconduct",
    description: "Repeated failure to follow the cash-handling procedure.",
    incident_date: "2026-08-01",
    status: "reported",
    investigation_notes: [],
    decision: null,
    decision_notes: null,
    decided_at: null,
    sanction_type: null,
    sanction_details: null,
    sanction_effective_date: null,
    appeal_status: null,
    appeal_grounds: null,
    appeal_filed_at: null,
    appeal_decision_notes: null,
    appeal_decided_at: null,
    closed_at: null,
    created_at: "2026-08-01T00:00:00Z",
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
  return render(<DisciplinaryCasesTab employeeId={EMPLOYEE_ID} />, {
    wrapper: Wrapper,
  });
}

describe("<DisciplinaryCasesTab>", () => {
  it("shows an empty state with no cases", async () => {
    server.use(http.get(CASES_URL, () => HttpResponse.json([])));
    renderTab();

    expect(
      await screen.findByText("No disciplinary cases"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /open case/i }),
    ).toBeInTheDocument();
  });

  it("lists a case with its category and status", async () => {
    server.use(
      http.get(CASES_URL, () =>
        HttpResponse.json([buildCase({ status: "investigating" })]),
      ),
    );
    renderTab();

    expect(await screen.findByText("Misconduct")).toBeInTheDocument();
    expect(screen.getByText("Investigating")).toBeInTheDocument();
    expect(
      screen.getByText(
        "Repeated failure to follow the cash-handling procedure.",
      ),
    ).toBeInTheDocument();
  });

  it("hides the open-case action for a user without manage permission", async () => {
    canManage = false;
    server.use(http.get(CASES_URL, () => HttpResponse.json([])));
    renderTab();

    await screen.findByText("No disciplinary cases");
    expect(
      screen.queryByRole("button", { name: /open case/i }),
    ).not.toBeInTheDocument();
    canManage = true;
  });

  it("opens a case through the dialog with the right payload", async () => {
    server.use(http.get(CASES_URL, () => HttpResponse.json([])));
    let requestBody: unknown;
    server.use(
      http.post(CASES_URL, async ({ request }) => {
        requestBody = await request.json();
        return HttpResponse.json(buildCase(), { status: 201 });
      }),
    );
    renderTab();
    await screen.findByText("No disciplinary cases");

    await userEvent.click(screen.getByRole("button", { name: /open case/i }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText("Description"),
      "Cash drawer short by 200 ETB at close.",
    );
    await userEvent.type(
      within(dialog).getByLabelText("Incident date"),
      "2026-08-05",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /open case/i }),
    );

    expect(requestBody).toMatchObject({
      category: "misconduct",
      description: "Cash drawer short by 200 ETB at close.",
      incident_date: "2026-08-05",
    });
  });

  it("adds an investigation note to a reported case", async () => {
    // Stateful: the GET handler reflects whatever the note endpoint last
    // returned, the way the real API's list would after the mutation
    // invalidates and refetches it.
    let current = buildCase();
    let requestBody: unknown;
    server.use(
      http.get(CASES_URL, () => HttpResponse.json([current])),
      http.post(
        `${CASES_URL}/${current.public_id}/notes`,
        async ({ request }) => {
          requestBody = await request.json();
          current = buildCase({
            status: "investigating",
            investigation_notes: [
              {
                note: "Interviewed the cashier.",
                by: 1,
                by_name: "HR Admin",
                at: "2026-08-02T00:00:00Z",
              },
            ],
          });
          return HttpResponse.json(current);
        },
      ),
    );
    renderTab();
    await screen.findByText("Misconduct");

    await userEvent.type(
      screen.getByPlaceholderText("Add an investigation note..."),
      "Interviewed the cashier.",
    );
    await userEvent.click(screen.getByRole("button", { name: "Add note" }));

    expect(requestBody).toEqual({ note: "Interviewed the cashier." });
    expect(await screen.findByText("Investigating")).toBeInTheDocument();
  });
});
