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

// The page is gated on `users.viewAny`; grant access directly.
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
    role: "tenant_admin",
  }),
}));

/** Shaped like UserResource for an account that has not accepted its invite. */
function user(status: string) {
  return {
    public_id: "01HZUSER000000000000000001",
    email: "selam@acme.et",
    username: null,
    phone: null,
    role: "employee",
    status,
    locale: "en",
    mfa_enabled: false,
    invited_at: "2026-10-01T06:00:00Z",
    activated_at: status === "invited" ? null : "2026-10-01T07:00:00Z",
    last_login_at: null,
    custom_role: null,
    employee: null,
    created_at: "2026-10-01T06:00:00Z",
  };
}

function serve(status: string, onPatch: (b: Record<string, unknown>) => void) {
  server.use(
    http.get("*/api/v1/users", () =>
      HttpResponse.json({
        data: [user(status)],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        links: { first: null, last: null, prev: null, next: null },
      }),
    ),
    http.patch("*/api/v1/users/:id", async ({ request }) => {
      const body = (await request.json()) as Record<string, unknown>;
      onPatch(body);
      return HttpResponse.json({ ...user(status), ...body });
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

describe("Users — editing an invited account", () => {
  /**
   * The edit form had no way to hold `invited`, so it opened an invited user
   * as `active` and sent that back with every save. Renaming an invitee's
   * handle activated the account — UserController stamps `activated_at` on
   * the first `active` — and with it went the Resend invite action, which
   * only an `invited` account offers.
   */
  it("does not activate an invited user when only their username changes", async () => {
    let body: Record<string, unknown> | null = null;
    serve("invited", (b) => (body = b));

    const ui = userEvent.setup();
    renderPage();

    await ui.click(await screen.findByRole("button", { name: "Edit" }));
    const dialog = await screen.findByRole("dialog");
    await ui.type(within(dialog).getByLabelText("Username"), "selam.t");
    await ui.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ username: "selam.t" });
    expect(body).not.toHaveProperty("status");
  });

  it("still sends a status the admin chose for an active user", async () => {
    let body: Record<string, unknown> | null = null;
    serve("active", (b) => (body = b));

    const ui = userEvent.setup();
    renderPage();

    await ui.click(await screen.findByRole("button", { name: "Edit" }));
    const dialog = await screen.findByRole("dialog");
    // Role, then Status.
    await ui.click(within(dialog).getAllByRole("combobox")[1]);
    await ui.click(await screen.findByRole("option", { name: "Suspended" }));
    await ui.click(within(dialog).getByRole("button", { name: "Save" }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ status: "suspended" });
  });
});

describe("Users — the whole list", () => {
  /**
   * The API pages at 25, ordered by email, and the screen read only page 1
   * with no pager or search, so accounts past the 25th could not be reached
   * (audit N68).
   */
  it("pages and searches through the API", async () => {
    const asked: string[] = [];
    server.use(
      http.get("*/api/v1/users", ({ request }) => {
        asked.push(new URL(request.url).search);
        const page = Number(new URL(request.url).searchParams.get("page") ?? 1);
        return HttpResponse.json({
          data: [{ ...user("active"), email: `page${page}@acme.et` }],
          meta: { current_page: page, last_page: 2, per_page: 25, total: 26 },
          links: { first: null, last: null, prev: null, next: null },
        });
      }),
    );
    renderPage();

    expect(
      (await screen.findAllByText("page1@acme.et"))[0],
    ).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: /next/i }));
    expect(
      (await screen.findAllByText("page2@acme.et"))[0],
    ).toBeInTheDocument();

    await userEvent.type(
      screen.getByPlaceholderText(/search by name or email/i),
      "selam",
    );
    await waitFor(() =>
      expect(asked.some((q) => q.includes("search=selam"))).toBe(true),
    );
  });
});
