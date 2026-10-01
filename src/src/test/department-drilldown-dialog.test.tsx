import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DepartmentDrillDownDialog } from "@/features/dashboard/components/department-drilldown-dialog";

const DEPARTMENT_ID = "01HZDEPT00000000000000001";
const DETAIL_URL = `*/api/v1/analytics/departments/${DEPARTMENT_ID}`;

/** The array `DepartmentAnalyticsService::detail()` returns. */
function buildDetail(overrides: Record<string, unknown> = {}) {
  return {
    public_id: DEPARTMENT_ID,
    name: "Finance",
    headcount: 2,
    avg_salary_cents: 1_500_000,
    gender_breakdown: { female: 1, male: 1 },
    employees: [
      {
        public_id: "01HZEMP000000000000000001",
        name: "Almaz Bekele",
        status: "confirmed",
      },
      {
        public_id: "01HZEMP000000000000000002",
        name: "Dawit Haile",
        status: "probation",
      },
    ],
    ...overrides,
  };
}

function renderDialog(departmentPublicId: string | null) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(
    <DepartmentDrillDownDialog
      departmentPublicId={departmentPublicId}
      onOpenChange={() => {}}
    />,
    { wrapper: Wrapper },
  );
}

describe("<DepartmentDrillDownDialog>", () => {
  it("shows the department's headcount and links each employee", async () => {
    server.use(http.get(DETAIL_URL, () => HttpResponse.json(buildDetail())));
    renderDialog(DEPARTMENT_ID);

    expect(
      await screen.findByRole("heading", { name: "Finance" }),
    ).toBeInTheDocument();
    expect(screen.getByText("2")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: /Almaz Bekele/ })).toHaveAttribute(
      "href",
      "/employees/01HZEMP000000000000000001",
    );
  });

  it("asks for nothing while no department is picked", async () => {
    let called = false;
    server.use(
      http.get("*/api/v1/analytics/departments/*", () => {
        called = true;
        return HttpResponse.json(buildDetail());
      }),
    );
    renderDialog(null);

    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(called).toBe(false);
  });
});
