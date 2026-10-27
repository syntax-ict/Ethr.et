import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
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

function serveTenant(overrides: Record<string, unknown>) {
  server.use(
    http.get(`*/api/v1/admin/tenants/${ID}`, () =>
      HttpResponse.json({
        public_id: ID,
        name: "Acme Ltd",
        subdomain: "acme",
        custom_domain: null,
        custom_domain_status: null,
        custom_domain_dns: null,
        custom_domain_allowed: false,
        canonical_url: "https://acme.ethr.et",
        type: null,
        status: "cancelled",
        trial_ends_at: null,
        created_at: "2026-10-01T00:00:00Z",
        updated_at: "2026-10-01T00:00:00Z",
        usage: { employees: 3, devices: 0 },
        subscription: null,
        invoices: [],
        audit_log: [],
        deleted_at: null,
        ...overrides,
      }),
    ),
  );
}

function captureDelete() {
  const bodies: unknown[] = [];
  server.use(
    http.post(`*/api/v1/admin/tenants/${ID}/delete`, async ({ request }) => {
      bodies.push(await request.json());
      return HttpResponse.json({
        public_id: ID,
        deleted_at: "2026-10-19T12:00:00Z",
      });
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

const deleteButton = () =>
  screen.findByRole("button", { name: /delete organisation/i });

// 2026-10-19: deleting an organisation from the platform console. It is the
// second of two deliberate steps, and asks for a reason before anything is
// sent. By owner decision, typing the address out was dropped on 2026-10-26
// and the authenticator code on 2026-10-27.
describe("deleting an organisation from the console", () => {
  it("is offered only once the organisation is cancelled", async () => {
    serveTenant({ status: "active" });
    renderPage();

    await screen.findByRole("heading", { name: "Acme Ltd" });
    expect(
      screen.queryByRole("button", { name: /delete organisation/i }),
    ).not.toBeInTheDocument();
  });

  it("says what deleting does and keeps, and asks only for a reason", async () => {
    serveTenant({});
    const bodies = captureDelete();
    renderPage();

    await userEvent.click(await deleteButton());
    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent(/signed out/i);
    expect(dialog).toHaveTextContent(/records are kept/i);
    expect(dialog).toHaveTextContent("acme.ethr.et");

    // Neither the address typed out nor an authenticator code is asked for.
    expect(within(dialog).queryByLabelText(/type acme/i)).toBeNull();
    expect(within(dialog).queryByLabelText(/code/i)).toBeNull();

    const submit = within(dialog).getByRole("button", {
      name: /delete organisation/i,
    });
    expect(submit).toBeDisabled();

    // Four characters are not a reason.
    await userEvent.type(within(dialog).getByLabelText(/reason/i), "Dupe");
    expect(submit).toBeDisabled();

    await userEvent.type(
      within(dialog).getByLabelText(/reason/i),
      " registration",
    );
    expect(submit).toBeEnabled();
    await userEvent.click(submit);

    expect(bodies).toEqual([{ reason: "Dupe registration" }]);
  });

  it("shows a refused reason under the reason field and keeps the dialog open", async () => {
    serveTenant({});
    server.use(
      http.post(`*/api/v1/admin/tenants/${ID}/delete`, () =>
        HttpResponse.json(
          {
            message: "The reason field must be at least 5 characters.",
            errors: {
              reason: ["The reason field must be at least 5 characters."],
            },
          },
          { status: 422 },
        ),
      ),
    );
    renderPage();

    await userEvent.click(await deleteButton());
    const dialog = await screen.findByRole("dialog");
    await userEvent.type(
      within(dialog).getByLabelText(/reason/i),
      "Closing it",
    );
    await userEvent.click(
      within(dialog).getByRole("button", { name: /delete organisation/i }),
    );

    expect(
      await within(dialog).findByText(
        "The reason field must be at least 5 characters.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toBeInTheDocument();
  });

  it("offers only Restore and Backup on a deleted organisation", async () => {
    serveTenant({ deleted_at: "2026-10-19T12:00:00Z" });
    renderPage();

    expect(
      await screen.findByRole("button", { name: /restore/i }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /backup data/i }),
    ).toBeInTheDocument();
    for (const name of [
      /suspend/i,
      /impersonate/i,
      /extend trial/i,
      /delete organisation/i,
    ]) {
      expect(screen.queryByRole("button", { name })).not.toBeInTheDocument();
    }
  });
});
