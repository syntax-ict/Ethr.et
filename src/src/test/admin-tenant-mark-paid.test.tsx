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
const INVOICE = "01HZINVOICE0000000PENDING1";

function serveTenantWithPendingInvoice() {
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
        type: null,
        status: "active",
        trial_ends_at: null,
        created_at: "2026-10-01T00:00:00Z",
        updated_at: "2026-10-01T00:00:00Z",
        usage: { employees: 3, devices: 0 },
        subscription: null,
        invoices: [
          {
            public_id: INVOICE,
            total_cents: 99900,
            status: "pending",
            due_date: "2026-11-01",
          },
        ],
        audit_log: [],
      }),
    ),
  );
}

function captureMarkPaid() {
  const calls: string[] = [];
  server.use(
    http.put(
      `*/api/v1/admin/tenants/${ID}/invoices/:invoice/mark-paid`,
      ({ params }) => {
        calls.push(String(params.invoice));
        return HttpResponse.json({ public_id: INVOICE, status: "paid" });
      },
    ),
  );
  return calls;
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

// Console QA F4 (2026-10-12): one click confirmed a payment — activating the
// subscription and booking revenue — with nothing in between.
describe("confirming an invoice payment from the console", () => {
  it("asks first, naming the invoice and the amount, and sends nothing yet", async () => {
    serveTenantWithPendingInvoice();
    const calls = captureMarkPaid();
    renderPage();

    await userEvent.click(
      await screen.findByRole("button", { name: /mark paid/i }),
    );

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent(INVOICE.slice(-8));
    expect(dialog).toHaveTextContent(/999\.00/);
    expect(calls).toEqual([]);
  });

  it("marks it paid only once confirmed", async () => {
    serveTenantWithPendingInvoice();
    const calls = captureMarkPaid();
    renderPage();

    await userEvent.click(
      await screen.findByRole("button", { name: /mark paid/i }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /999\.00/ }),
    );

    await waitFor(() => expect(calls).toEqual([INVOICE]));
  });

  it("sends nothing when cancelled", async () => {
    serveTenantWithPendingInvoice();
    const calls = captureMarkPaid();
    renderPage();

    await userEvent.click(
      await screen.findByRole("button", { name: /mark paid/i }),
    );
    const dialog = await screen.findByRole("dialog");
    await userEvent.click(
      within(dialog).getByRole("button", { name: /^cancel$/i }),
    );

    await waitFor(() =>
      expect(screen.queryByRole("dialog")).not.toBeInTheDocument(),
    );
    expect(calls).toEqual([]);
  });
});
