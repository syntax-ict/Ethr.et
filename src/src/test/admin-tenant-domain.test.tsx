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

function serveTenant(
  customDomain: string | null,
  {
    status = customDomain === null ? null : "verified",
    allowed = true,
  }: { status?: "pending" | "verified" | null; allowed?: boolean } = {},
) {
  server.use(
    http.get(`*/api/v1/admin/tenants/${ID}`, () =>
      HttpResponse.json({
        public_id: ID,
        name: "Acme Ltd",
        subdomain: "acme",
        custom_domain: customDomain,
        custom_domain_status: status,
        custom_domain_dns:
          customDomain === null
            ? null
            : {
                txt_name: `_ethr-verification.${customDomain}`,
                txt_value: "ethr-verification=0123456789abcdef0123456789abcdef",
                cname_name: customDomain,
                cname_target: "ethr.et",
              },
        custom_domain_allowed: allowed,
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

// Production builds without NEXT_PUBLIC_ROOT_DOMAIN, and the host serves no
// wildcard subdomains (M3), so `acme.ethr.et` does not answer. The console
// must print an address that does.
describe("the tenant's address on the detail page (single-host mode)", () => {
  it("prints ethr.et/{slug} when no custom domain is assigned", async () => {
    serveTenant(null);
    renderPage();

    const heading = await screen.findByRole("heading", { name: "Acme Ltd" });
    expect(heading.parentElement).toHaveTextContent("ethr.et/acme");
    expect(document.body).not.toHaveTextContent("acme.ethr.et");
  });

  it("prints the custom domain under the name when one is assigned", async () => {
    serveTenant("hr.acme.com");
    renderPage();

    const heading = await screen.findByRole("heading", { name: "Acme Ltd" });
    expect(heading.parentElement).toHaveTextContent("hr.acme.com");
    expect(document.body).not.toHaveTextContent("acme.ethr.et");
  });
});

// A custom domain is the Enterprise tier of an organisation's address (owner
// decision 2026-10-08). Assigning one stores it pending; it resolves nothing
// until the organisation publishes a TXT token and a CNAME, and an admin
// presses Verify.
describe("verifying a custom domain", () => {
  function captureVerifies(reply: () => Response) {
    let calls = 0;
    server.use(
      http.post(`*/api/v1/admin/tenants/${ID}/domain/verify`, () => {
        calls += 1;
        return reply();
      }),
    );
    return () => calls;
  }

  it("prints the shared address, not a pending domain, under the name", async () => {
    serveTenant("hr.acme.com", { status: "pending" });
    renderPage();

    const heading = await screen.findByRole("heading", { name: "Acme Ltd" });
    expect(heading.parentElement).toHaveTextContent("ethr.et/acme");
    expect(heading.parentElement).not.toHaveTextContent("hr.acme.com");
  });

  it("marks a pending domain and lists the two records to publish", async () => {
    serveTenant("hr.acme.com", { status: "pending" });
    renderPage();

    expect(
      await screen.findByText(/pending verification/i),
    ).toBeInTheDocument();
    expect(
      await screen.findByText(/verify the custom domain/i),
    ).toBeInTheDocument();
    expect(
      screen.getByText("_ethr-verification.hr.acme.com"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("ethr-verification=0123456789abcdef0123456789abcdef"),
    ).toBeInTheDocument();
    expect(screen.getByText("CNAME")).toBeInTheDocument();
    expect(screen.getByText("ethr.et")).toBeInTheDocument();
  });

  it("verifies on request", async () => {
    serveTenant("hr.acme.com", { status: "pending" });
    const calls = captureVerifies(() =>
      HttpResponse.json({
        public_id: ID,
        custom_domain: "hr.acme.com",
        custom_domain_status: "verified",
        custom_domain_dns: null,
      }),
    );
    renderPage();

    await userEvent.click(
      await screen.findByRole("button", { name: /^verify$/i }),
    );

    await waitFor(() => expect(calls()).toBe(1));
  });

  it("names each record the server could not find", async () => {
    serveTenant("hr.acme.com", { status: "pending" });
    captureVerifies(() =>
      HttpResponse.json(
        {
          type: "validation_error",
          title: "Validation Failed",
          status: 422,
          detail: "The given data was invalid.",
          errors: {
            txt: ["No TXT record _ethr-verification.hr.acme.com was found."],
            cname: ["hr.acme.com is not a CNAME for ethr.et."],
          },
        },
        { status: 422 },
      ),
    );
    renderPage();

    await userEvent.click(
      await screen.findByRole("button", { name: /^verify$/i }),
    );

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent(
      "No TXT record _ethr-verification.hr.acme.com was found.",
    );
    expect(alert).toHaveTextContent("hr.acme.com is not a CNAME for ethr.et.");
  });

  it("asks for nothing once the domain is verified", async () => {
    serveTenant("hr.acme.com", { status: "verified" });
    renderPage();

    expect(await screen.findByText(/^verified$/i)).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /^verify$/i }),
    ).not.toBeInTheDocument();
  });

  it("says when the plan has no custom domain, and will not save one", async () => {
    serveTenant(null, { allowed: false });
    renderPage();

    const dialog = await openDialog();
    expect(within(dialog).getByRole("note")).toHaveTextContent(
      /plan does not include a custom domain/i,
    );
    await userEvent.type(
      within(dialog).getByLabelText(/^domain$/i),
      "hr.acme.com",
    );
    expect(
      within(dialog).getByRole("button", { name: /save domain/i }),
    ).toBeDisabled();
  });
});
