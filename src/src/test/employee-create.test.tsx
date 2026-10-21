import { describe, it, expect, vi } from "vitest";
import {
  render,
  screen,
  fireEvent,
  waitFor,
  within,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import NewEmployeePage from "@/app/(dashboard)/employees/new/page";

const router = vi.hoisted(() => ({ push: vi.fn(), back: vi.fn() }));

vi.mock("next/navigation", () => ({
  useRouter: () => router,
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
  // Gregorian, so the hire date is one date input the tests can fill; the
  // Ethiopian entry is three parts and has its own tests.
  localStorage.setItem("ethr.calendar", "gregorian");
  server.use(
    http.get("*/api/v1/employees/next-code", () =>
      HttpResponse.json({ employee_code: "EMP-0042", format: "EMP-{SEQ:4}" }),
    ),
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

    // StoreEmployeeRequest requires it, and the form now checks it first.

    fireEvent.change(document.getElementById("hire_date")!, {
      target: { value: "2026-10-01" },
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
    // StoreEmployeeRequest requires it, and the form now checks it first.
    fireEvent.change(document.getElementById("hire_date")!, {
      target: { value: "2026-10-01" },
    });
    fireEvent.submit(container.querySelector("form")!);

    expect(
      await screen.findByText("The selected department id is invalid."),
    ).toBeInTheDocument();
  });
});

/**
 * Production QA 2026-10-11, P15: the API accepted `create_login` and no screen
 * sent it, so no employee of a new organisation could ever sign in.
 */
describe("New employee form — login invitation", () => {
  function capture() {
    const seen: { body?: Record<string, unknown> } = {};
    server.use(
      http.post("*/api/v1/employees", async ({ request }) => {
        seen.body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          { ...buildEmployee(), meta: { login_invited: true } },
          { status: 201 },
        );
      }),
    );
    return seen;
  }

  function fillBasics() {
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    // StoreEmployeeRequest requires it, and the form now checks it first.
    fireEvent.change(document.getElementById("hire_date")!, {
      target: { value: "2026-10-01" },
    });
    fireEvent.change(screen.getByLabelText(/^Email/), {
      target: { value: "hana@acme.et" },
    });
  }

  it("does not invite unless asked", async () => {
    const seen = capture();
    const { container } = renderPage();
    fillBasics();

    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(seen.body).toBeDefined());
    expect(seen.body).not.toHaveProperty("create_login");
  });

  it("asks for a login, as an employee, when the box is ticked", async () => {
    const seen = capture();
    const { container } = renderPage();
    fillBasics();

    fireEvent.click(screen.getByLabelText(/Send a login invitation/));
    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(seen.body).toBeDefined());
    expect(seen.body).toMatchObject({
      create_login: true,
      user_role: "employee",
    });
  });

  it("cannot be ticked without an email", () => {
    renderPage();

    expect(screen.getByLabelText(/Send a login invitation/)).toBeDisabled();
  });
});

/**
 * The 2026-10-15 redesign: the API's own two required fields, the next code
 * as a hint, the only branch chosen, and two ways to finish.
 */
describe("New employee form, redesigned", () => {
  function capture() {
    const seen: { bodies: Record<string, unknown>[] } = { bodies: [] };
    server.use(
      http.post("*/api/v1/employees", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        seen.bodies.push(body);
        return HttpResponse.json(
          {
            ...buildEmployee(),
            public_id: "01NEWEMPLOYEE000000000000",
            name: body.name,
          },
          { status: 201 },
        );
      }),
    );
    return seen;
  }

  it("marks only the name and the hire date as required", () => {
    renderPage();

    expect(screen.getByLabelText(/Full Name/)).toHaveAttribute(
      "aria-required",
      "true",
    );
    expect(screen.getByLabelText(/^Email/)).not.toHaveAttribute(
      "aria-required",
    );
    expect(screen.getByLabelText(/^Code/)).not.toHaveAttribute("aria-required");
  });

  it("says which code a blank one will get", async () => {
    renderPage();

    expect(
      await screen.findByText("Leave blank and ETHR assigns EMP-0042."),
    ).toBeInTheDocument();
  });

  it("sends a blank code as null and chooses the only branch", async () => {
    const seen = capture();
    const { container } = renderPage();
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    // StoreEmployeeRequest requires it, and the form now checks it first.
    fireEvent.change(document.getElementById("hire_date")!, {
      target: { value: "2026-10-01" },
    });
    await screen.findAllByText("Headquarters");

    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() => expect(seen.bodies).toHaveLength(1));
    expect(seen.bodies[0]).toMatchObject({
      employee_code: null,
      branch_id: "01BRANCHHQ000000000000000",
    });
  });

  it("opens the new record after saving", async () => {
    capture();
    router.push.mockClear();
    const { container } = renderPage();
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    // StoreEmployeeRequest requires it, and the form now checks it first.
    fireEvent.change(document.getElementById("hire_date")!, {
      target: { value: "2026-10-01" },
    });

    fireEvent.submit(container.querySelector("form")!);

    await waitFor(() =>
      expect(router.push).toHaveBeenCalledWith(
        "/employees/01NEWEMPLOYEE000000000000",
      ),
    );
  });

  it("starts the next one on Save and add another", async () => {
    const seen = capture();
    router.push.mockClear();
    renderPage();
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    // StoreEmployeeRequest requires it, and the form now checks it first.
    fireEvent.change(document.getElementById("hire_date")!, {
      target: { value: "2026-10-01" },
    });

    fireEvent.click(
      screen.getByRole("button", { name: "Save and add another" }),
    );

    await waitFor(() => expect(seen.bodies).toHaveLength(1));
    await waitFor(() =>
      expect(screen.getByLabelText(/Full Name/)).toHaveValue(""),
    );
    expect(router.push).not.toHaveBeenCalled();
  });
});

// The form is one long page; a guide at the top says where each section
// stands and links to it (FRONTEND_REDESIGN_PLAN.md, Phase 4).
describe("New employee form, section guide", () => {
  it("links each section and marks the required ones until they are filled", async () => {
    renderPage();

    const guide = await screen.findByRole("list", {
      name: "Sections of the form",
    });
    const links = within(guide).getAllByRole("link");
    expect(links.map((a) => a.getAttribute("href"))).toEqual([
      "#section-person",
      "#section-contact",
      "#section-job",
      "#section-pay",
    ]);
    expect(within(guide).getAllByText("Has a required field")).toHaveLength(2);

    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });
    expect(within(guide).getAllByText("Has a required field")).toHaveLength(1);
  });

  it("sends nothing without a hire date, and says where", async () => {
    let posts = 0;
    server.use(
      http.post("*/api/v1/employees", () => {
        posts++;
        return HttpResponse.json({ public_id: "E1" }, { status: 201 });
      }),
    );
    const { container } = renderPage();
    fireEvent.change(screen.getByLabelText(/Full Name/), {
      target: { value: "Hana Girma" },
    });

    fireEvent.submit(container.querySelector("form")!);

    expect(
      await screen.findByText("This field is required"),
    ).toBeInTheDocument();
    expect(document.activeElement?.id).toBe("hire_date");
    const guide = screen.getByRole("list", { name: "Sections of the form" });
    expect(within(guide).getByText("Needs a change")).toBeInTheDocument();
    expect(posts).toBe(0);
  });
});
