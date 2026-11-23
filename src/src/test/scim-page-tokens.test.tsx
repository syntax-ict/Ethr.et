import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ScimSettingsPage from "@/app/(dashboard)/settings/scim/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** One row of `ApiKeyController::index`'s `keys` array. */
function key(over: Record<string, unknown>) {
  return {
    public_id: "KEY",
    name: "Key",
    key_prefix: "ethr_abcdefg",
    abilities: ["read"],
    last_used_at: null,
    expires_at: null,
    is_active: true,
    created_at: "2026-09-01T06:00:00Z",
    ...over,
  };
}

const OKTA = key({
  public_id: "SCIM1",
  name: "Okta SCIM",
  abilities: ["scim"],
  last_used_at: "2026-11-20T08:00:00Z",
});
const PAYROLL = key({
  public_id: "KEY1",
  name: "Payroll export",
  abilities: ["read", "payroll"],
});

function me(permissions: string[], features?: Record<string, unknown>) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role: "tenant_admin" },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
      features: features ?? null,
    }),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<ScimSettingsPage />, { wrapper: Wrapper });
}

describe("<ScimSettingsPage> tokens", () => {
  it("lists only the keys that carry the scim ability", async () => {
    server.use(
      me(["settings.manage", "apikey.manage"]),
      http.get("*/api/v1/api-keys", () =>
        HttpResponse.json({ keys: [OKTA, PAYROLL] }),
      ),
    );
    renderPage();

    expect(await screen.findByText("Okta SCIM")).toBeInTheDocument();
    expect(screen.queryByText("Payroll export")).not.toBeInTheDocument();
    expect(screen.getByText("Last used")).toBeInTheDocument();
  });

  it("revokes a token only after it is confirmed, then refreshes the list", async () => {
    let deletes = 0;
    server.use(
      me(["settings.manage", "apikey.manage"]),
      http.get("*/api/v1/api-keys", () =>
        HttpResponse.json({ keys: deletes ? [PAYROLL] : [OKTA, PAYROLL] }),
      ),
      http.delete("*/api/v1/api-keys/SCIM1", () => {
        deletes++;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    const row = (await screen.findByText("Okta SCIM")).closest("tr")!;
    await user.click(
      within(row).getByRole("button", { name: "Revoke Okta SCIM" }),
    );

    const dialog = await screen.findByRole("dialog");
    expect(deletes).toBe(0);

    await user.click(within(dialog).getByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(deletes).toBe(1));
    expect(await screen.findByText("No SCIM tokens yet")).toBeInTheDocument();
  });

  it("shows the empty state when no key carries the scim ability", async () => {
    server.use(
      me(["settings.manage", "apikey.manage"]),
      http.get("*/api/v1/api-keys", () =>
        HttpResponse.json({ keys: [PAYROLL] }),
      ),
    );
    renderPage();

    expect(await screen.findByText("No SCIM tokens yet")).toBeInTheDocument();
  });

  it("says the list failed to load rather than that there are no tokens", async () => {
    server.use(
      me(["settings.manage", "apikey.manage"]),
      http.get("*/api/v1/api-keys", () =>
        HttpResponse.json({ title: "Server Error" }, { status: 500 }),
      ),
    );
    renderPage();

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
    expect(screen.queryByText("No SCIM tokens yet")).not.toBeInTheDocument();
  });

  it("gives a user without apikey.manage one line and no list", async () => {
    let listed = 0;
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/api-keys", () => {
        listed++;
        return HttpResponse.json({ keys: [OKTA] });
      }),
    );
    renderPage();

    expect(
      await screen.findByText(
        "To revoke a token, ask an administrator who has API key access.",
      ),
    ).toBeInTheDocument();
    expect(screen.queryByText("Okta SCIM")).not.toBeInTheDocument();
    expect(screen.queryByText("Tokens in use")).not.toBeInTheDocument();
    // The server would refuse it, so the page does not ask.
    expect(listed).toBe(0);
  });

  it("says so when SCIM is switched off for the organisation", async () => {
    server.use(
      me(["settings.manage", "apikey.manage"], {
        scim: { on: false, reason: "platform_disabled" },
      }),
      http.get("*/api/v1/api-keys", () => HttpResponse.json({ keys: [OKTA] })),
    );
    renderPage();

    expect(
      await screen.findByText(/SCIM provisioning is off for your organisation/),
    ).toBeInTheDocument();
    // The tokens are kept, and can still be revoked while it is off.
    expect(await screen.findByText("Okta SCIM")).toBeInTheDocument();
  });

  it("shows no off notice when SCIM is on", async () => {
    server.use(
      me(["settings.manage", "apikey.manage"], {
        scim: { on: true, reason: "plan" },
      }),
      http.get("*/api/v1/api-keys", () => HttpResponse.json({ keys: [OKTA] })),
    );
    renderPage();

    expect(await screen.findByText("Okta SCIM")).toBeInTheDocument();
    expect(
      screen.queryByText(/SCIM provisioning is off for your organisation/),
    ).not.toBeInTheDocument();
  });
});
