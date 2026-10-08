import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { EmployeeDetail } from "@/app/(dashboard)/employees/[id]/employee-detail";

vi.mock("next/navigation", () => ({
  usePathname: () => "/employees/EMP1",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "hr_admin",
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => false,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

const page = (data: unknown[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length },
  links: { first: null, last: null, prev: null, next: null },
});

// No screen could set an employee's supervisor, team or cost centre, so the
// reporting chart was flat and teams and cost centres were never assigned
// (audit N73).
describe("editing an employee's supervisor", () => {
  it("sends the supervisor chosen", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/employees/EMP1", () =>
        HttpResponse.json(
          buildEmployee({ public_id: "EMP1", name: "Abebe Kebede" }),
        ),
      ),
      http.get("*/api/v1/employees", () =>
        HttpResponse.json(
          page([
            buildEmployee({ public_id: "EMP1", name: "Abebe Kebede" }),
            buildEmployee({ public_id: "BOSS", name: "Boss Person" }),
          ]),
        ),
      ),
      http.get("*/api/v1/organization/teams", () =>
        HttpResponse.json(page([])),
      ),
      http.get("*/api/v1/organization/cost-centers", () =>
        HttpResponse.json(page([])),
      ),
      http.put("*/api/v1/employees/EMP1", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(buildEmployee({ public_id: "EMP1" }));
      }),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <EmployeeDetail routeId="EMP1" />
      </QueryClientProvider>,
    );

    await userEvent.click(
      await screen.findByRole("button", { name: /^edit$/i }),
    );
    await userEvent.click(
      await screen.findByRole("combobox", { name: /supervisor/i }),
    );
    // The employee is not offered as their own supervisor.
    expect(
      screen.queryByRole("option", { name: "Abebe Kebede" }),
    ).not.toBeInTheDocument();
    await userEvent.click(
      await screen.findByRole("option", { name: "Boss Person" }),
    );
    await userEvent.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(body?.supervisor_id).toBe("BOSS"));
  });
});
