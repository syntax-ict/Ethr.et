import { afterEach, describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import EmployeesPage from "@/app/(dashboard)/employees/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/utils/csv-export", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/utils/csv-export")>()),
  saveCsv: vi.fn(),
}));

const page = (data: unknown[]) => ({
  data,
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 100,
    total: data.length,
  },
  links: { first: null, last: null, prev: null, next: null },
});

/** Every list request the page made, as its query string. */
let listQueries: URLSearchParams[] = [];

function renderAt(search: string) {
  window.history.replaceState(null, "", `/employees${search}`);
  listQueries = [];
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", name: "HR", role: "hr_admin" },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: ["employee.viewAny", "employee.create"],
      }),
    ),
    http.get("*/api/v1/employees", ({ request }) => {
      const params = new URL(request.url).searchParams;
      if (params.get("per_page") === "25") listQueries.push(params);
      return HttpResponse.json(page([buildEmployee()]));
    }),
    http.get("*/api/v1/organization/departments", () =>
      HttpResponse.json(page([{ public_id: "D1", name: "Engineering" }])),
    ),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(page([{ public_id: "B1", name: "Bole" }])),
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

const lastQuery = () => listQueries[listQueries.length - 1];

afterEach(() => {
  window.history.replaceState(null, "", "/");
});

// Filters lived in component state, and the list offered no status,
// department or branch filter at all, though the API takes all three
// (FRONTEND_REDESIGN_PLAN.md, Phase 4).
describe("Employees list filters", () => {
  it("reads its search, filters and sort from the address", async () => {
    renderAt(
      "?q=abebe&status=probation&department=D1&branch=B1&sort=-employee_code",
    );

    await screen.findByText("Abebe Kebede");
    await waitFor(() => expect(lastQuery()).toBeDefined());
    const q = lastQuery();
    expect(q.get("search")).toBe("abebe");
    expect(q.get("filter[status]")).toBe("probation");
    expect(q.get("filter[department_id]")).toBe("D1");
    expect(q.get("filter[branch_id]")).toBe("B1");
    expect(q.get("sort")).toBe("-employee_code");
  });

  it("ignores a status the API does not know, instead of sending it", async () => {
    renderAt("?status=bogus&sort=salary");

    await screen.findByText("Abebe Kebede");
    await waitFor(() => expect(lastQuery()).toBeDefined());
    expect(lastQuery().get("filter[status]")).toBeNull();
    expect(lastQuery().get("sort")).toBe("name");
  });

  it("sends no filter while every filter is at All", async () => {
    renderAt("");

    await screen.findByText("Abebe Kebede");
    await waitFor(() => expect(lastQuery()).toBeDefined());
    const keys = [...lastQuery().keys()];
    expect(keys.filter((k) => k.startsWith("filter["))).toEqual([]);
    expect(lastQuery().get("search")).toBeNull();
  });

  // It sent only the search, so a list narrowed to one department exported
  // every department.
  it("exports what the filters show", async () => {
    let exported: URLSearchParams | undefined;
    server.use(
      http.get("*/api/v1/employees/export", ({ request }) => {
        exported = new URL(request.url).searchParams;
        return HttpResponse.json({ csv: "name\n", count: 1 });
      }),
    );
    renderAt("?status=confirmed&department=D1");

    await screen.findByText("Abebe Kebede");
    fireEvent.click(screen.getByRole("button", { name: "Export" }));

    await waitFor(() => expect(exported).toBeDefined());
    expect(exported!.get("filter[status]")).toBe("confirmed");
    expect(exported!.get("filter[department_id]")).toBe("D1");
  });

  // The "records to fix" list a Next Steps row opens (?missing=tin): the set
  // the row counted, named in a banner, and exported as it is shown.
  it("lists the records with one gap, says so, and exports the same set", async () => {
    let exported: URLSearchParams | undefined;
    server.use(
      http.get("*/api/v1/employees/export", ({ request }) => {
        exported = new URL(request.url).searchParams;
        return HttpResponse.json({ csv: "name\n", count: 1 });
      }),
    );
    renderAt("?missing=tin");

    await screen.findByText("Abebe Kebede");
    await waitFor(() => expect(lastQuery()).toBeDefined());
    expect(lastQuery().get("filter[missing]")).toBe("tin");
    expect(
      screen.getByText("Showing current employees who have no TIN."),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "Export" }));
    await waitFor(() => expect(exported).toBeDefined());
    expect(exported!.get("filter[missing]")).toBe("tin");

    fireEvent.click(screen.getByRole("button", { name: "Show all employees" }));
    await waitFor(() => expect(window.location.search).toBe(""));
    await waitFor(() => expect(lastQuery().get("filter[missing]")).toBeNull());
  });

  it("ignores a gap the API does not know, instead of sending it", async () => {
    renderAt("?missing=salary");

    await screen.findByText("Abebe Kebede");
    await waitFor(() => expect(lastQuery()).toBeDefined());
    expect(lastQuery().get("filter[missing]")).toBeNull();
    expect(screen.queryByText(/Showing current employees/)).toBeNull();
  });

  it("clears the filters but keeps the sort", async () => {
    renderAt("?status=probation&branch=B1&sort=-name");

    await screen.findByText("Abebe Kebede");
    fireEvent.click(screen.getByRole("button", { name: /clear filters/i }));

    await waitFor(() => expect(window.location.search).toBe("?sort=-name"));
    await waitFor(() => expect(lastQuery().get("filter[status]")).toBeNull());
    expect(lastQuery().get("sort")).toBe("-name");
  });
});
