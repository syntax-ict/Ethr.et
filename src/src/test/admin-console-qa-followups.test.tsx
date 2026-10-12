import { describe, it, expect, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { AdminTenantDetail } from "@/app/(dashboard)/admin/tenants/[id]/tenant-detail";
import ProfileLayout from "@/app/(dashboard)/profile/layout";
import { HostProvider } from "@/lib/auth/host-provider";

/**
 * Follow-ups from the live console QA (docs/audit/QA-CONSOLE-PRODUCTION-2026-10-11.md):
 * F5 address mode and F6 backup ask first, F7 actions wait for MFA, F16 the
 * invoices card says when there are none, F20 the shared address is shown, and
 * F22 the console host offers only the Security tab of the profile area.
 */

const pathname = vi.hoisted(() => ({ value: "/admin/tenants/01HZTENANT001" }));

vi.mock("next/navigation", () => ({
  usePathname: () => pathname.value,
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

function serveTenant(overrides: Record<string, unknown> = {}) {
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
        address_mode: "subdomain",
        address_mode_effective: "subdomain",
        canonical_url: "https://acme.ethr.et",
        type: "general",
        status: "trial",
        trial_ends_at: null,
        created_at: "2026-10-01T00:00:00Z",
        updated_at: "2026-10-01T00:00:00Z",
        usage: { employees: 3, devices: 0 },
        subscription: null,
        invoices: [],
        audit_log: [],
        ...overrides,
      }),
    ),
  );
}

function serveMe(mfaEnabled: boolean) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "01HZUSER000000000000000001",
          role: "super_admin",
          mfa_enabled: mfaEnabled,
        },
        tenant: null,
      }),
    ),
  );
}

function renderDetail() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AdminTenantDetail routeId={ID} />
    </QueryClientProvider>,
  );
}

describe("tenant detail follow-ups", () => {
  it("asks before changing the address, naming the new one, and sends nothing until confirmed (F5)", async () => {
    serveTenant();
    serveMe(true);
    const puts: unknown[] = [];
    server.use(
      http.put(
        `*/api/v1/admin/tenants/${ID}/address-mode`,
        async ({ request }) => {
          puts.push(await request.json());
          return HttpResponse.json({ public_id: ID, address_mode: "path" });
        },
      ),
    );
    renderDetail();

    await userEvent.click(
      await screen.findByRole("combobox", { name: /address mode/i }),
    );
    await userEvent.click(
      await screen.findByRole("option", { name: /shared/i }),
    );

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("/acme");
    expect(dialog).toHaveTextContent("acme.ethr.et");
    expect(puts).toEqual([]);

    await userEvent.click(
      within(dialog).getByRole("button", { name: /change address/i }),
    );
    await waitFor(() => expect(puts).toEqual([{ mode: "path" }]));
  });

  it("asks before queueing a backup (F6)", async () => {
    serveTenant();
    serveMe(true);
    const posts: string[] = [];
    server.use(
      http.post(`*/api/v1/admin/tenants/${ID}/backup`, () => {
        posts.push("backup");
        return HttpResponse.json({ message: "queued" });
      }),
    );
    renderDetail();

    await userEvent.click(
      await screen.findByRole("button", { name: /backup data/i }),
    );
    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("Acme Ltd");
    expect(posts).toEqual([]);

    await userEvent.click(
      within(dialog).getByRole("button", { name: /backup data/i }),
    );
    await waitFor(() => expect(posts).toEqual(["backup"]));
  });

  it("disables the actions, and says why, while the account has no MFA (F7)", async () => {
    serveTenant();
    serveMe(false);
    renderDetail();

    expect(
      await screen.findByText(/need two-factor authentication/i),
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(
        screen.getByRole("button", { name: /backup data/i }),
      ).toBeDisabled(),
    );
    expect(
      screen.getByRole("button", { name: /extend trial/i }),
    ).toBeDisabled();
    expect(
      screen.getByRole("combobox", { name: /address mode/i }),
    ).toBeDisabled();
  });

  it("says when there are no invoices rather than hiding the card (F16)", async () => {
    serveTenant({ invoices: [] });
    renderDetail();

    expect(await screen.findByText(/no invoices yet/i)).toBeInTheDocument();
  });

  it("shows the entry URL for an organisation on the shared address (F20)", async () => {
    serveTenant({
      address_mode: "path",
      address_mode_effective: "path",
      canonical_url: null,
    });
    renderDetail();

    const row = (await screen.findAllByText(/\/acme$/))[0];
    expect(row).toBeInTheDocument();
    expect(screen.queryByText("acme.ethr.et")).not.toBeInTheDocument();
  });

  it("shows the subdomain as stored, not capitalised (F14)", async () => {
    serveTenant();
    renderDetail();

    // The stat card used CSS capitalize: "Acme" for "acme".
    const values = await screen.findAllByText("acme");
    for (const value of values) {
      expect(value.className).not.toMatch(/capitalize/);
    }
  });
});

describe("profile tabs (F22)", () => {
  function renderProfile(host: string) {
    pathname.value = "/profile/security/";
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    return render(
      <QueryClientProvider client={client}>
        <HostProvider host={host}>
          <ProfileLayout>
            <div />
          </ProfileLayout>
        </HostProvider>
      </QueryClientProvider>,
    );
  }

  it("offers only Security on the console host", () => {
    vi.stubEnv("NEXT_PUBLIC_ROOT_DOMAIN", "ethr.et");
    renderProfile("admin.ethr.et");

    const nav = screen.getByRole("navigation", { name: /profile sections/i });
    const links = within(nav).getAllByRole("link");
    expect(links).toHaveLength(1);
    expect(links[0]).toHaveTextContent(/security/i);
    // A trailing slash, as a directly loaded export page has, still marks it current.
    expect(links[0]).toHaveAttribute("aria-current", "page");
    vi.unstubAllEnvs();
  });
});
