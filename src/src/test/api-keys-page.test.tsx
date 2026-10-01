import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ApiKeysPage from "@/app/(dashboard)/settings/api-keys/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/** One row of `ApiKeyController::index`'s `keys` array. */
const KEY = {
  public_id: "KEY1",
  name: "Payroll export",
  key_prefix: "ethr_abcdefg",
  abilities: ["read", "payroll"],
  last_used_at: null,
  expires_at: null,
  is_active: true,
  created_at: "2026-09-01T06:00:00Z",
};

function me(permissions: string[], role = "tenant_admin") {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
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
  return render(<ApiKeysPage />, { wrapper: Wrapper });
}

describe("<ApiKeysPage>", () => {
  it("revokes a key only after it is confirmed", async () => {
    // Revoking is immediate and permanent; it used to happen on one click of
    // the row's icon.
    let deletes = 0;
    server.use(
      me(["apikey.manage"]),
      http.get("*/api/v1/api-keys", () =>
        HttpResponse.json({ keys: deletes ? [] : [KEY] }),
      ),
      http.delete("*/api/v1/api-keys/KEY1", () => {
        deletes++;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    const row = (await screen.findByText("Payroll export")).closest("tr")!;
    await user.click(within(row).getByRole("button", { name: "Revoke" }));

    const dialog = await screen.findByRole("dialog");
    expect(deletes).toBe(0);

    await user.click(within(dialog).getByRole("button", { name: "Revoke" }));
    await waitFor(() => expect(deletes).toBe(1));
  });

  it("says the list failed to load rather than that there are no keys", async () => {
    server.use(
      me(["apikey.manage"]),
      http.get("*/api/v1/api-keys", () =>
        HttpResponse.json({ title: "Server Error" }, { status: 500 }),
      ),
    );
    renderPage();

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
    expect(screen.queryByText("No API keys")).not.toBeInTheDocument();
  });

  it("admits a custom role holding apikey.manage", async () => {
    server.use(
      me(["apikey.manage"], "employee"),
      http.get("*/api/v1/api-keys", () => HttpResponse.json({ keys: [KEY] })),
    );
    renderPage();

    expect(await screen.findByText("Payroll export")).toBeInTheDocument();
    expect(screen.queryByTestId("role-gate-denied")).not.toBeInTheDocument();
  });

  it("shows a new key once, with a named copy button", async () => {
    let posted: unknown = null;
    server.use(
      me(["apikey.manage"]),
      http.get("*/api/v1/api-keys", () => HttpResponse.json({ keys: [] })),
      http.post("*/api/v1/api-keys", async ({ request }) => {
        posted = await request.json();
        return HttpResponse.json(
          {
            public_id: "KEY2",
            name: "Reporting",
            key: "ethr_plainsecretvalue",
            abilities: ["read"],
            expires_at: null,
            created_at: "2026-10-01T06:00:00Z",
          },
          { status: 201 },
        );
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await user.click(await screen.findByRole("button", { name: /Create Key/ }));
    await user.type(
      await screen.findByRole("textbox", { name: /Key Name/ }),
      "Reporting",
    );
    await user.click(screen.getByRole("button", { name: "Create" }));

    expect(
      await screen.findByText("ethr_plainsecretvalue"),
    ).toBeInTheDocument();
    expect(posted).toEqual({ name: "Reporting", abilities: ["read"] });
    expect(screen.getByRole("button", { name: "Copy" })).toBeInTheDocument();
  });
});
