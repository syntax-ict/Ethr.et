import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { saveCsv } from "@/lib/utils/csv-export";
import EmployeesPage from "@/app/(dashboard)/employees/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/utils/csv-export", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/utils/csv-export")>()),
  saveCsv: vi.fn(),
}));

function renderPage(role = "hr_admin") {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "HR", role },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: ["employee.viewAny", "employee.create"],
      }),
    ),
    // Page 1 of 2: the table holds 25 of 30 employees.
    http.get("*/api/v1/employees", () =>
      HttpResponse.json({
        data: [buildEmployee()],
        meta: {
          current_page: 1,
          last_page: 2,
          per_page: 25,
          total: 30,
          from: 1,
          to: 25,
        },
        links: { first: "", last: "", prev: null, next: "?page=2" },
      }),
    ),
  );

  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <EmployeesPage />
    </QueryClientProvider>,
  );
}

describe("Employees page export", () => {
  it("offers only the export of every matching employee, not a page-sized one", async () => {
    let exportHit = 0;
    server.use(
      http.get("*/api/v1/employees/export", () => {
        exportHit++;
        return HttpResponse.json({ csv: "name\nAbebe\n", count: 30 });
      }),
    );

    renderPage();
    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();

    // DataTable's "Export CSV" downloads the 25 loaded rows only; it sat beside
    // the full export with nothing to say which was which.
    expect(
      screen.queryByRole("button", { name: "Export CSV" }),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Export" }));

    await waitFor(() =>
      expect(saveCsv).toHaveBeenCalledWith(
        expect.stringMatching(/^employees-\d{4}-\d{2}-\d{2}\.csv$/),
        "name\nAbebe\n",
      ),
    );
    expect(exportHit).toBe(1);
  });
});

describe("Employees page access", () => {
  it("opens for a custom role holding employee.create, as the sidebar promises", async () => {
    // The sidebar offers this page on `can.manageEmployees`; the page gated on
    // the hr_admin role tier, so this user got the link and "Access Denied".
    renderPage("employee");

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.queryByTestId("role-gate-denied")).not.toBeInTheDocument();
  });
});
