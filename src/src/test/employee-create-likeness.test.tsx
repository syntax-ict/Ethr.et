import { describe, it, expect, vi, beforeEach } from "vitest";
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
import { employeeLikeness } from "@/features/employees/likeness";

/**
 * One employee record per person. `POST /employees` answers 409 when the
 * person being added looks like someone the organisation already has
 * (EmployeeIntake), and the form has to say who, and what can be done: a
 * toast with the sentence is gone in seconds and offers nothing.
 */

const router = vi.hoisted(() => ({ push: vi.fn(), back: vi.fn() }));
const permissions = vi.hoisted(() => ({ deleteEmployees: false }));

vi.mock("next/navigation", () => ({
  useRouter: () => router,
  useSearchParams: () => new URLSearchParams(),
}));
vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({ can: permissions, hasPermission: () => false }),
}));

function empty() {
  return {
    data: [],
    meta: {
      current_page: 1,
      last_page: 1,
      per_page: 100,
      total: 0,
      from: 1,
      to: 0,
    },
    links: { first: "", last: "", prev: null, next: null },
  };
}

function problem(
  code: "employee-exists" | "employee-needs-confirmation",
  outcome: string,
  detail: string,
  reasons: string[],
) {
  return HttpResponse.json(
    {
      type: `https://ethr.et/errors/${code}`,
      title: "Employee Already Exists",
      status: 409,
      detail,
      code,
      outcome,
      candidates: [
        {
          public_id: "01ABEBE0000000000000000000",
          name: "Abebe Kebede",
          reasons,
        },
      ],
    },
    { status: 409 },
  );
}

/** Render the form, fill the two required fields and submit. */
function submitForm() {
  localStorage.setItem("ethr.calendar", "gregorian");
  server.use(
    http.get("*/api/v1/employees/next-code", () =>
      HttpResponse.json({ employee_code: "EMP-0042", format: "EMP-{SEQ:4}" }),
    ),
    http.get("*/api/v1/organization/departments", () =>
      HttpResponse.json(empty()),
    ),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(empty()),
    ),
    http.get("*/api/v1/organization/positions", () =>
      HttpResponse.json(empty()),
    ),
    http.get("*/api/v1/organization/teams", () => HttpResponse.json(empty())),
    http.get("*/api/v1/organization/cost-centers", () =>
      HttpResponse.json(empty()),
    ),
  );

  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const { container } = render(
    <QueryClientProvider client={qc}>
      <CalendarProvider>
        <NewEmployeePage />
      </CalendarProvider>
    </QueryClientProvider>,
  );

  fireEvent.change(screen.getByLabelText(/Full Name/), {
    target: { value: "Sara Tesfaye" },
  });
  fireEvent.change(document.getElementById("hire_date")!, {
    target: { value: "2026-10-01" },
  });
  fireEvent.submit(container.querySelector("form")!);
}

beforeEach(() => {
  router.push.mockClear();
  permissions.deleteEmployees = false;
});

describe("New employee form, someone already here", () => {
  it("names a certain match and offers their record, not a way round it", async () => {
    server.use(
      http.post("*/api/v1/employees", () =>
        problem(
          "employee-exists",
          "matched",
          "Abebe Kebede is already an employee here. Open their record to make changes.",
          ["email"],
        ),
      ),
    );

    submitForm();

    expect(await screen.findByText("Already an employee")).toBeInTheDocument();
    expect(
      screen.getByText(/Abebe Kebede is already an employee here/),
    ).toBeInTheDocument();
    expect(screen.getByText("email")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Open their record" }),
    ).toHaveAttribute("href", "/employees/01ABEBE0000000000000000000");
    expect(screen.queryByText("Add as a new person")).not.toBeInTheDocument();
    expect(router.push).not.toHaveBeenCalled();
  }, 45_000);

  it("asks about a likeness, and sends the same form again once the person confirms", async () => {
    const bodies: Record<string, unknown>[] = [];
    server.use(
      http.post("*/api/v1/employees", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        bodies.push(body);
        return body.confirm_new === true
          ? HttpResponse.json(buildEmployee(), { status: 201 })
          : problem(
              "employee-needs-confirmation",
              "needs_review",
              "This may be Abebe Kebede, who is already an employee here. Add them only if this is a different person.",
              ["phone"],
            );
      }),
    );

    submitForm();

    expect(await screen.findByText("Is this someone new?")).toBeInTheDocument();
    expect(screen.getByText("phone")).toBeInTheDocument();

    fireEvent.click(screen.getByText("Add as a new person"));

    await waitFor(() => expect(bodies).toHaveLength(2));
    // The same form, with the one answer added.
    expect(bodies[0]).not.toHaveProperty("confirm_new");
    expect(bodies[1]).toMatchObject({ ...bodies[0], confirm_new: true });

    await waitFor(() => expect(router.push).toHaveBeenCalled());
    expect(screen.queryByText("Is this someone new?")).not.toBeInTheDocument();
  }, 45_000);

  it("offers to restore a deleted record to someone who may delete", async () => {
    permissions.deleteEmployees = true;
    let restored: string | undefined;
    server.use(
      http.post("*/api/v1/employees", () =>
        problem(
          "employee-needs-confirmation",
          "archived",
          "Abebe Kebede was deleted from ETHR and their record is kept. Restore that record, or add them as a new person.",
          ["email", "archived"],
        ),
      ),
      http.post("*/api/v1/employees/:id/restore", ({ params }) => {
        restored = String(params.id);
        return HttpResponse.json(buildEmployee());
      }),
    );

    submitForm();

    // A deleted record has no page to open, so its name is not a link.
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("Abebe Kebede")).toBeInTheDocument();
    expect(
      within(dialog).queryByRole("link", { name: "Abebe Kebede" }),
    ).toBeNull();
    expect(screen.getByText("Add as a new person")).toBeInTheDocument();

    fireEvent.click(screen.getByText("Restore their record"));

    await waitFor(() => expect(restored).toBe("01ABEBE0000000000000000000"));
    await waitFor(() =>
      expect(router.push).toHaveBeenCalledWith(
        "/employees/01ABEBE0000000000000000000",
      ),
    );
  }, 45_000);

  it("says who can restore, to someone who may not", async () => {
    server.use(
      http.post("*/api/v1/employees", () =>
        problem(
          "employee-needs-confirmation",
          "archived",
          "Abebe Kebede was deleted from ETHR and their record is kept.",
          ["archived"],
        ),
      ),
    );

    submitForm();

    expect(
      await screen.findByText(
        "Someone who may delete employees can restore this record.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByText("Restore their record")).not.toBeInTheDocument();
  }, 45_000);
});

describe("employeeLikeness", () => {
  const response = (status: number, data: unknown) => ({
    response: { status, data },
  });

  it("reads the two 409s the add form can get", () => {
    expect(
      employeeLikeness(
        response(409, {
          code: "employee-needs-confirmation",
          outcome: "former_employee",
          detail: "Abebe Kebede worked here before and has left.",
          candidates: [
            { public_id: "01A", name: "Abebe Kebede", reasons: ["email", 7] },
            { name: "no id" },
          ],
        }),
      ),
    ).toEqual({
      code: "employee-needs-confirmation",
      outcome: "former_employee",
      detail: "Abebe Kebede worked here before and has left.",
      candidates: [
        { public_id: "01A", name: "Abebe Kebede", reasons: ["email"] },
      ],
    });
  });

  it("is null for every other failure, so those still reach the toast", () => {
    // The seat lock's "someone else is adding" is a 409 too, and is not this.
    expect(
      employeeLikeness(
        response(409, { detail: "Another change is in progress." }),
      ),
    ).toBeNull();
    expect(
      employeeLikeness(response(422, { code: "employee-exists" })),
    ).toBeNull();
    expect(employeeLikeness(new Error("network"))).toBeNull();
    expect(employeeLikeness(undefined)).toBeNull();
  });
});
