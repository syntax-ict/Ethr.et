import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ReportsPage from "@/app/(dashboard)/reports/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

// The page is wrapped in <RoleGate anyPermission={["viewReports"]}>; grant access directly.
// Every ability is held unless a test denies it.
const denied = new Set<string>();
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: (permission: string) => !denied.has(permission),
    can: { viewReports: true },
    role: "hr_admin",
  }),
}));

beforeEach(() => denied.clear());

/** `ReportEngine::SOURCES`, as `GET /reports/sources` returns it. */
const SOURCES = {
  sources: {
    employees: {
      label: "Employees",
      fields: [
        "name",
        "email",
        "phone",
        "employee_code",
        "gender",
        "status",
        "hire_date",
        "salary_cents",
        "department",
        "branch",
        "position",
      ],
      filters: ["status", "department", "branch", "cost_center"],
    },
    attendance: {
      label: "Attendance",
      fields: [
        "employee_name",
        "date",
        "check_in",
        "check_out",
        "status",
        "source",
        "worked_minutes",
      ],
      filters: ["from", "to", "status", "department", "branch"],
    },
    leave: {
      label: "Leave Balances",
      fields: [
        "employee_name",
        "leave_type",
        "year",
        "entitled_days",
        "used_days",
        "remaining_days",
      ],
      filters: ["year", "department", "branch"],
    },
    payroll: {
      label: "Payroll",
      fields: [
        "employee_name",
        "period",
        "basic_salary_cents",
        "gross_cents",
        "income_tax_cents",
        "employee_pension_cents",
        "net_cents",
      ],
      filters: ["from", "to", "department", "branch", "cost_center"],
    },
  },
};

/** `ReportEngine::generate()` for an ungrouped run: `summary` is `[]`. */
const EMPTY_RESULT = { source: "employees", total: 0, data: [], summary: [] };

function serve(onGenerate: (body: Record<string, unknown>) => void) {
  server.use(
    http.get("*/api/v1/reports/sources", () => HttpResponse.json(SOURCES)),
    http.post("*/api/v1/reports/generate", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      onGenerate(body);
      return HttpResponse.json({ ...EMPTY_RESULT, source: body.source });
    }),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    // As the app mounts every dashboard page: under the calendar provider.
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<ReportsPage />, { wrapper });
}

async function chooseSource(
  user: ReturnType<typeof userEvent.setup>,
  label: string,
) {
  await user.click(
    await screen.findByRole("combobox", { name: "Data Source" }),
  );
  await user.click(await screen.findByRole("option", { name: label }));
}

/**
 * The filter picker offered every column of the source, but ReportEngine reads
 * only the keys each source publishes in `filters` and ignores the rest. A
 * filter such as `gender = female` or `name = Abebe` was dropped on the
 * server, so the report came back unfiltered with nothing to say so.
 */
describe("Reports builder — filters", () => {
  it("filters employees by a key the engine reads, not the first column", async () => {
    let body: Record<string, unknown> | null = null;
    serve((b) => (body = b));

    const user = userEvent.setup();
    renderPage();

    await screen.findByText("11 available fields", { exact: false });
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    // A status is chosen, not typed. This test used to type "active", which
    // is not an employee status: the engine matched nothing and the report
    // came back empty (audit N100).
    await user.click(screen.getByRole("combobox", { name: /value: status/i }));
    await user.click(await screen.findByRole("option", { name: "Confirmed" }));
    await user.click(screen.getByRole("button", { name: /Run Preview/ }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      source: "employees",
      filters: { status: "confirmed" },
    });
  });

  it("offers attendance only the filters the engine applies", async () => {
    serve(() => {});

    const user = userEvent.setup();
    renderPage();

    await chooseSource(user, "Attendance");
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    await user.click(screen.getByRole("combobox", { name: "Filter field" }));

    const listbox = await screen.findByRole("listbox");
    expect(
      within(listbox)
        .getAllByRole("option")
        .map((o) => o.textContent),
    ).toEqual(["From", "To", "Status", "Department", "Branch"]);
  });

  // A date typed as "1/10/2026" matched nothing (audit N100).
  it("gives a date filter a date control, not a free-text box", async () => {
    serve(() => {});

    const user = userEvent.setup();
    renderPage();

    await chooseSource(user, "Attendance");
    await user.click(screen.getByRole("button", { name: "Add filter" }));

    expect(screen.queryByPlaceholderText("value")).not.toBeInTheDocument();
  });

  it("filters payroll by period and unit, which it ignored before", async () => {
    serve(() => {});

    const user = userEvent.setup();
    renderPage();

    await chooseSource(user, "Payroll");
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    await user.click(screen.getByRole("combobox", { name: "Filter field" }));

    const listbox = await screen.findByRole("listbox");
    expect(
      within(listbox)
        .getAllByRole("option")
        .map((o) => o.textContent),
    ).toEqual(["From", "To", "Department", "Branch", "Cost centre"]);
  });
});

// Blueprint assessment D6, 2026-10-20: the server refuses the payroll source
// without payroll.viewAll and drops salary without employee.viewFinancial, so
// the builder does not offer either.
describe("Reports builder — pay data", () => {
  it("offers neither the payroll source nor salary to a caller without them", async () => {
    denied.add("payroll.viewAll");
    denied.add("employee.viewFinancial");
    serve(() => {});

    const user = userEvent.setup();
    renderPage();

    await screen.findByText("10 available fields", { exact: false });
    await user.click(
      await screen.findByRole("combobox", { name: "Data Source" }),
    );
    expect(
      screen.queryByRole("option", { name: "Payroll" }),
    ).not.toBeInTheDocument();
  });
});
