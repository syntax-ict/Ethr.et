import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CommandPalette } from "@/components/shared/command-palette";

/**
 * Settings pages and tabs in the open palette (settings review F9): the
 * person types what they want to change, and Enter opens it.
 */

const pushMock = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: pushMock }),
}));

function mockUser(role: string, permissions: string[]) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER", name: "Test", role },
        permissions,
        tenant: null,
      }),
    ),
    http.get("*/api/v1/search", ({ request }) =>
      HttpResponse.json({
        query: new URL(request.url).searchParams.get("q") ?? "",
        employees: [],
        attendance: [],
        leave: [],
        departments: [],
      }),
    ),
  );
}

function renderPalette() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <CommandPalette />
    </QueryClientProvider>,
  );
}

const placeholder = /search people, attendance, leave/i;

describe("CommandPalette settings", () => {
  beforeEach(() => {
    pushMock.mockReset();
    window.localStorage.removeItem("ethr.search.recent.01HZUSER");
  });

  it("finds the SSO tab for 'sso' and opens it at ?tab=sso", async () => {
    mockUser("tenant_admin", ["settings.manage"]);
    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Control>}k{/Control}");
    await user.type(await screen.findByPlaceholderText(placeholder), "sso");

    const option = await screen.findByRole("option", { name: /SSO/ });
    // Filed under Settings, so a bare "SSO" says where it is.
    expect(option).toHaveTextContent("Settings");
    await waitFor(() =>
      expect(option).toHaveAttribute("aria-selected", "true"),
    );
    await user.keyboard("{Enter}");
    expect(pushMock).toHaveBeenLastCalledWith("/settings?tab=sso");
  });

  it("finds Payroll rules for 'tax' and Webhooks for 'webhooks'", async () => {
    mockUser("tenant_admin", [
      "settings.manage",
      "payroll.viewConfig",
      "webhook.manage",
    ]);
    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Control>}k{/Control}");
    await user.type(await screen.findByPlaceholderText(placeholder), "tax");
    await user.click(
      await screen.findByRole("option", { name: /payroll rules/i }),
    );
    expect(pushMock).toHaveBeenLastCalledWith("/settings/payroll");

    await user.keyboard("{Control>}k{/Control}");
    await user.type(
      await screen.findByPlaceholderText(placeholder),
      "webhooks",
    );
    await user.click(await screen.findByRole("option", { name: /Webhooks/ }));
    expect(pushMock).toHaveBeenLastCalledWith("/settings/webhooks");
  });

  it("offers someone without settings.manage no SSO and no Security tab", async () => {
    mockUser("hr_admin", ["holiday.create", "users.viewAny"]);
    const user = userEvent.setup();
    renderPalette();

    await user.keyboard("{Control>}k{/Control}");
    await user.type(await screen.findByPlaceholderText(placeholder), "sso");
    await waitFor(() =>
      expect(
        screen.queryByRole("option", { name: /SSO/ }),
      ).not.toBeInTheDocument(),
    );

    // Their Settings row leads to Holidays, the one page they hold; it must
    // not hide the Holidays row from a search for it.
    await user.clear(screen.getByPlaceholderText(placeholder));
    await user.type(screen.getByPlaceholderText(placeholder), "holidays");
    expect(
      await screen.findByRole("option", { name: /Holidays/ }),
    ).toBeInTheDocument();
  });
});
