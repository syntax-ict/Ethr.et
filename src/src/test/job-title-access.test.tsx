import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import UsersSettingsPage from "@/app/(dashboard)/settings/users/page";
import { PositionsSection } from "@/features/organization/components/sections/positions-section";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
    role: "tenant_admin",
  }),
}));

const paged = (data: unknown[]) => ({
  data,
  meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
  links: { first: null, last: null, prev: null, next: null },
});

function account(over: Record<string, unknown> = {}) {
  return {
    public_id: "01HZUSER000000000000000001",
    email: "selam@acme.et",
    username: null,
    phone: null,
    role: "employee",
    role_follows_position: true,
    title_role: "employee",
    status: "active",
    locale: "en",
    mfa_enabled: false,
    invited_at: null,
    activated_at: "2026-10-01T07:00:00Z",
    last_login_at: null,
    custom_role: null,
    employee: { public_id: "01HZEMP", name: "Selam Tadesse" },
    created_at: "2026-10-01T06:00:00Z",
    ...over,
  };
}

function wrap() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );
  }
  return Wrapper;
}

describe("Users: the role that follows a job title", () => {
  it("tags a following account and offers Apply when the title gives another role", async () => {
    let applied = false;
    server.use(
      http.get("*/api/v1/users", () =>
        HttpResponse.json(
          paged([account({ role: "employee", title_role: "finance_admin" })]),
        ),
      ),
      http.post("*/api/v1/users/:id/title-access", () => {
        applied = true;
        return HttpResponse.json(account({ role: "finance_admin" }));
      }),
    );
    render(<UsersSettingsPage />, { wrapper: wrap() });

    expect((await screen.findAllByText("From job title"))[0]).toBeVisible();
    expect(
      screen.getAllByText(
        /Job title gives Finance admin\. Waiting for someone who may give that role\./,
      )[0],
    ).toBeVisible();

    await userEvent.click(screen.getAllByRole("button", { name: "Apply" })[0]);
    await waitFor(() => expect(applied).toBe(true));
  });

  it("shows no waiting line when the role already matches the title", async () => {
    server.use(
      http.get("*/api/v1/users", () =>
        HttpResponse.json(paged([account({ title_role: "employee" })])),
      ),
    );
    render(<UsersSettingsPage />, { wrapper: wrap() });

    await screen.findAllByText("From job title");
    expect(screen.queryByText(/Waiting for someone/)).toBeNull();
  });

  it("asks the API for the accounts that differ from their title", async () => {
    const asked: string[] = [];
    server.use(
      http.get("*/api/v1/users", ({ request }) => {
        asked.push(new URL(request.url).search);
        return HttpResponse.json(paged([account()]));
      }),
    );
    render(<UsersSettingsPage />, { wrapper: wrap() });

    await userEvent.click(
      await screen.findByRole("button", { name: "Differs from job title" }),
    );
    await waitFor(() =>
      expect(
        asked.some((q) => q.includes("filter%5Btitle_access%5D=held")),
      ).toBe(true),
    );
  });

  it("sends the flag, not a role, when the choice is Follows job title, and disables the role", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/users", () =>
        HttpResponse.json(
          paged([
            account({ role: "supervisor", role_follows_position: false }),
          ]),
        ),
      ),
      http.patch("*/api/v1/users/:id", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(account());
      }),
    );
    render(<UsersSettingsPage />, { wrapper: wrap() });

    await userEvent.click(
      await screen.findByRole("button", { name: /^Edit / }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("radio", { name: "Follows job title" }),
    );
    expect(within(dialog).getAllByRole("combobox")[0]).toBeDisabled();
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ role_follows_position: true });
    expect(body).not.toHaveProperty("role");
  });

  it("sends the role and ends following when set by hand", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/users", () =>
        HttpResponse.json(paged([account({ role_follows_position: true })])),
      ),
      http.patch("*/api/v1/users/:id", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(account());
      }),
    );
    render(<UsersSettingsPage />, { wrapper: wrap() });

    await userEvent.click(
      await screen.findByRole("button", { name: /^Edit / }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("radio", { name: "Set by hand" }),
    );
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({
      role: "employee",
      role_follows_position: false,
    });
  });
});

describe("Job titles: the access a title gives", () => {
  const position = (over: Record<string, unknown> = {}) => ({
    public_id: "01HZPOS0001",
    title: "Accountant",
    title_am: null,
    code: "ACC",
    description: null,
    is_active: true,
    access_role: null,
    employees_count: 4,
    ...over,
  });

  function servePositions(rows: unknown[], onPatch?: (b: unknown) => void) {
    server.use(
      http.get("*/api/v1/organization/positions", () =>
        HttpResponse.json(paged(rows)),
      ),
      http.put("*/api/v1/organization/positions/:id", async ({ request }) => {
        onPatch?.(await request.json());
        return HttpResponse.json(position());
      }),
    );
  }

  it("shows the access in the table and warns how many people a change reaches", async () => {
    servePositions([position({ access_role: "dept_admin" })]);
    render(<PositionsSection />, { wrapper: wrap() });

    expect(await screen.findByText("Department admin")).toBeVisible();

    await userEvent.click(await screen.findByRole("button", { name: /edit/i }));
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).queryByText(/people hold this job title/)).toBeNull();

    await userEvent.click(
      within(dialog).getByRole("combobox", { name: "Access" }),
    );
    await userEvent.click(
      await screen.findByRole("option", { name: "Finance admin" }),
    );
    expect(
      within(dialog).getByText(
        "4 people hold this job title. Accounts that follow the job title change when you save. Accounts given a role by hand stay as they are.",
      ),
    ).toBeVisible();
  });

  it("offers the access a title's own name suggests, and never chooses it", async () => {
    let body: Record<string, unknown> | null = null;
    servePositions(
      [position({ title: "Supervisor", employees_count: 0 })],
      (b) => {
        body = b as Record<string, unknown>;
      },
    );
    render(<PositionsSection />, { wrapper: wrap() });

    await userEvent.click(await screen.findByRole("button", { name: /edit/i }));
    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText("Give this job title Supervisor access?"),
    ).toBeVisible();

    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ access_role: null });
  });

  it("takes the suggestion with one click", async () => {
    let body: Record<string, unknown> | null = null;
    servePositions(
      [position({ title: "hr admin", employees_count: 0 })],
      (b) => {
        body = b as Record<string, unknown>;
      },
    );
    render(<PositionsSection />, { wrapper: wrap() });

    await userEvent.click(await screen.findByRole("button", { name: /edit/i }));
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: "Yes, give it" }),
    );
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ access_role: "hr_admin" });
  });
});

describe("Users: following a job title says what it will do first", () => {
  const preview = (over: Record<string, unknown> = {}) => ({
    role: "employee",
    position: "QA Engineer",
    applies: true,
    ...over,
  });

  function serve(user: Record<string, unknown>) {
    const bodies: Record<string, unknown>[] = [];
    server.use(
      http.get("*/api/v1/users", () => HttpResponse.json(paged([user]))),
      http.patch("*/api/v1/users/:id", async ({ request }) => {
        bodies.push((await request.json()) as Record<string, unknown>);
        return HttpResponse.json(account());
      }),
    );
    return bodies;
  }

  async function openAndFollow() {
    render(<UsersSettingsPage />, { wrapper: wrap() });
    await userEvent.click(
      await screen.findByRole("button", { name: /^Edit / }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("radio", { name: "Follows job title" }),
    );
    return dialog;
  }

  const hrAdmin = (over: Record<string, unknown> = {}) =>
    account({
      role: "hr_admin",
      role_follows_position: false,
      title_role: null,
      title_preview: preview(),
      ...over,
    });

  it("shows the role the title would give, or says there is no title", async () => {
    serve(hrAdmin());
    const dialog = await openAndFollow();
    expect(
      within(dialog).getByText("Job title QA Engineer gives Employee"),
    ).toBeVisible();
  });

  it("names the missing title when there is none", async () => {
    serve(hrAdmin({ title_preview: preview({ position: null }) }));
    const dialog = await openAndFollow();
    expect(
      within(dialog).getByText("No job title, so this gives Employee"),
    ).toBeVisible();
  });

  it("asks before a role change, sends nothing, and sends the flag on confirm", async () => {
    const bodies = serve(hrAdmin());
    const dialog = await openAndFollow();
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    const ask = await screen.findByText("Follow the job title?");
    expect(ask).toBeVisible();
    expect(
      screen.getByText(
        "Selam Tadesse's role changes from HR admin to Employee.",
      ),
    ).toBeVisible();
    expect(bodies).toHaveLength(0);

    await userEvent.click(screen.getByRole("button", { name: "Change role" }));
    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(bodies[0]).toMatchObject({ role_follows_position: true });
    expect(bodies[0]).not.toHaveProperty("role");
  });

  it("returns to the dialog with nothing sent when the person keeps editing", async () => {
    const bodies = serve(hrAdmin());
    const dialog = await openAndFollow();
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await userEvent.click(
      await screen.findByRole("button", { name: "Keep editing" }),
    );

    await waitFor(() =>
      expect(screen.queryByText("Follow the job title?")).toBeNull(),
    );
    expect(screen.getByText("Where the role comes from")).toBeVisible();
    expect(bodies).toHaveLength(0);
  });

  it("says the role is kept when the person could not give the new one", async () => {
    serve(hrAdmin({ title_preview: preview({ applies: false }) }));
    const dialog = await openAndFollow();
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    expect(
      await screen.findByText(
        "Selam Tadesse keeps HR admin until someone who may give Employee applies it. It will show under Differs from job title.",
      ),
    ).toBeVisible();
    expect(
      screen.getByRole("button", { name: "Follow job title" }),
    ).toBeVisible();
  });

  it("saves straight away when the title gives the role the account already has", async () => {
    const bodies = serve(
      hrAdmin({ role: "employee", title_preview: preview() }),
    );
    const dialog = await openAndFollow();
    await userEvent.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(bodies).toHaveLength(1));
    expect(screen.queryByText("Follow the job title?")).toBeNull();
  });
});
