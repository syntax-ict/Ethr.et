import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import PlatformSettingsPage from "@/app/(dashboard)/admin/platform-settings/page";

/**
 * The sidebar's "Powered by ETHR" mark is the platform's, set in the console
 * on admin.ethr.et. The backend refuses anyone without `admin.manage`
 * (PlatformProductMarkTest); this is the console side: the card saves what
 * the operator chose, and an organisation's admin never sees it.
 */

vi.mock("next/navigation", () => ({
  usePathname: () => "/admin/platform-settings",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
}));

const permissions = vi.hoisted(() => ({ role: "super_admin" }));

vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => {
    const superAdmin = permissions.role === "super_admin";
    return {
      role: permissions.role,
      level: superAdmin ? 100 : 80,
      permissions: [],
      hasPermission: () => superAdmin,
      isAtLeast: (role: string) => superAdmin || role !== "super_admin",
      hasRole: (...roles: string[]) => roles.includes(permissions.role),
      isSuperAdmin: superAdmin,
      isTenantAdmin: !superAdmin,
      isHrAdmin: false,
      isFinanceAdmin: false,
      isSupervisor: false,
      isEmployee: false,
      can: new Proxy({} as Record<string, boolean>, {
        get: () => superAdmin,
      }),
    };
  },
}));

const STORED = {
  public_id: "01HZPLATFORM00000000000001",
  bank_name: null,
  bank_account_number: null,
  bank_account_name: null,
  payment_instructions: null,
  payment_instructions_am: null,
  is_configured: false,
  platform_name: null,
  platform_name_am: null,
  tagline: null,
  tagline_am: null,
  logo_url: null,
  contact_email: null,
  contact_phone: null,
  office_address: null,
  office_address_am: null,
  social_linkedin: null,
  social_x: null,
  social_facebook: null,
  metric_organisations: null,
  metric_employees: null,
  metric_uptime_note: null,
  metric_uptime_note_am: null,
  has_published_metrics: false,
  testimonial_quote: null,
  testimonial_quote_am: null,
  testimonial_author: null,
  testimonial_role: null,
  testimonial_role_am: null,
  testimonial_organisation: null,
  testimonial_consented_on: null,
  google_maps_key: null,
  product_mark_enabled: true,
  product_mark_label: null,
  product_mark_label_am: null,
  product_mark_url: null,
  updated_at: null,
};

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <PlatformSettingsPage />
    </QueryClientProvider>,
  );
}

function card(): HTMLElement {
  const title = screen.getByText("Sidebar product mark");
  return title.closest(".rounded-xl") as HTMLElement;
}

describe("the sidebar product mark in the platform console", () => {
  beforeEach(() => {
    permissions.role = "super_admin";
  });

  it("starts on, and saves it off with the standard wording", async () => {
    const puts: Record<string, unknown>[] = [];
    server.use(
      http.get("*/api/v1/admin/platform-settings", () =>
        HttpResponse.json(STORED),
      ),
      http.put("*/api/v1/admin/platform-settings", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        puts.push(body);
        return HttpResponse.json({ ...STORED, ...body });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    const toggle = await screen.findByRole("switch", {
      name: /show the mark/i,
    });
    expect(toggle).toHaveAttribute("aria-checked", "true");

    await user.click(toggle);
    expect(toggle).toHaveAttribute("aria-checked", "false");
    expect(
      within(card()).getByText(/organisations see only the collapse button/i),
    ).toBeInTheDocument();

    await user.click(
      within(card()).getByRole("button", { name: /save changes/i }),
    );

    await waitFor(() => expect(puts).toHaveLength(1));
    // Only the mark's own fields: this card's save must not resend the bank
    // account or the public profile from a stale copy.
    expect(puts[0]).toEqual({
      product_mark_enabled: false,
      product_mark_label: null,
      product_mark_label_am: null,
      product_mark_url: null,
    });
  });

  it("saves the wording and link, and previews them on the sidebar surface", async () => {
    const puts: Record<string, unknown>[] = [];
    server.use(
      http.get("*/api/v1/admin/platform-settings", () =>
        HttpResponse.json(STORED),
      ),
      http.put("*/api/v1/admin/platform-settings", async ({ request }) => {
        const body = (await request.json()) as Record<string, unknown>;
        puts.push(body);
        return HttpResponse.json({ ...STORED, ...body });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await user.type(
      await screen.findByLabelText(/^wording$/i),
      "Built on ETHR",
    );
    await user.type(
      screen.getByLabelText(/^link \(https\)$/i),
      "https://www.ethr.et/features",
    );
    expect(within(card()).getByText("Built on ETHR")).toBeInTheDocument();

    await user.click(
      within(card()).getByRole("button", { name: /save changes/i }),
    );
    await waitFor(() => expect(puts).toHaveLength(1));
    expect(puts[0]).toMatchObject({
      product_mark_enabled: true,
      product_mark_label: "Built on ETHR",
      product_mark_url: "https://www.ethr.et/features",
    });
  });

  it("is not shown to an organisation's admin", async () => {
    permissions.role = "tenant_admin";
    renderPage();

    expect(await screen.findByTestId("role-gate-denied")).toBeInTheDocument();
    expect(screen.queryByText("Sidebar product mark")).toBeNull();
    expect(screen.queryByRole("switch", { name: /show the mark/i })).toBeNull();
  });
});
