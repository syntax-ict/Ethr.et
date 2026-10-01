import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import ScimSettingsPage from "@/app/(dashboard)/settings/scim/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

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
  return render(<ScimSettingsPage />, { wrapper: Wrapper });
}

describe("<ScimSettingsPage>", () => {
  it("shows a generated token once", async () => {
    let sent: unknown = null;
    server.use(
      me(["settings.manage"]),
      http.post("*/api/v1/settings/scim-token", async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json(
          {
            token: "scimplaintokenvalue",
            prefix: "scimplai",
            expires_at: "2027-10-01T06:00:00+00:00",
            message: "Store this token securely — it will not be shown again.",
          },
          { status: 201 },
        );
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await user.type(
      await screen.findByPlaceholderText(/Token name/),
      "  Okta SCIM ",
    );
    await user.click(screen.getByRole("button", { name: /Generate/ }));

    expect(await screen.findByText("scimplaintokenvalue")).toBeInTheDocument();
    await waitFor(() => expect(sent).toEqual({ name: "Okta SCIM" }));
  });

  it("admits a custom role holding settings.manage", async () => {
    server.use(me(["settings.manage"], "employee"));
    renderPage();

    expect(
      await screen.findByPlaceholderText(/Token name/),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("role-gate-denied")).not.toBeInTheDocument();
  });
});
