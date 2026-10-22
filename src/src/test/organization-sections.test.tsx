import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import OrganizationPage from "@/app/(dashboard)/organization/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("next/navigation", () => ({
  usePathname: () => "/organization",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "tenant_admin",
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

const BRANCH = {
  public_id: "01HZBRANCH0001",
  name: "Bole Branch",
  name_am: null,
  code: "BOLE",
  city: "Addis Ababa",
  is_active: true,
  departments_count: 2,
  employees_count: 12,
};

const page = (data: unknown[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length },
  links: { first: null, last: null, prev: null, next: null },
});

function serveBranches(rows: unknown[] = [BRANCH]) {
  server.use(
    http.get("*/api/v1/organization/tree", () => HttpResponse.json([])),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json(page(rows)),
    ),
  );
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <OrganizationPage />
    </QueryClientProvider>,
  );
}

async function openBranches() {
  await userEvent.click(await screen.findByRole("tab", { name: /branches/i }));
}

// The six units on the organization page share one CrudSection
// (FRONTEND_REDESIGN_PLAN.md, Phase 3). Branches stands in for all six.
describe("organization sections", () => {
  afterEach(() => {
    vi.mocked(toast.error).mockClear();
    vi.mocked(toast.success).mockClear();
  });

  it("names a branch's counts in words, not 'dept' and 'emp'", async () => {
    serveBranches();
    renderPage();
    await openBranches();

    expect(await screen.findByText("2 departments")).toBeInTheDocument();
    expect(screen.getByText("12 employees")).toBeInTheDocument();
  });

  it("ties each label to its field", async () => {
    serveBranches();
    renderPage();
    await openBranches();
    await screen.findByText("Bole Branch");

    await userEvent.click(screen.getByRole("button", { name: "Add" }));
    const dialog = await screen.findByRole("dialog", { name: "New Branch" });

    expect(within(dialog).getByLabelText(/^Name \*$/)).toBeRequired();
    expect(within(dialog).getByLabelText("Latitude")).toHaveAttribute(
      "type",
      "number",
    );
  });

  // It said "Save failed" whatever the server answered, so a duplicate code
  // never said which field was wrong.
  it("shows the server's reason when a save is refused", async () => {
    serveBranches();
    server.use(
      http.post("*/api/v1/organization/branches", () =>
        HttpResponse.json(
          {
            type: "validation_error",
            title: "Validation Failed",
            status: 422,
            detail: "The given data was invalid.",
            errors: { code: ["The code has already been taken."] },
          },
          { status: 422 },
        ),
      ),
    );
    renderPage();
    await openBranches();
    await screen.findByText("Bole Branch");

    await userEvent.click(screen.getByRole("button", { name: "Add" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText(/^Name \*$/), "Piassa");
    await userEvent.type(within(dialog).getByLabelText("Code"), "BOLE");
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await vi.waitFor(() =>
      expect(toast.error).toHaveBeenCalledWith(
        "The code has already been taken.",
      ),
    );
    // Still open, so the fix is one field away.
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });

  it("closes the dialog and says so when a save succeeds", async () => {
    serveBranches();
    let body: unknown;
    server.use(
      http.post("*/api/v1/organization/branches", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ ...BRANCH, public_id: "01HZNEW" });
      }),
    );
    renderPage();
    await openBranches();
    await screen.findByText("Bole Branch");

    await userEvent.click(screen.getByRole("button", { name: "Add" }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(within(dialog).getByLabelText(/^Name \*$/), "Piassa");
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await vi.waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Branch created"),
    );
    expect(body).toMatchObject({ name: "Piassa", code: null, latitude: null });
    await vi.waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    );
  });

  it("shows a department's head and keeps or clears it on save", async () => {
    const HEAD = {
      public_id: "01HZEMPHEAD001",
      name: "Hanna Girma",
      employee_code: "E-007",
    };
    const DEPT = {
      public_id: "01HZDEPT0001",
      name: "Finance",
      name_am: null,
      code: "FIN",
      is_active: true,
      branch: null,
      parent: null,
      head: HEAD,
    };
    const bodies: unknown[] = [];
    serveBranches();
    server.use(
      http.get("*/api/v1/organization/departments", () =>
        HttpResponse.json(page([DEPT])),
      ),
      http.get("*/api/v1/employees", () => HttpResponse.json(page([HEAD]))),
      http.put("*/api/v1/organization/departments/:id", async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json(DEPT);
      }),
      http.post("*/api/v1/organization/departments", async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ ...DEPT, public_id: "01HZNEWDEPT" });
      }),
    );
    renderPage();
    await userEvent.click(
      await screen.findByRole("tab", { name: /departments/i }),
    );

    expect(await screen.findByText("Hanna Girma")).toBeInTheDocument();

    await userEvent.click(screen.getByRole("button", { name: /edit/i }));
    const editDialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(editDialog).getByRole("button", { name: "Save" }),
    );
    await vi.waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({
      head_employee_public_id: "01HZEMPHEAD001",
    });
    await vi.waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    );

    await userEvent.click(screen.getByRole("button", { name: "Add" }));
    const newDialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(newDialog).getByLabelText(/^Name \*$/),
      "Audit",
    );
    await userEvent.click(
      within(newDialog).getByRole("button", { name: "Save" }),
    );
    await vi.waitFor(() => expect(bodies).toHaveLength(2));
    expect(bodies[1]).toMatchObject({ head_employee_public_id: null });
  });

  it("asks before deleting, and deletes on yes", async () => {
    serveBranches();
    const deleted = vi.fn();
    server.use(
      http.delete("*/api/v1/organization/branches/:id", ({ params }) => {
        deleted(params.id);
        return new HttpResponse(null, { status: 204 });
      }),
    );
    renderPage();
    await openBranches();
    await screen.findByText("Bole Branch");

    await userEvent.click(screen.getByRole("button", { name: /delete/i }));
    const confirm = await screen.findByRole("dialog", {
      name: /delete branch/i,
    });
    expect(confirm).toHaveTextContent('Delete branch "Bole Branch"?');
    await userEvent.click(
      within(confirm).getByRole("button", { name: "Delete" }),
    );

    await vi.waitFor(() =>
      expect(deleted).toHaveBeenCalledWith(BRANCH.public_id),
    );
    await vi.waitFor(() =>
      expect(toast.success).toHaveBeenCalledWith("Deleted"),
    );
  });

  it("says the list failed instead of offering to add the first one", async () => {
    server.use(
      http.get("*/api/v1/organization/tree", () => HttpResponse.json([])),
      http.get("*/api/v1/organization/branches", () =>
        HttpResponse.json({ status: 500 }, { status: 500 }),
      ),
    );
    renderPage();
    await openBranches();

    expect(
      await screen.findByRole("button", { name: "Try again" }),
    ).toBeInTheDocument();
    expect(screen.queryByText("No branches")).not.toBeInTheDocument();
  });
});
