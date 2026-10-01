import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { CalendarProvider } from "@/lib/calendar/calendar-context";
import SettingsPage from "@/app/(dashboard)/settings/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

let tab = "security";
vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams(`tab=${tab}`),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), back: vi.fn() }),
  usePathname: () => "/settings",
}));

/** Shaped like `SettingsController::index`. */
const SETTINGS = {
  organization: {
    name: "Abay Textiles",
    subdomain: "abay",
    type: "manufacturing",
    timezone: "Africa/Addis_Ababa",
    locale: "am",
  },
  branding: { logo_url: null, theme: [] },
  leave: { working_days: [1, 2, 3, 4, 5] },
  payroll: {
    pay_period: "monthly",
    run_day: 25,
    fiscal_year_start_month: 1,
    pagumen_proration_strategy: "full_month",
    retirement_age: 60,
  },
  security: { mfa_policy: "required", session_timeout_minutes: 30 },
  sso: {
    is_enabled: true,
    provider: "saml",
    idp_entity_id: "https://idp.example.et/entity",
    idp_sso_url: "https://idp.example.et/sso",
    default_role: "employee",
    auto_provision: false,
    metadata_url: "https://api.ethr.et/api/v1/sso/saml/abay/metadata",
  },
};

function me(permissions: string[], role = "tenant_admin") {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Abay", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <CalendarProvider>{children}</CalendarProvider>
    </QueryClientProvider>
  );
  return render(<SettingsPage />, { wrapper: Wrapper });
}

beforeEach(() => {
  tab = "security";
});

describe("<SettingsPage> security tab", () => {
  it("shows the saved MFA policy and session timeout, not the defaults", async () => {
    // Both live under `security` in GET /settings; the page read them from the
    // top level, so it always showed Optional / 480 — an admin who had
    // required MFA was told it was optional.
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings", () => HttpResponse.json(SETTINGS)),
    );
    renderPage();

    expect(await screen.findByRole("spinbutton")).toHaveValue(30);
    expect(screen.getByRole("combobox")).toHaveTextContent("Required");
  });

  it("saves an edited timeout as a number under settings", async () => {
    let body: unknown = null;
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings", () => HttpResponse.json(SETTINGS)),
      http.put("*/api/v1/settings", async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ message: "Settings updated", settings: {} });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    const timeout = await screen.findByRole("spinbutton");
    await user.clear(timeout);
    await user.type(timeout, "45");
    await user.click(screen.getByRole("button", { name: /Save/ }));

    await waitFor(() => expect(body).not.toBeNull());
    expect(body).toEqual({ settings: { session_timeout_minutes: 45 } });
  });

  it("admits a custom role holding settings.manage", async () => {
    server.use(
      me(["settings.manage"], "employee"),
      http.get("*/api/v1/settings", () => HttpResponse.json(SETTINGS)),
    );
    renderPage();

    expect(await screen.findByRole("spinbutton")).toBeInTheDocument();
    expect(screen.queryByTestId("role-gate-denied")).not.toBeInTheDocument();
  });
});

describe("<SettingsPage> SSO tab", () => {
  it("names the metadata URL's copy button", async () => {
    tab = "sso";
    server.use(
      me(["settings.manage"]),
      http.get("*/api/v1/settings", () => HttpResponse.json(SETTINGS)),
    );
    renderPage();

    expect(
      await screen.findByText(SETTINGS.sso.metadata_url),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Copy" })).toBeInTheDocument();
  });
});
