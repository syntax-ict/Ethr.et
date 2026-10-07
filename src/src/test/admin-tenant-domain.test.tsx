import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AdminTenantDetail } from "@/app/(dashboard)/admin/tenants/[id]/tenant-detail";

vi.mock("next/navigation", () => ({
  usePathname: () => "/admin/tenants/01HZTENANT001",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
}));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    role: "super_admin",
    level: 100,
    permissions: [],
    hasPermission: () => true,
    isAtLeast: () => true,
    hasRole: (...roles: string[]) => roles.includes("super_admin"),
    isSuperAdmin: true,
    isTenantAdmin: false,
    isHrAdmin: false,
    isFinanceAdmin: false,
    isSupervisor: false,
    isEmployee: false,
    can: new Proxy({} as Record<string, boolean>, { get: () => true }),
  }),
}));

const ID = "01HZTENANT001";

function serveTenant(customDomain: string | null) {
  server.use(
    http.get(`*/api/v1/admin/tenants/${ID}`, () =>
      HttpResponse.json({
        public_id: ID,
        name: "Acme Ltd",
        subdomain: "acme",
        custom_domain: customDomain,
        type: null,
        status: "active",
        trial_ends_at: null,
        created_at: "2026-10-01T00:00:00Z",
        updated_at: "2026-10-01T00:00:00Z",
        usage: { employees: 3, devices: 0 },
        subscription: null,
        invoices: [],
        audit_log: [],
      }),
    ),
  );
}

/** Records every body sent to the domain endpoint and answers with `reply`. */
function captureDomainPuts(reply: () => Response) {
  const bodies: unknown[] = [];
  server.use(
    http.put(`*/api/v1/admin/tenants/${ID}/domain`, async ({ request }) => {
      bodies.push(await request.json());
      return reply();
    }),
  );
  return bodies;
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AdminTenantDetail routeId={ID} />
    </QueryClientProvider>,
  );
}

async function openDialog() {
  await userEvent.click(
    await screen.findByRole("button", { name: /custom domain/i }),
  );
  return screen.findByRole("dialog");
}

// The platform console is the only place a custom domain can be assigned.
// Until it existed, `custom_domain` could be set only in the database.
describe("assigning a custom domain from the tenant detail page", () => {
  it("shows that no domain is assigned", async () => {
    serveTenant(null);
    renderPage();

    const row = (await screen.findAllByText(/custom domain/i)).find(
      (el) => el.tagName !== "BUTTON" && !el.closest("button"),
    );
    expect(row?.parentElement).toHaveTextContent(/none/i);
  });

  it("saves the domain typed into the dialog", async () => {
    serveTenant(null);
    const bodies = captureDomainPuts(() =>
      HttpResponse.json({ public_id: ID, custom_domain: "hr.acme.com" }),
    );
    renderPage();

    const dialog = await openDialog();
    await userEvent.type(
      within(dialog).getByLabelText(/^domain$/i),
      "hr.acme.com",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /save domain/i }),
    );

    await waitFor(() =>
      expect(bodies).toEqual([{ custom_domain: "hr.acme.com" }]),
    );
    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    );
  });

  it("shows the server's reason under the field and keeps the dialog open", async () => {
    serveTenant(null);
    captureDomainPuts(() =>
      HttpResponse.json(
        {
          type: "validation_error",
          title: "Validation Failed",
          status: 422,
          detail: "The custom domain has already been taken.",
          errors: {
            custom_domain: ["The custom domain has already been taken."],
          },
        },
        { status: 422 },
      ),
    );
    renderPage();

    const dialog = await openDialog();
    const field = within(dialog).getByLabelText(/^domain$/i);
    await userEvent.type(field, "hr.acme.com");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /save domain/i }),
    );

    expect(
      await within(dialog).findByText(
        "The custom domain has already been taken.",
      ),
    ).toBeInTheDocument();
    expect(field).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });

  it("removes an assigned domain by sending null", async () => {
    serveTenant("hr.acme.com");
    const bodies = captureDomainPuts(() =>
      HttpResponse.json({ public_id: ID, custom_domain: null }),
    );
    renderPage();

    const dialog = await openDialog();
    expect(within(dialog).getByLabelText(/^domain$/i)).toHaveValue(
      "hr.acme.com",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /remove domain/i }),
    );

    await waitFor(() => expect(bodies).toEqual([{ custom_domain: null }]));
  });

  it("offers no removal when nothing is assigned", async () => {
    serveTenant(null);
    renderPage();

    const dialog = await openDialog();
    expect(
      within(dialog).queryByRole("button", { name: /remove domain/i }),
    ).not.toBeInTheDocument();
  });
});
