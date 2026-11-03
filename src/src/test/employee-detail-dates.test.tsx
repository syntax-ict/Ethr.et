import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { EmployeeDetail } from "@/app/(dashboard)/employees/[id]/employee-detail";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

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

function renderDetail() {
  server.use(
    http.get("*/api/v1/employees/EMP1", () =>
      HttpResponse.json(
        buildEmployee({
          public_id: "EMP1",
          name: "Abebe Kebede",
          hire_date: "2026-11-01",
        }),
      ),
    ),
  );
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <CalendarProvider>
        <EmployeeDetail routeId="EMP1" />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  localStorage.removeItem("ethr.calendar");
});

// The hire date printed the API's "2026-11-01" whatever calendar the reader
// had chosen.
describe("the employee record's hire date", () => {
  it("is written in the Ethiopian calendar for an Ethiopian-calendar reader", async () => {
    localStorage.setItem("ethr.calendar", "ethiopian");
    renderDetail();

    expect(await screen.findByText("Tikimt 22, 2019")).toBeInTheDocument();
    expect(screen.queryByText("2026-11-01")).not.toBeInTheDocument();
  });

  it("is a formatted Gregorian date for a Gregorian reader", async () => {
    localStorage.setItem("ethr.calendar", "gregorian");
    renderDetail();

    expect(await screen.findByText("01 Nov 2026")).toBeInTheDocument();
    expect(screen.queryByText("2026-11-01")).not.toBeInTheDocument();
  });
});
