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

async function pick(trigger: string, option: string) {
  fireEvent.click(screen.getByRole("combobox", { name: trigger }));
  fireEvent.click(await screen.findByRole("option", { name: option }));
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

    fireEvent.change(screen.getByRole("textbox", { name: /Full Name/ }), {
      target: { value: "Hana Girma" },
    });
    fireEvent.change(
      screen.getByRole("spinbutton", { name: /Monthly Salary/ }),
      {
        target: { value: "5000.50" },
      },
    );

    // Wait for the pickers to have their options.
    await waitFor(() =>
      expect(
        screen.getByRole("combobox", { name: "Department" }),
      ).toBeEnabled(),
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
  });

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
    fireEvent.change(screen.getByRole("textbox", { name: /Full Name/ }), {
      target: { value: "Hana Girma" },
    });
    fireEvent.submit(container.querySelector("form")!);

    expect(
      await screen.findByText("The selected department id is invalid."),
    ).toBeInTheDocument();
  });
});
