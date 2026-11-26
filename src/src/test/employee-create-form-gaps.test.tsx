import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { todayIn } from "@/lib/utils/date";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import NewEmployeePage from "@/app/(dashboard)/employees/new/page";
import {
  validateEmployeeField,
  validateEmployeeForm,
  type ValidatedEmployeeFields,
} from "@/features/employees/new-employee-validation";

/**
 * The Add employee form against `StoreEmployeeRequest` and
 * `EmployeeController::store`: pay data is sent only by someone who may set it
 * and only when filled in, grade and probation end are offered, the rules a
 * person can trip over are read in the browser, and a form with input in it is
 * not dropped silently.
 */

const router = vi.hoisted(() => ({ push: vi.fn(), back: vi.fn() }));
vi.mock("next/navigation", () => ({
  useRouter: () => router,
  useSearchParams: () => new URLSearchParams(),
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

const PAY = ["employee.create", "employee.updateFinancial"];
const NO_PAY = ["employee.create"];

function renderPage(
  permissions: string[],
  grades: { public_id: string; name: string }[] = [],
) {
  localStorage.setItem("ethr.calendar", "gregorian");
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "U1", role: "hr_admin" },
        permissions,
        tenant: { timezone: "Africa/Addis_Ababa" },
      }),
    ),
    http.get("*/api/v1/employees/next-code", () =>
      HttpResponse.json({ employee_code: "EMP-0042", format: "EMP-{SEQ:4}" }),
    ),
    http.get("*/api/v1/organization/grades", () =>
      HttpResponse.json(paginated(grades)),
    ),
    http.get("*/api/v1/organization/:kind", () =>
      HttpResponse.json(paginated([])),
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

function capture(status = 201) {
  const seen: { body?: Record<string, unknown>; count: number } = { count: 0 };
  server.use(
    http.post("*/api/v1/employees", async ({ request }) => {
      seen.count += 1;
      seen.body = (await request.json()) as Record<string, unknown>;
      return HttpResponse.json(buildEmployee(), { status });
    }),
  );
  return seen;
}

function type(id: string, value: string) {
  fireEvent.change(document.getElementById(id)!, { target: { value } });
}

function fillRequired() {
  type("name", "Hana Girma");
  type("hire_date", "2026-10-01");
}

function submit(container: HTMLElement) {
  fireEvent.submit(container.querySelector("form")!);
}

beforeEach(() => {
  router.push.mockClear();
});

describe("New employee form, pay fields", () => {
  it("sends no salary and no TIN when both are left blank", async () => {
    const seen = capture();
    const { container } = renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    fillRequired();
    submit(container);

    await waitFor(() => expect(seen.body).toBeDefined());
    expect(seen.body).not.toHaveProperty("salary_cents");
    expect(seen.body).not.toHaveProperty("tin");
  });

  it("sends the salary and TIN that were typed", async () => {
    const seen = capture();
    const { container } = renderPage(PAY);
    fireEvent.change(await screen.findByLabelText(/Monthly Salary/), {
      target: { value: "5000.50" },
    });
    type("tin", "0012345678");
    fillRequired();
    submit(container);

    await waitFor(() => expect(seen.body).toBeDefined());
    expect(seen.body).toMatchObject({
      salary_cents: 500050,
      tin: "0012345678",
    });
  });

  it("offers no pay inputs to someone who may not set pay, and sends none", async () => {
    const seen = capture();
    const { container } = renderPage(NO_PAY);
    expect(
      await screen.findByText(
        "Salary and TIN are added by someone who manages pay.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText(/Monthly Salary/)).not.toBeInTheDocument();
    expect(document.getElementById("tin")).toBeNull();
    // Not gated by the server, so not hidden.
    expect(document.getElementById("national_id")).not.toBeNull();

    type("national_id", "ETH-1");
    fillRequired();
    submit(container);

    await waitFor(() => expect(seen.body).toBeDefined());
    expect(seen.body).not.toHaveProperty("salary_cents");
    expect(seen.body).not.toHaveProperty("tin");
    expect(seen.body).toMatchObject({ national_id: "ETH-1" });
  });
});

describe("New employee form, grade and probation end", () => {
  it("shows no grade picker in an organisation without grades", async () => {
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    expect(document.getElementById("grade_id")).toBeNull();
    expect(document.getElementById("probation_end_date")).not.toBeNull();
  });

  it("sends the grade and the probation end date that were chosen", async () => {
    const seen = capture();
    const { container } = renderPage(PAY, [
      { public_id: "01GRADEG1000000000000000AA", name: "G1" },
    ]);
    await waitFor(() =>
      expect(document.getElementById("grade_id")).not.toBeNull(),
    );
    fireEvent.click(document.getElementById("grade_id")!);
    const option = await screen.findByText("G1", {
      selector: '[role="option"] *, [role="option"]',
    });
    fireEvent.click(option.closest('[role="option"]') ?? option);
    fillRequired();
    type("probation_end_date", "2026-12-31");
    submit(container);

    await waitFor(() => expect(seen.body).toBeDefined());
    expect(seen.body).toMatchObject({
      grade_id: "01GRADEG1000000000000000AA",
      probation_end_date: "2026-12-31",
    });
  }, 30_000);

  it("sends neither when they are left empty", async () => {
    const seen = capture();
    const { container } = renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    fillRequired();
    submit(container);
    await waitFor(() => expect(seen.body).toBeDefined());
    expect(seen.body?.grade_id ?? null).toBeNull();
    expect(seen.body?.probation_end_date ?? null).toBeNull();
  });
});

describe("New employee form, probation status", () => {
  async function sendWith(probationEnd: string) {
    const seen = capture();
    const { container } = renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    fillRequired();
    if (probationEnd) type("probation_end_date", probationEnd);
    submit(container);
    await waitFor(() => expect(seen.body).toBeDefined());
    return seen.body!;
  }

  it("starts the person on probation when the end date is ahead", async () => {
    expect(await sendWith("2099-01-01")).toMatchObject({
      status: "probation",
      probation_end_date: "2099-01-01",
    });
  });

  it("starts the person on probation when the end date is today", async () => {
    const today = todayIn("Africa/Addis_Ababa");
    expect(await sendWith(today)).toMatchObject({ status: "probation" });
  });

  it("sends no status when the end date has passed", async () => {
    const body = await sendWith("2026-10-15");
    expect(body).not.toHaveProperty("status");
    expect(body).toMatchObject({ probation_end_date: "2026-10-15" });
  });

  it("sends no status without an end date", async () => {
    expect(await sendWith("")).not.toHaveProperty("status");
  });

  it("says what a date does, always", async () => {
    renderPage(PAY);
    expect(
      await screen.findByText(
        /the person starts on probation and HR is reminded/,
      ),
    ).toBeInTheDocument();
  });
});

describe("New employee form, inline checks", () => {
  it("names a malformed email at the field when it is left, and clears it on edit", async () => {
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    type("email", "hana.example.com");
    fireEvent.blur(document.getElementById("email")!);

    const message = await screen.findByText("Enter a valid email address");
    expect(message).toHaveAttribute("id", "email-error");
    const field = document.getElementById("email")!;
    expect(field).toHaveAttribute("aria-invalid", "true");
    expect(field.getAttribute("aria-describedby")).toContain("email-error");

    type("email", "hana@example.com");
    expect(screen.queryByText("Enter a valid email address")).toBeNull();
  });

  it("does not post a form that breaks a rule, and focuses the first field that does", async () => {
    const seen = capture();
    const { container } = renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    fillRequired();
    type("email", "nope");
    type("probation_end_date", "2026-09-01");
    submit(container);

    expect(
      await screen.findByText("Enter a valid email address"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("The end date cannot fall before the start date"),
    ).toBeInTheDocument();
    expect(document.activeElement).toBe(document.getElementById("email"));
    expect(seen.count).toBe(0);
  });

  it("refuses a badge with a space and a person under 15 on their hire date", async () => {
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    type("hire_date", "2026-10-01");
    type("date_of_birth", "2012-01-01");
    fireEvent.blur(document.getElementById("date_of_birth")!);
    expect(
      await screen.findByText(
        "A person must be at least 15 years old on their hire date",
      ),
    ).toBeInTheDocument();

    type("badge_number", "12 34");
    fireEvent.blur(document.getElementById("badge_number")!);
    expect(
      await screen.findByText("Use letters, numbers and hyphens only"),
    ).toBeInTheDocument();
  });

  it("re-reads a date message against a corrected hire date", async () => {
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    type("hire_date", "2026-10-01");
    type("probation_end_date", "2026-09-01");
    fireEvent.blur(document.getElementById("probation_end_date")!);
    await screen.findByText("The end date cannot fall before the start date");

    type("hire_date", "2026-08-01");
    expect(
      screen.queryByText("The end date cannot fall before the start date"),
    ).toBeNull();
  });
});

describe("validateEmployeeField", () => {
  const t = (_key: string, fallback?: string) => fallback ?? _key;
  const base: ValidatedEmployeeFields = {
    name: "A",
    name_am: "",
    email: "",
    employee_code: "",
    badge_number: "",
    date_of_birth: "",
    nationality: "",
    national_id: "",
    tin: "",
    hire_date: "2026-10-01",
    probation_end_date: "",
  };
  const now = new Date("2026-11-24T10:00:00Z");
  const check = (patch: Partial<ValidatedEmployeeFields>) =>
    validateEmployeeForm({ ...base, ...patch }, t, now);

  it("accepts everything the server accepts at the edges", () => {
    expect(check({ email: "a@b" })).toEqual({});
    expect(check({ name: "x".repeat(255) })).toEqual({});
    expect(check({ national_id: "x".repeat(50), tin: "1".repeat(20) })).toEqual(
      {},
    );
    // Exactly 15 on the hire date, and a probation ending on the hire date.
    expect(check({ date_of_birth: "2011-10-01" })).toEqual({});
    expect(check({ probation_end_date: "2026-10-01" })).toEqual({});
    // A hire date exactly a year ahead.
    expect(check({ hire_date: "2027-11-24" })).toEqual({});
    // A badge with a hyphen and a code at the limit.
    expect(
      check({ badge_number: "AB-12", employee_code: "c".repeat(30) }),
    ).toEqual({});
  });

  it("flags each rule one step past its limit", () => {
    expect(check({ name: "x".repeat(256) }).name).toBeDefined();
    expect(check({ name_am: "ሀ".repeat(256) }).name_am).toBeDefined();
    expect(check({ email: "a".repeat(251) + "@b.co" }).email).toBeDefined();
    expect(check({ nationality: "x".repeat(101) }).nationality).toBeDefined();
    expect(check({ national_id: "x".repeat(51) }).national_id).toBeDefined();
    expect(check({ tin: "1".repeat(21) }).tin).toBeDefined();
    expect(check({ badge_number: "b".repeat(33) }).badge_number).toBeDefined();
    expect(check({ date_of_birth: "2011-10-02" }).date_of_birth).toBeDefined();
    expect(check({ date_of_birth: "2027-01-01" }).date_of_birth).toBeDefined();
    expect(check({ hire_date: "2027-11-26" }).hire_date).toBeDefined();
    expect(
      check({ probation_end_date: "2026-09-30" }).probation_end_date,
    ).toBeDefined();
  });

  it("checks one field on its own", () => {
    expect(
      validateEmployeeField("email", { ...base, email: "x" }, t, now),
    ).toBe("Enter a valid email address");
  });
});

describe("New employee form, leaving with input", () => {
  /** A click on a link, as the sidebar and Cancel are; false when stopped. */
  function clickLeave(): boolean {
    const link = document.querySelector('a[href="/employees"]')!;
    return fireEvent.click(link);
  }

  async function armed() {
    // The guard arms in an effect after the first edit.
    await waitFor(() => expect(clickLeave()).toBe(false));
  }

  it("does not stop a link while the form is untouched", async () => {
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    clickLeave();
    expect(screen.queryByText("Leave without saving?")).toBeNull();
  });

  it("stops a link once the form has input and asks first", async () => {
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    type("name", "Hana");
    await armed();
    expect(
      await screen.findByText("Leave without saving?"),
    ).toBeInTheDocument();
    expect(router.push).not.toHaveBeenCalled();
  });

  it("stays on the form when the person keeps editing", async () => {
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    type("name", "Hana");
    await armed();
    fireEvent.click(await screen.findByText("Keep editing"));
    await waitFor(() =>
      expect(screen.queryByText("Leave without saving?")).toBeNull(),
    );
    expect(router.push).not.toHaveBeenCalled();
    expect(document.getElementById("name")).toHaveValue("Hana");
  });

  it("goes where the link led when the person leaves", async () => {
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    type("name", "Hana");
    await armed();
    fireEvent.click(await screen.findByText("Leave"));
    await waitFor(() => expect(router.push).toHaveBeenCalledWith("/employees"));
  });

  it("does not stop a link after a save", async () => {
    const seen = capture();
    const { container } = renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    fillRequired();
    submit(container);
    await waitFor(() => expect(router.push).toHaveBeenCalled());
    expect(seen.count).toBe(1);
    await waitFor(() => expect(clickLeave()).toBe(true));
    expect(screen.queryByText("Leave without saving?")).toBeNull();
  });

  it("does not stop a link after Save and add another", async () => {
    const seen = capture();
    renderPage(PAY);
    await screen.findByLabelText(/Monthly Salary/);
    fillRequired();
    fireEvent.click(screen.getByText("Save and add another"));
    await waitFor(() =>
      expect(document.getElementById("name")).toHaveValue(""),
    );
    expect(seen.count).toBe(1);
    await waitFor(() => expect(clickLeave()).toBe(true));
    expect(screen.queryByText("Leave without saving?")).toBeNull();
  });
});
