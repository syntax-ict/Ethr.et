import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { QuickActions } from "@/features/dashboard/components/quick-actions";

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    can: { manageEmployees: true, processPayroll: false },
    isSupervisor: true,
  }),
}));

/**
 * An organisation's first admin often has no employee record of its own.
 * Check In and Apply for Leave only lead such an account to an error, and
 * the dashboard's "My Day" already tells it how to get a record, so the
 * header offers what it can do instead.
 */
function meWith(employeePublicId: string | null) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "U1",
          role: "tenant_admin",
          employee_public_id: employeePublicId,
        },
        tenant: { public_id: "T1", name: "Acme" },
        permissions: [],
      }),
    ),
    http.get("*/api/v1/dashboard/employee", () =>
      HttpResponse.json({ attendance_today: null }),
    ),
  );
}

function renderActions() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <QuickActions />
    </QueryClientProvider>,
  );
}

beforeEach(() => localStorage.setItem("locale", "en"));

describe("dashboard quick actions", () => {
  it("offers no check-in or leave to an account with no employee record", async () => {
    meWith(null);

    renderActions();

    expect(
      await screen.findByRole("link", { name: /add employee/i }),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(
        screen.queryByRole("link", { name: /check in/i }),
      ).not.toBeInTheDocument(),
    );
    expect(
      screen.queryByRole("link", { name: /apply for leave/i }),
    ).not.toBeInTheDocument();
  });

  it("offers them to an employee", async () => {
    meWith("EMP1");

    renderActions();

    expect(
      await screen.findByRole("link", { name: /check in/i }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /apply for leave/i }),
    ).toBeInTheDocument();
  });
});
