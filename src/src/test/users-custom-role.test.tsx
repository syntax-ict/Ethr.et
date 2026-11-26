import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import UsersSettingsPage from "@/app/(dashboard)/settings/users/page";

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

const SELF = "01HZUSER000000000000000009";
const OTHER = "01HZUSER000000000000000001";
const DESK = { public_id: "01HZROLE000000000000000001", name: "Leave desk" };
const OFF = {
  public_id: "01HZROLE000000000000000002",
  name: "Old role",
  is_active: false,
};

function person(
  publicId: string,
  customRole: {
    public_id: string;
    name: string;
    is_active?: boolean;
  } | null = null,
  follows = false,
) {
  return {
    public_id: publicId,
    email: publicId === SELF ? "me@acme.et" : "selam@acme.et",
    username: null,
    phone: null,
    role: "employee",
    role_follows_position: follows,
    status: "active",
    locale: "en",
    mfa_enabled: false,
    invited_at: "2026-10-01T06:00:00Z",
    activated_at: "2026-10-01T07:00:00Z",
    last_login_at: null,
    custom_role: customRole,
    employee: null,
    created_at: "2026-10-01T06:00:00Z",
  };
}

function serve(
  people: ReturnType<typeof person>[],
  onPatch: (b: Record<string, unknown>) => void = () => {},
) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({ user: { public_id: SELF }, permissions: [] }),
    ),
    http.get("*/api/v1/users/assignable-roles", () =>
      HttpResponse.json({
        data: [
          {
            ...DESK,
            description: "",
            org_scope: "all",
            permissions_count: 3,
          },
        ],
      }),
    ),
    http.get("*/api/v1/users", () =>
      HttpResponse.json({
        data: people,
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        links: { first: null, last: null, prev: null, next: null },
      }),
    ),
    http.patch("*/api/v1/users/:id", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      onPatch(body);
      return HttpResponse.json({ ...people[0], ...body });
    }),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<UsersSettingsPage />, { wrapper });
}

describe("Users: giving a custom role", () => {
  it("offers the assignable roles and sends custom_role_id only when it changed", async () => {
    let body: Record<string, unknown> | null = null;
    serve([person(OTHER)], (b) => (body = b));
    const ui = userEvent.setup();
    renderPage();

    await ui.click(await screen.findByRole("button", { name: /^Edit / }));
    const dialog = await screen.findByRole("dialog");
    await ui.click(within(dialog).getByLabelText("Custom role"));
    expect(
      await screen.findByRole("option", {
        name: /None \(built-in role only\)/,
      }),
    ).toBeInTheDocument();
    await ui.click(await screen.findByRole("option", { name: /Leave desk/ }));
    expect(
      within(dialog).getByText(/replace the built-in role's/),
    ).toBeInTheDocument();
    await ui.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ custom_role_id: DESK.public_id });
  });

  it("leaves custom_role_id out when it was not touched", async () => {
    let body: Record<string, unknown> | null = null;
    serve([person(OTHER, DESK)], (b) => (body = b));
    const ui = userEvent.setup();
    renderPage();

    await ui.click(await screen.findByRole("button", { name: /^Edit / }));
    const dialog = await screen.findByRole("dialog");
    await ui.type(within(dialog).getByLabelText("Username"), "selam.t");
    await ui.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).not.toHaveProperty("custom_role_id");
  });

  it("sends null to take the role away", async () => {
    let body: Record<string, unknown> | null = null;
    serve([person(OTHER, DESK)], (b) => (body = b));
    const ui = userEvent.setup();
    renderPage();

    await ui.click(await screen.findByRole("button", { name: /^Edit / }));
    const dialog = await screen.findByRole("dialog");
    await ui.click(within(dialog).getByLabelText("Custom role"));
    await ui.click(
      await screen.findByRole("option", {
        name: /None \(built-in role only\)/,
      }),
    );
    await ui.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ custom_role_id: null });
  });

  it("does not offer the select on your own row", async () => {
    serve([person(SELF)]);
    const ui = userEvent.setup();
    renderPage();

    // The identity loads on its own; wait for the row, then open it.
    await ui.click(await screen.findByRole("button", { name: /^Edit / }));
    const dialog = await screen.findByRole("dialog");
    await waitFor(() =>
      expect(within(dialog).queryByLabelText("Custom role")).toBeNull(),
    );
  });

  it("shows the custom role as a badge on the row", async () => {
    serve([person(OTHER, DESK)]);
    renderPage();

    expect((await screen.findAllByText("Leave desk")).length).toBeGreaterThan(
      0,
    );
  });

  it("marks a custom role that is switched off, on the row and in the dialog", async () => {
    serve([person(OTHER, OFF)]);
    const ui = userEvent.setup();
    renderPage();

    expect(
      await screen.findByText(/Old role \(Inactive\)/),
    ).toBeInTheDocument();

    await ui.click(await screen.findByRole("button", { name: /^Edit / }));
    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText(/This custom role is inactive/),
    ).toBeInTheDocument();
  });

  it("says the job title does not change the built-in role while a custom role is set", async () => {
    serve([person(OTHER, DESK, true)]);
    const ui = userEvent.setup();
    renderPage();

    await ui.click(await screen.findByRole("button", { name: /^Edit / }));
    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText(/job title does not change the built-in role/),
    ).toBeInTheDocument();
  });
});
