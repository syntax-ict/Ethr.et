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

describe("Users: the role that follows a position", () => {
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

    expect((await screen.findAllByText("From position"))[0]).toBeVisible();
    expect(
      screen.getAllByText(
        /Position gives Finance admin\. Waiting for someone who may give that role\./,
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

    await screen.findAllByText("From position");
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
      await screen.findByRole("button", { name: "Differs from position" }),
    );
    await waitFor(() =>
      expect(
        asked.some((q) => q.includes("filter%5Btitle_access%5D=held")),
      ).toBe(true),
    );
  });

  it("sends the flag, not a role, when the choice is Follows position, and disables the role", async () => {
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
      within(dialog).getByRole("radio", { name: "Follows position" }),
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

describe("Positions: the access a position gives", () => {
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
    expect(within(dialog).queryByText(/people hold this position/)).toBeNull();

    await userEvent.click(
      within(dialog).getByRole("combobox", { name: "Access" }),
    );
    await userEvent.click(
      await screen.findByRole("option", { name: "Finance admin" }),
    );
    expect(
      within(dialog).getByText(
        "4 people hold this position. Accounts that follow the position change when you save. Accounts given a role by hand stay as they are.",
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
      within(dialog).getByText("Give this position Supervisor access?"),
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
