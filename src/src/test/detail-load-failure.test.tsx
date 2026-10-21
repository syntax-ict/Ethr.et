import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
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

function renderDetail(status: number) {
  server.use(
    http.get("*/api/v1/employees/EMP1", () =>
      HttpResponse.json({ detail: "x" }, { status }),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <EmployeeDetail routeId="EMP1" />
    </QueryClientProvider>,
  );
}

/**
 * Every failed load used to read "Employee not found", which sent people
 * looking for a record that was there all along. Only a 404 means that.
 */
describe("employee detail when the record does not load", () => {
  it("says the record was not found for a 404", async () => {
    renderDetail(404);
    expect(await screen.findByText("Employee not found")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });

  it("says the data did not load, and offers a retry, for anything else", async () => {
    renderDetail(500);
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Couldn't load this",
    );
    expect(
      screen.getByRole("button", { name: /Try again/ }),
    ).toBeInTheDocument();
    expect(screen.queryByText("Employee not found")).not.toBeInTheDocument();
  });
});
