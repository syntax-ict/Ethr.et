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

let canReset = true;

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: new Proxy({} as Record<string, boolean>, {
      get: (_t, flag) => (flag === "resetUserMfa" ? canReset : true),
    }),
    role: "tenant_admin",
  }),
}));

function user(mfa: boolean) {
  return {
    public_id: "01HZUSER000000000000000002",
    email: "abebe@acme.et",
    username: null,
    phone: null,
    role: "employee",
    status: "active",
    locale: "en",
    mfa_enabled: mfa,
    invited_at: "2026-10-01T06:00:00Z",
    activated_at: "2026-10-01T07:00:00Z",
    last_login_at: null,
    custom_role: null,
    employee: null,
    created_at: "2026-10-01T06:00:00Z",
  };
}

function serve(mfa: boolean, onReset: (id: string) => void = () => {}) {
  server.use(
    http.get("*/api/v1/users", () =>
      HttpResponse.json({
        data: [user(mfa)],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        links: { first: null, last: null, prev: null, next: null },
      }),
    ),
    http.post("*/api/v1/users/:id/mfa/reset", ({ params }) => {
      onReset(String(params.id));
      return HttpResponse.json(user(false));
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

/**
 * Someone who lost the phone with their authenticator had no way back: no
 * recovery codes, and nothing an admin could do (2026-10-09). A tenant admin
 * now resets it from this list.
 */
describe("Users — resetting two-factor authentication", () => {
  it("resets it for a user who has it, after confirming", async () => {
    canReset = true;
    const reset: string[] = [];
    serve(true, (id) => reset.push(id));

    const ui = userEvent.setup();
    renderPage();

    expect(await screen.findByText("2FA")).toBeInTheDocument();
    await ui.click(
      screen.getByRole("button", { name: "Reset two-factor authentication" }),
    );
    const dialog = await screen.findByRole("dialog");
    expect(within(dialog).getByText("abebe@acme.et")).toBeInTheDocument();
    expect(reset).toEqual([]);

    await ui.click(within(dialog).getByRole("button", { name: "Reset" }));

    await waitFor(() => expect(reset).toEqual(["01HZUSER000000000000000002"]));
  });

  it("offers nothing to reset for a user without it", async () => {
    canReset = true;
    serve(false);
    renderPage();

    // No employee name, so the row shows the email twice.
    await screen.findAllByText("abebe@acme.et");
    expect(
      screen.queryByRole("button", { name: "Reset two-factor authentication" }),
    ).not.toBeInTheDocument();
  });

  it("is hidden from someone without the permission", async () => {
    canReset = false;
    serve(true);
    renderPage();

    // No employee name, so the row shows the email twice.
    await screen.findAllByText("abebe@acme.et");
    expect(
      screen.queryByRole("button", { name: "Reset two-factor authentication" }),
    ).not.toBeInTheDocument();
  });
});
