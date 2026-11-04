import { afterEach, describe, expect, it } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DepartmentOverview } from "@/features/dashboard/components/department-overview";

/**
 * "Your department" is drawn from `/dashboard/department` for a reader whose
 * `org_scope` is a department or a team (dashboard review, 2026-11-03, PR 4).
 * A department admin had no section of their own and read "No direct
 * reports" instead.
 */
const DEPARTMENT = {
  scope: "department",
  unit: { name: "Finance", name_am: "ፋይናንስ" },
  headcount: 24,
  attendance: { present: 19, absent: 3, late: 2 },
  on_leave_today: 2,
  pending_approvals: 4,
};

function me(orgScope: string) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "01HZUSER",
          name: "Test",
          role: "dept_admin",
          org_scope: orgScope,
        },
        tenant: { public_id: "T1", name: "Demo" },
        permissions: [],
      }),
    ),
  );
}

function serve(payload: object = DEPARTMENT) {
  let requests = 0;
  server.use(
    http.get("*/api/v1/dashboard/department", () => {
      requests += 1;
      return HttpResponse.json(payload);
    }),
  );
  return () => requests;
}

function renderOverview() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <DepartmentOverview />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  localStorage.setItem("locale", "en");
});

describe("<DepartmentOverview>", () => {
  it("counts the department's people today for a department admin", async () => {
    me("department");
    serve();
    renderOverview();

    // The heading draws at once over skeleton tiles; the unit name arrives
    // with the answer, so wait for it before reading the figures.
    expect(await screen.findByText("Finance")).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Your department" }),
    ).toBeInTheDocument();
    expect(screen.getByText("19")).toBeInTheDocument();
    expect(screen.getByText("of 24")).toBeInTheDocument();
    expect(screen.getByText("3")).toBeInTheDocument();
    expect(screen.getByText("2")).toBeInTheDocument();
    expect(screen.getByText("4")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /pending approvals/i }),
    ).toHaveAttribute("href", "/approvals");
  });

  it("names the team, in Amharic, for a team-scoped role", async () => {
    localStorage.setItem("locale", "am");
    me("team");
    serve({
      ...DEPARTMENT,
      scope: "team",
      unit: { name: "Collections", name_am: "ክፍያ መሰብሰብ" },
    });
    renderOverview();

    expect(await screen.findByText("ክፍያ መሰብሰብ")).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "የእርስዎ ቡድን" }),
    ).toBeInTheDocument();
  });

  it("asks for nothing and draws nothing for a role that reaches the organisation", async () => {
    me("all");
    const requests = serve();
    renderOverview();

    await waitFor(() =>
      expect(screen.queryByRole("heading")).not.toBeInTheDocument(),
    );
    expect(requests()).toBe(0);
  });

  it("draws nothing when the admin's record has no department", async () => {
    me("department");
    serve({ ...DEPARTMENT, unit: null, headcount: 0 });
    renderOverview();

    await waitFor(() =>
      expect(screen.queryByRole("heading")).not.toBeInTheDocument(),
    );
  });
});
