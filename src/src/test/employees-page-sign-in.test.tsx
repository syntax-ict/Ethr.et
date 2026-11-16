import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import EmployeesPage from "@/app/(dashboard)/employees/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const page = (data: unknown[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
  links: { first: null, last: null, prev: null, next: null },
});

function renderAt(search: string, rows: unknown[]) {
  window.history.replaceState(null, "", `/employees${search}`);
  const queries: URLSearchParams[] = [];
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
      queries.push(new URL(request.url).searchParams);
      return HttpResponse.json(page(rows));
    }),
    http.get("*/api/v1/organization/departments", () =>
      HttpResponse.json(page([])),
    ),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(page([])),
    ),
  );
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={qc}>
      <EmployeesPage />
    </QueryClientProvider>,
  );
  return queries;
}

describe("employees list: who cannot sign in", () => {
  it("notes a person with no sign-in and one invited, and nobody else", async () => {
    renderAt("", [
      buildEmployee({
        public_id: "E1",
        name: "Abel None",
        sign_in: "none",
        status: "confirmed",
      }),
      buildEmployee({
        public_id: "E2",
        name: "Bete Invited",
        sign_in: "invited",
        status: "probation",
      }),
      buildEmployee({
        public_id: "E3",
        name: "Chaltu Active",
        sign_in: "active",
        status: "confirmed",
      }),
      buildEmployee({
        public_id: "E4",
        name: "Dawit Suspended",
        sign_in: "suspended",
      }),
      buildEmployee({
        public_id: "E5",
        name: "Eden Resigned",
        sign_in: "none",
        status: "resigned",
      }),
    ]);

    expect(await screen.findByText("Abel None")).toBeInTheDocument();
    expect(screen.getAllByText("No sign-in")).toHaveLength(1);
    expect(screen.getAllByText("Invited, not activated")).toHaveLength(1);
  });

  it("opens the list for ?missing=sign_in, asks the server for that gap and says what it shows", async () => {
    const queries = renderAt("?missing=sign_in", [
      buildEmployee({
        public_id: "E1",
        name: "Abel None",
        sign_in: "none",
        status: "confirmed",
      }),
    ]);

    expect(
      await screen.findByText(
        "Showing current employees who cannot sign in yet.",
      ),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(queries.some((q) => q.get("filter[missing]") === "sign_in")).toBe(
        true,
      ),
    );
  });
});
