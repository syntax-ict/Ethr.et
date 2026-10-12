import { describe, it, expect, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import PlatformSettingsPage from "@/app/(dashboard)/admin/platform-settings/page";

/**
 * Production, 2026-10-12: the owner filled in the public profile, saved the
 * payment section, saved the profile — and only one field was stored. Both
 * forms re-seeded from the server whenever the query delivered new data, and
 * one form's save is new data for both, so the other form's unsaved typing was
 * replaced with the stored (empty) values.
 */

vi.mock("next/navigation", () => ({
  usePathname: () => "/admin/platform-settings",
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

const STORED = {
  public_id: "01HZPLATFORM00000000000001",
  bank_name: "Commercial Bank of Ethiopia",
  bank_account_number: "1000123456789",
  bank_account_name: "ETHR",
  payment_instructions: null,
  payment_instructions_am: null,
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
  testimonial_quote: null,
  testimonial_quote_am: null,
  testimonial_author: null,
  testimonial_role: null,
  testimonial_role_am: null,
  testimonial_organisation: null,
  testimonial_consented_on: null,
};

function servePlatformSettings() {
  let gets = 0;
  server.use(
    http.get("*/api/v1/admin/platform-settings", () => {
      gets += 1;
      // A fresh object each time, as a real refetch delivers.
      return HttpResponse.json({ ...STORED });
    }),
  );
  return () => gets;
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <PlatformSettingsPage />
    </QueryClientProvider>,
  );
  return client;
}

describe("platform settings keep what has been typed", () => {
  it("keeps the profile being typed when the payment section saves", async () => {
    servePlatformSettings();
    const client = renderPage();

    const email = await screen.findByLabelText(/contact email/i);
    await userEvent.type(email, "hello@ethr.et");

    // The payment section saves: the refetch now carries changed data, which
    // used to re-seed BOTH forms and drop the unsaved profile.
    server.use(
      http.get("*/api/v1/admin/platform-settings", () =>
        HttpResponse.json({
          ...STORED,
          payment_instructions: "Pay by bank transfer.",
        }),
      ),
    );
    await act(async () => {
      await client.refetchQueries({ queryKey: ["admin", "platform-settings"] });
    });

    await waitFor(() =>
      expect(
        screen.getByDisplayValue("Pay by bank transfer."),
      ).toBeInTheDocument(),
    );
    expect(screen.getByLabelText(/contact email/i)).toHaveValue(
      "hello@ethr.et",
    );
  });

  it("still takes fresh values while nothing has been typed", async () => {
    servePlatformSettings();
    const client = renderPage();

    await screen.findByLabelText(/contact email/i);

    server.use(
      http.get("*/api/v1/admin/platform-settings", () =>
        HttpResponse.json({ ...STORED, contact_email: "saved@ethr.et" }),
      ),
    );
    await act(async () => {
      await client.refetchQueries({ queryKey: ["admin", "platform-settings"] });
    });

    await waitFor(() =>
      expect(screen.getByLabelText(/contact email/i)).toHaveValue(
        "saved@ethr.et",
      ),
    );
  });
});
