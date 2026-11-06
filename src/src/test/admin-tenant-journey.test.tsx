import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { buildJourney, buildJourneySummary } from "./msw/handlers";
import AdminTenantsPage from "@/app/(dashboard)/admin/tenants/page";
import { TenantJourneyCard } from "@/app/(dashboard)/admin/tenants/[id]/tenant-journey-card";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  usePathname: () => "/admin/tenants",
  useRouter: () => ({ push, replace: vi.fn(), back: vi.fn() }),
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

const ID = "01HZJOURNEY000000000000001";

function renderWithClient(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

afterEach(() => window.history.replaceState(null, "", "/"));

/**
 * Where an organisation is between sign-up and using ETHR
 * (docs/operations/TENANT-JOURNEY.md). The console shows counts, dates and
 * reason codes; the server sends codes, so each one is turned into a sentence
 * here and an unknown one stays visible.
 */
describe("the journey card on an organisation", () => {
  it("shows the stage, what needs attention, the milestones and the events", async () => {
    server.use(
      http.get(`*/api/v1/admin/tenants/${ID}/journey`, () =>
        HttpResponse.json({
          data: buildJourney({
            stage: "first_use",
            health_state: "warning",
            reasons: ["no_activity_14d_after_first_use", "devices_silent"],
            milestones: {
              registered: "2026-10-01T08:00:00+00:00",
              configured: "2026-10-02T08:00:00+00:00",
              first_use: "2026-10-10T08:00:00+00:00",
            },
            metrics: {
              ...buildJourney().metrics,
              employees: 12,
              team_users: 4,
              activated_users: 3,
              punches_30d: 240,
              punch_sources_30d: [{ source: "mobile", count: 240 }],
              devices: 2,
              devices_silent: 1,
            },
            readiness: {
              score: 71,
              level: "needs_attention",
              gaps: [
                {
                  id: "employer_tin_set",
                  category: "payroll",
                  label: "Employer TIN recorded",
                },
              ],
            },
            events: [
              {
                action: "admin.tenant.trial_extended",
                created_at: "2026-10-20T08:00:00+00:00",
                by_platform: true,
              },
            ],
          }),
        }),
      ),
    );

    renderWithClient(<TenantJourneyCard publicId={ID} />);

    expect(await screen.findByText("Needs a look")).toBeInTheDocument();
    expect(
      screen.getByText(
        "It used ETHR before, and nothing has been recorded for 14 days.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText("A device has not synced for 3 days."),
    ).toBeInTheDocument();

    // The current stage carries aria-current; stages before it are done.
    const stages = screen.getByRole("list", { name: "Journey stages" });
    const current = within(stages)
      .getAllByRole("listitem")
      .find((li) => li.getAttribute("aria-current") === "step");
    expect(current).toHaveTextContent("First use");

    expect(screen.getByText("240")).toBeInTheDocument();
    expect(screen.getByText("mobile 240")).toBeInTheDocument();
    expect(
      screen.getByText("First punch or leave request"),
    ).toBeInTheDocument();
    expect(screen.getAllByText("Not yet").length).toBeGreaterThan(0);
    expect(screen.getByText("71% ready")).toBeInTheDocument();
    expect(screen.getByText("Trial extended")).toBeInTheDocument();
    expect(screen.getByText("by ETHR")).toBeInTheDocument();
  });

  it("says so when nothing needs attention, and shows no payroll tile without payroll", async () => {
    renderWithClient(<TenantJourneyCard publicId={ID} />);

    expect(
      await screen.findByText("Nothing needs attention."),
    ).toBeInTheDocument();
    expect(
      screen.queryByText("Completed payroll runs"),
    ).not.toBeInTheDocument();
  });

  it("shows a reason code this build does not know as itself", async () => {
    server.use(
      http.get(`*/api/v1/admin/tenants/${ID}/journey`, () =>
        HttpResponse.json({
          data: buildJourney({
            health_state: "critical",
            reasons: ["a_reason_from_a_newer_api"],
          }),
        }),
      ),
    );

    renderWithClient(<TenantJourneyCard publicId={ID} />);

    expect(
      await screen.findByText("a_reason_from_a_newer_api"),
    ).toBeInTheDocument();
    expect(screen.getByText("Action needed")).toBeInTheDocument();
  });

  it("reads an unknown health state as unknown, never as healthy", async () => {
    server.use(
      http.get(`*/api/v1/admin/tenants/${ID}/journey`, () =>
        HttpResponse.json({ data: buildJourney({ health_state: "mystery" }) }),
      ),
    );

    renderWithClient(<TenantJourneyCard publicId={ID} />);

    expect(await screen.findByText("Unknown")).toBeInTheDocument();
    expect(screen.queryByText("Healthy")).not.toBeInTheDocument();
  });
});

describe("the organisations list", () => {
  const tenant = (over: Record<string, unknown>) => ({
    public_id: ID,
    name: "Acme Ltd",
    subdomain: "acme",
    custom_domain: null,
    custom_domain_status: null,
    type: null,
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

  it("shows each organisation's stage and health, and a dash before its first snapshot", async () => {
    serveList([
      tenant({ stage: "first_use", health_state: "critical" }),
      tenant({
        public_id: "01HZJOURNEY000000000000002",
        name: "Beta Foods",
        stage: null,
        health_state: null,
      }),
    ]);

    renderWithClient(<AdminTenantsPage />);

    const acme = (await screen.findByText("Acme Ltd")).closest("tr")!;
    expect(within(acme).getByText("First use")).toBeInTheDocument();
    expect(within(acme).getByText("Action needed")).toBeInTheDocument();

    const beta = screen.getByText("Beta Foods").closest("tr")!;
    expect(within(beta).getByText("Unknown")).toBeInTheDocument();
  });

  it("shows the funnel, and pressing a stage filters the list to it", async () => {
    window.history.replaceState(null, "", "/admin/tenants");
    server.use(
      http.get("*/api/v1/admin/tenants/journey-summary", () =>
        HttpResponse.json({
          data: buildJourneySummary({
            total: 5,
            not_computed: 1,
            stages: [
              { stage: "registered", count: 2 },
              { stage: "configured", count: 0 },
              { stage: "people_added", count: 1 },
              { stage: "team_activated", count: 0 },
              { stage: "first_use", count: 1 },
              { stage: "successfully_using", count: 0 },
            ],
            health: { ok: 1, warning: 2, critical: 1, unknown: 1 },
          }),
        }),
      ),
    );
    const seen: string[] = [];
    serveList([tenant({ stage: "first_use", health_state: "ok" })], seen);

    renderWithClient(<AdminTenantsPage />);

    expect(await screen.findByText("Not measured yet: 1")).toBeInTheDocument();
    expect(
      screen.getByText("Need action: 1. Need a look: 2"),
    ).toBeInTheDocument();

    await userEvent.click(
      screen.getByRole("button", { name: /^First use\s*\d+$/ }),
    );

    await waitFor(() =>
      expect(seen.some((q) => q.includes("filter%5Bstage%5D=first_use"))).toBe(
        true,
      ),
    );
    expect(
      screen.getByRole("button", { name: /^First use\s*\d+$/ }),
    ).toHaveAttribute("aria-pressed", "true");
  });
});
