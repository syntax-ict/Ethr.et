import { describe, expect, it } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import RolesPage from "@/app/(dashboard)/settings/roles/page";

// Nothing set a custom role's reach, so every one took the column default,
// `self`, and a custom HR role could see no one but its holder (audit N89).
describe("roles page", () => {
  it("sends the reach chosen for a new role", async () => {
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", name: "Admin", role: "tenant_admin" },
          tenant: { public_id: "T1", name: "Demo" },
          permissions: ["settings.manage"],
        }),
      ),
      http.get("*/api/v1/roles", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
          links: { first: null, last: null, prev: null, next: null },
        }),
      ),
      http.get("*/api/v1/permissions", () =>
        HttpResponse.json({
          employee: [
            {
              name: "employee.view",
              module: "employee",
              action: "view",
              description: "View employees",
            },
          ],
        }),
      ),
      http.post("*/api/v1/roles", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json(
          { public_id: "R1", name: "Branch HR", permissions: [] },
          { status: 201 },
        );
      }),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <RolesPage />
      </QueryClientProvider>,
    );
    const user = userEvent.setup();

    await user.click(
      (await screen.findAllByRole("button", { name: /create role/i }))[0],
    );
    const dialog = await screen.findByRole("dialog");
    await user.type(within(dialog).getByLabelText(/^name/i), "Branch HR");
    await user.click(await within(dialog).findByLabelText(/view employees/i));
    await user.click(
      within(dialog).getByRole("combobox", { name: /whose records/i }),
    );
    await user.click(
      await screen.findByRole("option", { name: "Their branch" }),
    );
    await user.click(
      within(dialog).getByRole("button", { name: /create role/i }),
    );

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toMatchObject({ name: "Branch HR", org_scope: "branch" });
  });
});
