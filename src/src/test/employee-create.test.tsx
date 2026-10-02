import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import NewEmployeePage from "@/app/(dashboard)/employees/new/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), back: vi.fn() }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function paginated<T>(rows: T[]) {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 100,
      total: rows.length,
      from: 1,
      to: rows.length,
    },
    links: { first: "", last: "", prev: null, next: null },
  };
}

function renderPage() {
  server.use(
    http.get("*/api/v1/organization/departments", () =>
      HttpResponse.json(
        paginated([
          { public_id: "01DEPTENGINEERING00000000", name: "Engineering" },
        ]),
      ),
    ),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(
        paginated([
          { public_id: "01BRANCHHQ000000000000000", name: "Headquarters" },
        ]),
      ),
    ),
    http.get("*/api/v1/organization/positions", () =>
      HttpResponse.json(
        paginated([
          { public_id: "01POSITIONDEV000000000000", title: "Developer" },
        ]),
      ),
    ),
  );

  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <CalendarProvider>
        <NewEmployeePage />
      </CalendarProvider>
    </QueryClientProvider>,
  );
}

/**
 * Label and text queries, not `ByRole({ name })`: role queries compute the
 * accessible name of every control on this long form on each retry, which
 * took the first test to 14.7 s alone against a 20 s timeout — and past it
 * under load.
 */
async function pick(trigger: string, option: string) {
  fireEvent.click(screen.getByLabelText(trigger));
  const text = await screen.findByText(option, {
    selector: '[role="option"] *, [role="option"]',
  });
  fireEvent.click(text.closest('[role="option"]') ?? text);
}

describe("New employee form", () => {
  it("sends the department, branch and position under the field names StoreEmployeeRequest reads", async () => {
    let body: Record<string, unknown> | undefined;
    server.use(
      http.post("*/api/v1/employees", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(buildEmployee(), { status: 201 });
      }),
    );

    const { container } = renderPage();

    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    fireEvent.change(screen.getByLabelText(/Monthly Salary/), {
      target: { value: "5000.50" },
    });

    // Wait for the pickers to have their options.
    await waitFor(() =>
      expect(screen.getByLabelText("Department")).toBeEnabled(),
    );
    await pick("Department", "Engineering");
    await pick("Branch", "Headquarters");
    await pick("Position", "Developer");

    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(body).toBeDefined());

    expect(body).toMatchObject({
      name: "Hana Girma",
      department_id: "01DEPTENGINEERING00000000",
      branch_id: "01BRANCHHQ000000000000000",
      position_id: "01POSITIONDEV000000000000",
      // Integer cents with the decimals kept, per the integer-currency rule.
      salary_cents: 500050,
    });
    expect(body).not.toHaveProperty("department_public_id");
    expect(body).not.toHaveProperty("branch_public_id");
    expect(body).not.toHaveProperty("position_public_id");
    // The whole new-employee form, three pickers and a submit: ~11 s alone,
    // and 2.3x that when the backend suite shares the machine. The global 20 s
    // left no margin; this one integration test gets its own.
  }, 45_000);

  it("puts a 422 on a relation field under that field", async () => {
    server.use(
      http.post("*/api/v1/employees", () =>
        HttpResponse.json(
          {
            type: "https://ethr.et/errors/validation",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: {
              department_id: ["The selected department id is invalid."],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const { container } = renderPage();
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    fireEvent.submit(container.querySelector("form")!);

    expect(
      await screen.findByText("The selected department id is invalid."),
    ).toBeInTheDocument();
  });
});
