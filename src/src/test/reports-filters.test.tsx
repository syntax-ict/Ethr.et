import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ReportsPage from "@/app/(dashboard)/reports/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

// The page is wrapped in <RoleGate minRole="hr_admin">; grant access directly.
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    can: {},
    role: "hr_admin",
  }),
}));

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
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
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
 * only a few keys from `filters` — employees: `status`; attendance: `from` and
 * `to`; leave: `year`; payroll: none — and ignores the rest. A filter such as
 * `gender = female` or `name = Abebe` was dropped on the server, so the report
 * came back unfiltered with nothing to say so.
 */
describe("Reports builder — filters", () => {
  it("filters employees by a key the engine reads, not the first column", async () => {
    let body: Record<string, unknown> | null = null;
    serve((b) => (body = b));

    const user = userEvent.setup();
    renderPage();

    await screen.findByText("11 available fields", { exact: false });
    await user.click(screen.getByRole("button", { name: "Add filter" }));
    await user.type(screen.getByPlaceholderText("value"), "active");
    await user.click(screen.getByRole("button", { name: /Run Preview/ }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      source: "employees",
      filters: { status: "active" },
    });
  });

  it("offers attendance only the date range the engine applies", async () => {
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
    ).toEqual(["from", "to"]);
  });

  it("cannot add a filter to the payroll source, which applies none", async () => {
    serve(() => {});

    const user = userEvent.setup();
    renderPage();

    await chooseSource(user, "Payroll");

    expect(screen.getByRole("button", { name: "Add filter" })).toBeDisabled();
    expect(
      screen.getByText("This data source cannot be filtered."),
    ).toBeInTheDocument();
  });
});
