import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import CostSharingPage from "@/app/(dashboard)/payroll/cost-sharing/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
    role: "finance_admin",
  }),
}));

const EMPLOYEE = {
  public_id: "01HZEMPL000000000000000002",
  name: "Almaz Bekele",
  employee_code: "E-014",
};

const page = (data: unknown[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length },
  links: { first: null, last: null, prev: null, next: null },
});

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<CostSharingPage />, { wrapper });
}

async function fillForm(user: ReturnType<typeof userEvent.setup>) {
  await user.click(
    await screen.findByRole("button", { name: /New Obligation/ }),
  );
  const picker = await screen.findByRole("combobox", { name: "Employee" });
  await waitFor(async () => {
    await user.click(picker);
    await user.click(
      await screen.findByRole("option", { name: /Almaz Bekele/ }),
    );
  });
  await user.type(screen.getByLabelText("Total Obligation (ETB)"), "45000");
  await user.type(screen.getByLabelText("Deduction Rate (%)"), "10");
  await user.type(screen.getByLabelText("Started On"), "2026-09-01");
}

/**
 * Recording an obligation asked finance to paste the employee's public_id,
 * which no screen shows (2026-10-09). The employee is now picked by name, and
 * a refusal says why instead of "Failed to record obligation".
 */
describe("Cost sharing — new obligation", () => {
  beforeEach(() => vi.mocked(toast.error).mockClear());

  it("records it against the employee picked by name", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/payroll/cost-sharing", () =>
        HttpResponse.json(page([])),
      ),
      http.get("*/api/v1/employees", () => HttpResponse.json(page([EMPLOYEE]))),
      http.post("*/api/v1/payroll/cost-sharing", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({}, { status: 201 });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Record Obligation" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      employee_public_id: EMPLOYEE.public_id,
      total_obligation_cents: 4_500_000,
      deduction_rate_percent: 10,
      started_on: "2026-09-01",
    });
  });

  it("shows the server's reason when it refuses", async () => {
    server.use(
      http.get("*/api/v1/payroll/cost-sharing", () =>
        HttpResponse.json(page([])),
      ),
      http.get("*/api/v1/employees", () => HttpResponse.json(page([EMPLOYEE]))),
      http.post("*/api/v1/payroll/cost-sharing", () =>
        HttpResponse.json(
          {
            detail: "The given data was invalid.",
            errors: {
              employee_public_id: [
                "This employee already has an active obligation.",
              ],
            },
          },
          { status: 422 },
        ),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    await fillForm(user);
    await user.click(screen.getByRole("button", { name: "Record Obligation" }));

    await waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "This employee already has an active obligation.",
      ),
    );
  });
});
