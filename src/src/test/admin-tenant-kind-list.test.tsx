import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { kindCatalogueHandler } from "./msw/organization-types";
import AdminTenantsPage from "@/app/(dashboard)/admin/tenants/page";

vi.mock("next/navigation", () => ({
  usePathname: () => "/admin/tenants",
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

beforeEach(() => server.use(kindCatalogueHandler));
afterEach(() => window.history.replaceState(null, "", "/"));

function renderList() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <AdminTenantsPage />
    </QueryClientProvider>,
  );
}

const row = (over: Record<string, unknown>) => ({
  public_id: "01HZKIND0000000000000001",
  name: "Acme Ltd",
  subdomain: "acme",
  custom_domain: null,
  custom_domain_status: null,
  type: "hotel",
  organization_type: "private",
  organization_type_needs_review: false,
  status: "active",
  employee_count: 3,
  trial_ends_at: null,
  created_at: "2026-10-01T00:00:00Z",
  showcase_consented_at: null,
  showcase_hidden: false,
  trial_expired: false,
  stage: null,
  health_state: null,
  health_reasons: [],
  readiness_score: null,
  last_activity_at: null,
  journey_computed_at: null,
  ...over,
});

function serveList(rows: Array<Record<string, unknown>>, seen?: string[]) {
  server.use(
    http.get("*/api/v1/admin/tenants", ({ request }) => {
      seen?.push(new URL(request.url).search);
      return HttpResponse.json({
        data: rows,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 25,
          total: rows.length,
        },
        links: { first: null, last: null, prev: null, next: null },
      });
    }),
  );
}

describe("the organisations list, by kind in law", () => {
  it("shows the kind beside the industry, and flags one that needs confirming", async () => {
    serveList([
      row({}),
      row({
        public_id: "01HZKIND0000000000000002",
        name: "Ministry Office",
        type: "government",
        organization_type: "government",
        organization_type_needs_review: true,
      }),
    ]);

    renderList();

    const acme = (await screen.findByText("Acme Ltd")).closest("tr")!;
    expect(
      within(acme).getByText("Private company · Hotel / Hospitality"),
    ).toBeInTheDocument();
    expect(
      within(acme).queryByText("Needs confirming"),
    ).not.toBeInTheDocument();

    const ministry = screen.getByText("Ministry Office").closest("tr")!;
    expect(
      within(ministry).getByText("Government office · Government"),
    ).toBeInTheDocument();
    expect(within(ministry).getByText("Needs confirming")).toBeInTheDocument();
  });

  it("asks the API for one kind when the filter names it", async () => {
    window.history.replaceState(null, "", "/admin/tenants?kind=government");
    const seen: string[] = [];
    serveList([row({ organization_type: "government" })], seen);

    renderList();

    await screen.findByText("Acme Ltd");
    await waitFor(() =>
      expect(
        seen.some((q) =>
          q.includes("filter%5Borganization_type%5D=government"),
        ),
      ).toBe(true),
    );
    expect(
      screen.getByRole("combobox", { name: "Filter by kind of organisation" }),
    ).toBeInTheDocument();
  });

  it("sends no kind filter by default", async () => {
    const seen: string[] = [];
    serveList([row({})], seen);

    renderList();

    await screen.findByText("Acme Ltd");
    expect(seen.every((q) => !q.includes("organization_type"))).toBe(true);
  });

  it("still handles loading, failure and an empty result", async () => {
    server.use(
      http.get("*/api/v1/admin/tenants", () =>
        HttpResponse.json({ message: "down" }, { status: 500 }),
      ),
    );
    const failed = renderList();
    expect(
      await screen.findByText("Couldn't load tenants"),
    ).toBeInTheDocument();
    failed.unmount();

    serveList([]);
    renderList();
    expect(await screen.findByText("No tenants found")).toBeInTheDocument();
  });
});
