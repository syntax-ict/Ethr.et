import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import { PageTitleBar } from "@/components/layouts/page-title-bar";
import { PageActionsSlotProvider } from "@/components/layouts/page-actions-slot";
import { ROUTE_META } from "@/lib/route-meta";
import SettingsPage from "@/app/(dashboard)/settings/page";
import UsersSettingsPage from "@/app/(dashboard)/settings/users/page";
import ExitClearanceSettingsPage from "@/app/(dashboard)/settings/exit-clearance/page";
import en from "@/lib/i18n/locales/en.json";
import am from "@/lib/i18n/locales/am.json";
import om from "@/lib/i18n/locales/om.json";

/**
 * Settings review 2026-11-22, F12, F13 and F15: a breadcrumb that named every
 * settings page "General settings", a search box named only by its
 * placeholder, five exit-checklist checkboxes all called "Shows what is owed",
 * and section titles on General that were not headings.
 */

let pathname = "/settings/data-export";
vi.mock("next/navigation", () => ({
  usePathname: () => pathname,
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function me(permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role: "tenant_admin" },
      tenant: { public_id: "T1", name: "Abay", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return (
    <QueryClientProvider client={client}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
}

describe("breadcrumb parent of a settings page (F15)", () => {
  it("names the parent Settings, not General settings", async () => {
    pathname = "/settings/data-export";
    server.use(me(["settings.manage"]));
    render(
      <PageActionsSlotProvider>
        <PageTitleBar />
      </PageActionsSlotProvider>,
      { wrapper },
    );

    const trail = await screen.findByRole("navigation", {
      name: /breadcrumb/i,
    });
    const parent = within(trail).getByRole("link", { name: "Settings" });
    expect(parent).toHaveAttribute("href", "/settings");
    expect(within(trail).queryByText(/general settings/i)).toBeNull();
    expect(
      within(trail).getByText("Data export", { selector: "[aria-current]" }),
    ).toBeInTheDocument();
  });

  it("keeps General settings as the title of /settings itself", async () => {
    pathname = "/settings";
    server.use(me(["settings.manage"]));
    render(
      <PageActionsSlotProvider>
        <PageTitleBar />
      </PageActionsSlotProvider>,
      { wrapper },
    );

    expect(
      await screen.findByRole("heading", {
        level: 1,
        name: "General settings",
      }),
    ).toBeInTheDocument();
  });

  it("gives every settings sub-page the Settings parent key", () => {
    const subPages = Object.entries(ROUTE_META).filter(
      ([path, meta]) =>
        path.startsWith("/settings/") && meta.parent?.href === "/settings",
    );
    expect(subPages.length).toBeGreaterThan(10);
    for (const [, meta] of subPages) {
      expect(meta.parent?.labelKey).toBe("nav.settings");
    }
    for (const dict of [en, am, om] as Array<Record<string, string>>) {
      expect(dict["nav.settings"]).toBeTruthy();
    }
  });
});

describe("Users page search (F13, F12)", () => {
  function serveUsers(username: string | null) {
    server.use(
      me(["users.viewAny"]),
      http.get("*/api/v1/users", () =>
        HttpResponse.json({
          data: [
            {
              public_id: "01HZUSER000000000000000001",
              email: "selam@acme.et",
              username,
              phone: null,
              role: "employee",
              status: "active",
              locale: "en",
              mfa_enabled: false,
              invited_at: "2026-10-01T06:00:00Z",
              activated_at: "2026-10-01T07:00:00Z",
              last_login_at: null,
              custom_role: null,
              employee: null,
              created_at: "2026-10-01T06:00:00Z",
            },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
          links: { first: null, last: null, prev: null, next: null },
        }),
      ),
    );
  }

  it("names the search box with a label, not only a placeholder", async () => {
    serveUsers("selam.t");
    render(<UsersSettingsPage />, { wrapper });

    const box = await screen.findByRole("textbox", {
      name: "Search by name or email",
    });
    expect(box).toHaveAttribute("placeholder", "Search by name or email");
    // A real <label>, so the name survives once the placeholder is gone.
    const label = document.querySelector(`label[for="${box.id}"]`);
    expect(label).not.toBeNull();
    expect(label).toHaveClass("sr-only");
  });

  it("shows 'Not set' rather than a dash for an empty username", async () => {
    serveUsers(null);
    render(<UsersSettingsPage />, { wrapper });

    expect((await screen.findAllByText("selam@acme.et"))[0]).toBeVisible();
    expect(screen.getAllByText("Not set").length).toBeGreaterThan(0);
    expect(screen.queryByText("—")).toBeNull();
  });
});

describe("Exit checklist checkboxes (F13)", () => {
  it("names each 'shows what is owed' checkbox by its line", async () => {
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings/exit-clearance", () =>
        HttpResponse.json({
          is_customized: false,
          items: [
            { key: "id", label: "ID returned", owner: "hr", kind: "manual" },
            {
              key: "loans",
              label: "Loans settled",
              owner: "finance",
              kind: "obligations",
            },
            { key: "kit", label: "Kit returned", owner: "hr", kind: "manual" },
          ],
        }),
      ),
    );
    render(<ExitClearanceSettingsPage />, { wrapper });

    const boxes = await screen.findAllByRole("checkbox");
    const names = boxes.map((b) => b.getAttribute("aria-label"));
    expect(names).toEqual([
      "Shows what is owed, line 1",
      "Shows what is owed, line 2",
      "Shows what is owed, line 3",
    ]);
    // Each name still contains the visible words (WCAG 2.5.3).
    expect(screen.getByRole("checkbox", { name: /line 2/ })).toBeChecked();
  });

  it("ships the new name in all three languages", () => {
    for (const dict of [en, am, om] as Array<Record<string, string>>) {
      expect(dict["exit_clearance.shows_balance_line"]).toContain(":n");
    }
  });
});

describe("General settings section titles (F13)", () => {
  it("draws each card title as an h2 under the page's one h1", async () => {
    window.history.replaceState(null, "", "/settings?tab=general");
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings", () =>
        HttpResponse.json({
          organization: {
            name: "Abay Textiles",
            subdomain: "abay",
            type: "manufacturing",
            timezone: "Africa/Addis_Ababa",
            locale: "en",
          },
          address: {
            mode: "path",
            effective_mode: "path",
            canonical_url: null,
            entry_url: "https://ethr.et/abay",
            subdomain: "abay",
            subdomain_url: "https://abay.ethr.et",
            retired_subdomains: [],
            subdomains_served: false,
            custom_domain: null,
            custom_domain_status: null,
            custom_domain_allowed: false,
            custom_domain_dns: null,
          },
          branding: {
            logo_url: null,
            assets: { logo_dark: null, mark: null, cover: null },
            theme: [],
          },
          leave: { working_days: [1, 2, 3, 4, 5] },
          payroll: {
            pay_period: "monthly",
            run_day: 25,
            fiscal_year_start_month: 1,
            pagumen_proration_strategy: "full_month",
            retirement_age: 60,
          },
          security: {
            mfa_policy: "required",
            session_timeout_minutes: 30,
            password_policy: {
              min_length: 8,
              require_uppercase: false,
              require_lowercase: false,
              require_number: false,
              require_symbol: false,
            },
            activation_code_hours: 24,
          },
          display: { calendar: "gregorian" },
          employees: { number_format: "EMP-{SEQ:4}", next_code: "EMP-0001" },
          sso: { is_enabled: false, configured: false },
        }),
      ),
    );
    render(<SettingsPage />, { wrapper });

    expect(
      await screen.findByRole("heading", { level: 2, name: "Display" }),
    ).toBeInTheDocument();
    const h2s = screen.getAllByRole("heading", { level: 2 });
    expect(h2s.length).toBeGreaterThanOrEqual(3);
    // The page body adds no h1 of its own; the title bar owns the one.
    expect(screen.queryAllByRole("heading", { level: 1 })).toHaveLength(0);
  });
});
