import { afterEach, beforeEach, describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import { buildEmployee } from "./msw/handlers";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import EmployeesPage from "@/app/(dashboard)/employees/page";
import { ArchivedBulkBar } from "@/features/employees/components/archived-bulk-bar";
import { BulkActionsBar } from "@/features/employees/components/bulk-actions-bar";

/**
 * Archiving a selection of people, and restoring them from the Archived view.
 * The server decides who is archived; the screen shows a preview, asks once,
 * and says what happened, person by person for anyone it did not.
 */

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() },
}));

interface Result {
  employee_id: string;
  name: string;
  outcome: string;
  reasons: string[];
  error: string | null;
}

const page = (data: unknown[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
  links: { first: null, last: null, prev: null, next: null },
});

function me(permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "HR", role: "tenant_admin" },
      tenant: {
        public_id: "T1",
        name: "Demo",
        timezone: "Africa/Addis_Ababa",
      },
      permissions,
    }),
  );
}

function organisation() {
  return [
    http.get("*/api/v1/organization/departments", () =>
      HttpResponse.json(page([])),
    ),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(page([])),
    ),
  ];
}

function client() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
}

function wrap(node: React.ReactNode) {
  return (
    <QueryClientProvider client={client()}>
      <CalendarProvider>{node}</CalendarProvider>
    </QueryClientProvider>
  );
}

function entry(over: Partial<Result> & { employee_id: string }): Result {
  return {
    name: over.employee_id,
    outcome: "archive",
    reasons: [],
    error: null,
    ...over,
  };
}

/** What the server answers for one slice: every id archives, apart from `kept`. */
function answerArchive(
  ids: string[],
  confirm: boolean,
  kept: Record<string, string[]> = {},
) {
  const results = ids.map((id) =>
    kept[id]
      ? entry({
          employee_id: id,
          name: `Name ${id}`,
          outcome: confirm ? "kept" : "keep",
          reasons: kept[id],
        })
      : entry({
          employee_id: id,
          name: `Name ${id}`,
          outcome: confirm ? "archived" : "archive",
        }),
  );
  const archive = results.filter((r) =>
    ["archive", "archived"].includes(r.outcome),
  ).length;
  return {
    confirmed: confirm,
    archive_count: archive,
    keep_count: results.length - archive,
    already_archived: 0,
    failed: 0,
    results,
  };
}

interface Sent {
  ids: string[];
  confirm: boolean;
}

function servesArchive(kept: Record<string, string[]> = {}) {
  const sent: Sent[] = [];
  server.use(
    http.post("*/api/v1/employees/bulk-archive", async ({ request }) => {
      const body = (await request.json()) as {
        employee_ids: string[];
        confirm: boolean;
      };
      sent.push({ ids: body.employee_ids, confirm: body.confirm });
      return HttpResponse.json(
        answerArchive(body.employee_ids, body.confirm, kept),
      );
    }),
  );
  return sent;
}

const ids = (n: number) => Array.from({ length: n }, (_, i) => `E${i + 1}`);

beforeEach(() => {
  vi.mocked(toast.success).mockClear();
  vi.mocked(toast.error).mockClear();
});

afterEach(() => {
  window.history.replaceState(null, "", "/");
});

describe("The Archive button on the bulk bar", () => {
  it("is offered to someone who may delete employees", async () => {
    server.use(me(["employee.delete"]), ...organisation());
    render(wrap(<BulkActionsBar selectedIds={["E1"]} onComplete={vi.fn()} />));

    expect(
      await screen.findByRole("button", { name: "Archive" }),
    ).toBeInTheDocument();
  });

  it("is absent without the permission to delete employees", async () => {
    let asked = false;
    server.use(
      http.get("*/api/v1/auth/me", () => {
        asked = true;
        return HttpResponse.json({
          user: { public_id: "U1", name: "HR", role: "hr_admin" },
          tenant: null,
          permissions: ["employee.update"],
        });
      }),
      ...organisation(),
    );
    render(wrap(<BulkActionsBar selectedIds={["E1"]} onComplete={vi.fn()} />));

    await waitFor(() => expect(asked).toBe(true));
    await new Promise((r) => setTimeout(r, 30));
    expect(screen.queryByRole("button", { name: "Archive" })).toBeNull();
  });
});

describe("The archive dialog", () => {
  async function open(selected: string[]) {
    const user = userEvent.setup();
    const onComplete = vi.fn();
    render(
      wrap(<BulkActionsBar selectedIds={selected} onComplete={onComplete} />),
    );
    await user.click(await screen.findByRole("button", { name: "Archive" }));
    return { user, onComplete };
  }

  it("previews without writing, naming who is kept and why", async () => {
    server.use(me(["employee.delete"]), ...organisation());
    const sent = servesArchive({ E2: ["attendance", "payroll"], E3: ["self"] });
    await open(["E1", "E2", "E3"]);

    expect(
      await screen.findByText("1 person will be archived."),
    ).toBeInTheDocument();
    const kept = screen.getByRole("table");
    expect(within(kept).getByText("Name E2")).toBeInTheDocument();
    expect(
      within(kept).getByText("Has attendance records, Has been in a payroll"),
    ).toBeInTheDocument();
    expect(
      within(kept).getByText("This is your own record"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Someone who has left should be given a leaving status/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/can be restored from the Archived view/),
    ).toBeInTheDocument();

    expect(sent).toEqual([{ ids: ["E1", "E2", "E3"], confirm: false }]);
    expect(
      screen.getByRole("button", { name: "Archive 1 person" }),
    ).toBeEnabled();
  });

  it("disables the confirm button when nobody can be archived", async () => {
    server.use(me(["employee.delete"]), ...organisation());
    servesArchive({ E1: ["signed_in"], E2: ["leave"] });
    await open(["E1", "E2"]);

    expect(
      await screen.findByText("Nobody in this selection can be archived."),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Archive 0 people" }),
    ).toBeDisabled();
  });

  it("archives on the one confirm, reports the result, and clears the selection on close", async () => {
    server.use(me(["employee.delete"]), ...organisation());
    const sent = servesArchive({ E2: ["attendance"] });
    const { user, onComplete } = await open(["E1", "E2"]);

    await user.click(
      await screen.findByRole("button", { name: "Archive 1 person" }),
    );

    expect(
      await screen.findByText("1 person was archived."),
    ).toBeInTheDocument();
    expect(screen.getByText("These people were kept")).toBeInTheDocument();
    expect(sent.map((s) => s.confirm)).toEqual([false, true]);
    expect(onComplete).not.toHaveBeenCalled();

    await user.click(screen.getAllByRole("button", { name: "Close" })[0]);
    expect(onComplete).toHaveBeenCalledTimes(1);
  });

  it("previews in slices of 200, archives in slices of 50, and shows one combined answer", async () => {
    server.use(me(["employee.delete"]), ...organisation());
    const sent = servesArchive();
    const { user } = await open(ids(450));

    expect(
      await screen.findByText("450 people will be archived."),
    ).toBeInTheDocument();
    expect(sent.map((s) => [s.ids.length, s.confirm])).toEqual([
      [200, false],
      [200, false],
      [50, false],
    ]);

    await user.click(
      screen.getByRole("button", { name: "Archive 450 people" }),
    );

    expect(
      await screen.findByText("450 people were archived."),
    ).toBeInTheDocument();
    expect(sent.slice(3).map((s) => [s.ids.length, s.confirm])).toEqual([
      ...Array.from({ length: 9 }, () => [50, true]),
    ]);
  });

  it("says plainly how many were not sent when a slice fails", async () => {
    server.use(me(["employee.delete"]), ...organisation());
    let confirms = 0;
    server.use(
      http.post("*/api/v1/employees/bulk-archive", async ({ request }) => {
        const body = (await request.json()) as {
          employee_ids: string[];
          confirm: boolean;
        };
        if (body.confirm && ++confirms === 2) {
          return HttpResponse.json({ message: "Down" }, { status: 500 });
        }
        return HttpResponse.json(
          answerArchive(body.employee_ids, body.confirm),
        );
      }),
    );
    const { user } = await open(ids(450));

    await user.click(
      await screen.findByRole("button", { name: "Archive 450 people" }),
    );

    expect(
      await screen.findByText("50 people were archived."),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/stopped before 400 people were sent/),
    ).toBeInTheDocument();
    expect(toast.error).toHaveBeenCalled();
  });
});

describe("The Archived view", () => {
  function renderAt(search: string, permissions: string[]) {
    window.history.replaceState(null, "", `/employees${search}`);
    const queries: URLSearchParams[] = [];
    const archivedPerson = buildEmployee({
      public_id: "A1",
      name: "Meron Alemu",
      archived_at: "2026-11-10T09:00:00Z",
    });
    server.use(
      me(permissions),
      http.get("*/api/v1/employees", ({ request }) => {
        const params = new URL(request.url).searchParams;
        if (params.get("per_page") === "25") queries.push(params);
        return HttpResponse.json(
          page(
            params.get("filter[archived]")
              ? [archivedPerson]
              : [buildEmployee()],
          ),
        );
      }),
      ...organisation(),
    );
    render(wrap(<EmployeesPage />));
    return queries;
  }

  it("asks for the archived people, with no link, a date column and no status filter", async () => {
    const queries = renderAt("?status=archived", [
      "employee.viewAny",
      "employee.create",
      "employee.delete",
    ]);

    expect(await screen.findByText("Meron Alemu")).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: /Meron Alemu/ })).toBeNull();
    expect(
      screen.getByRole("columnheader", { name: "Archived" }),
    ).toBeInTheDocument();

    const last = queries[queries.length - 1];
    expect(last.get("filter[archived]")).toBe("1");
    expect(last.get("filter[status]")).toBeNull();
  });

  it("offers only Restore on a selection", async () => {
    const user = userEvent.setup();
    renderAt("?status=archived", [
      "employee.viewAny",
      "employee.create",
      "employee.delete",
    ]);

    await screen.findByText("Meron Alemu");
    await user.click(
      screen.getAllByRole("checkbox", { name: "Select row" })[0],
    );

    expect(
      await screen.findByRole("button", { name: "Restore" }),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /change status/i })).toBeNull();
    expect(screen.queryByRole("button", { name: "Archive" })).toBeNull();
  });

  it("is ignored by someone who may not delete employees", async () => {
    const queries = renderAt("?status=archived", [
      "employee.viewAny",
      "employee.create",
    ]);

    await screen.findByText("Abebe Kebede");
    await waitFor(() => expect(queries.length).toBeGreaterThan(0));
    for (const q of queries.slice(-1)) {
      expect(q.get("filter[archived]")).toBeNull();
    }
    expect(
      screen.getByRole("link", { name: /Abebe Kebede/ }),
    ).toBeInTheDocument();
  });
});

describe("Restoring from the Archived view", () => {
  async function restore(selected: string[]) {
    const user = userEvent.setup();
    const onComplete = vi.fn();
    render(
      wrap(<ArchivedBulkBar selectedIds={selected} onComplete={onComplete} />),
    );
    await user.click(screen.getByRole("button", { name: "Restore" }));
    return { user, onComplete };
  }

  it("lists anyone who was not restored, with the reason", async () => {
    const sent: string[][] = [];
    server.use(
      http.post("*/api/v1/employees/bulk-restore", async ({ request }) => {
        const body = (await request.json()) as { employee_ids: string[] };
        sent.push(body.employee_ids);
        return HttpResponse.json({
          restored: 1,
          failed: 1,
          results: [
            {
              employee_id: "A1",
              name: "Meron Alemu",
              outcome: "restored",
              error: null,
            },
            {
              employee_id: "A2",
              name: "Tigist Wolde",
              outcome: "failed",
              error: "Your plan has no seat left.",
            },
          ],
        });
      }),
    );
    const { user, onComplete } = await restore(["A1", "A2"]);

    await user.click(screen.getByRole("button", { name: "Restore 2 people" }));

    expect(
      await screen.findByText("1 person was restored."),
    ).toBeInTheDocument();
    expect(
      screen.getByText("These people were not restored"),
    ).toBeInTheDocument();
    expect(screen.getByText("Tigist Wolde")).toBeInTheDocument();
    expect(screen.getByText("Your plan has no seat left.")).toBeInTheDocument();
    expect(sent).toEqual([["A1", "A2"]]);

    await user.click(screen.getAllByRole("button", { name: "Close" })[0]);
    expect(onComplete).toHaveBeenCalledTimes(1);
  });

  it("sends more than 200 in slices", async () => {
    const sent: number[] = [];
    server.use(
      http.post("*/api/v1/employees/bulk-restore", async ({ request }) => {
        const body = (await request.json()) as { employee_ids: string[] };
        sent.push(body.employee_ids.length);
        return HttpResponse.json({
          restored: body.employee_ids.length,
          failed: 0,
          results: body.employee_ids.map((id) => ({
            employee_id: id,
            name: id,
            outcome: "restored",
            error: null,
          })),
        });
      }),
    );
    const { user } = await restore(ids(230));

    await user.click(
      screen.getByRole("button", { name: "Restore 230 people" }),
    );

    expect(
      await screen.findByText("230 people were restored."),
    ).toBeInTheDocument();
    expect(sent).toEqual([200, 30]);
  });
});

describe("Select all matching, on the employees page", () => {
  it("ends when the search changes, and says nothing about the old filter any more", async () => {
    window.history.replaceState(null, "", "/employees");
    const rows = [
      buildEmployee({ public_id: "E1", name: "Abel Tesfaye" }),
      buildEmployee({ public_id: "E2", name: "Bete Girma" }),
    ];
    server.use(
      me(["employee.viewAny", "employee.create", "employee.update"]),
      ...organisation(),
      http.get("*/api/v1/employees", () =>
        HttpResponse.json({
          data: rows,
          meta: { current_page: 1, last_page: 12, per_page: 2, total: 300 },
          links: { first: null, last: null, prev: null, next: null },
        }),
      ),
      http.get("*/api/v1/employees/ids", () =>
        HttpResponse.json({
          ids: ids(300),
          total: 300,
          capped: false,
          cap: 2000,
        }),
      ),
    );
    const user = userEvent.setup();
    render(wrap(<EmployeesPage />));

    await screen.findByText("Abel Tesfaye");
    await user.click(screen.getByRole("checkbox", { name: "Select all rows" }));
    await user.click(
      await screen.findByRole("button", {
        name: "Select all 300 people matching this filter",
      }),
    );
    expect(await screen.findByText("300 selected")).toBeInTheDocument();

    await user.type(
      screen.getByPlaceholderText("Search by name, email, or code..."),
      "x",
    );

    await waitFor(() =>
      expect(screen.queryByText("300 selected")).not.toBeInTheDocument(),
    );
    expect(screen.queryByText(/matching this filter/i)).toBeNull();
    expect(screen.queryByText(/\d+ selected/)).toBeNull();
  });
});
