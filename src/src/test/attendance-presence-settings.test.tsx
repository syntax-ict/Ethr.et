import { describe, it, expect } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AttendanceSettingsPage from "@/app/(dashboard)/settings/attendance/page";
import { SetupStatusPanel } from "@/features/attendance/components/setup-status-panel";

/**
 * Presence checks: the organisation's one setting (off, or up to 1, 2 or 3 a
 * shift), and the setup line for people the checks cannot reach.
 */
const SETTINGS = {
  public_id: "01HZSET0000000000000000001",
  enabled_methods: ["web", "mobile"],
  geofence_required: false,
  mobile_photo_required: false,
  kiosk_pin_required: false,
  qr_expiry_minutes: 30,
  qr_auto_refresh: true,
  qr_single_use_limit: 0,
  mobile_accuracy_threshold_meters: 100,
  offline_sync_enabled: true,
  kiosk_auto_reset_seconds: 4,
  grace_period_minutes: 15,
  ot_daily_cap_minutes: 240,
  confidence_threshold: 60,
  reminder_lead_minutes: 10,
  auto_checkout_after_minutes: 120,
  photo_retention_days: 30,
  risk_review_threshold: 40,
  presence_checks_per_shift: 0,
  device_binding: "off",
  office_networks: [],
  updated_at: "2026-09-01T06:00:00+00:00",
};

function renderPage(ui: React.ReactElement) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

describe("presence checks in attendance settings", () => {
  it("saves a choice of 0 to 3 checks a shift", async () => {
    const user = userEvent.setup();
    let body: Record<string, unknown> | null = null;
    server.use(
      http.get("*/api/v1/auth/me", () =>
        HttpResponse.json({
          user: { public_id: "U1", name: "Admin", role: "custom" },
          tenant: {
            public_id: "T1",
            name: "Demo",
            timezone: "Africa/Addis_Ababa",
          },
          permissions: ["attendance.manage"],
        }),
      ),
      http.get("*/api/v1/attendance/settings", () =>
        HttpResponse.json(SETTINGS),
      ),
      http.get("*/api/v1/attendance/settings/branches", () =>
        HttpResponse.json({ organisation: {}, branches: [] }),
      ),
      http.put("*/api/v1/attendance/settings", async ({ request }) => {
        body = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...SETTINGS, ...body });
      }),
    );
    renderPage(<AttendanceSettingsPage />);

    const trigger = await screen.findByRole("combobox", {
      name: "Checks during a shift",
    });
    expect(trigger).toHaveTextContent("Off");
    expect(
      screen.getByText("Tell your staff in writing before you switch this on."),
    ).toBeInTheDocument();

    await user.click(trigger);
    const options = (await screen.findAllByRole("option")).map(
      (o) => o.textContent,
    );
    expect(options).toEqual([
      "Off",
      "Up to 1 a shift",
      "Up to 2 a shift",
      "Up to 3 a shift",
    ]);
    await user.click(screen.getByRole("option", { name: "Up to 3 a shift" }));
    await waitFor(() => expect(trigger).toHaveTextContent("Up to 3 a shift"));
    const save = screen.getByRole("button", { name: /Save/ });
    await waitFor(() => expect(save).toBeEnabled());
    await user.click(save);

    await waitFor(() =>
      expect(body).toMatchObject({ presence_checks_per_shift: 3 }),
    );
  });
});

describe("the setup line for unreachable people", () => {
  it("counts the people the checks cannot reach", async () => {
    server.use(
      http.get("*/api/v1/attendance/setup-status", () =>
        HttpResponse.json({
          checks: [
            {
              id: "presence_unreachable",
              severity: "warning",
              count: 4,
              fix_route: "/settings/attendance",
            },
          ],
        }),
      ),
    );
    renderPage(<SetupStatusPanel />);

    const line = await screen.findByRole("link", {
      name: /4 people cannot receive presence checks/,
    });
    expect(
      within(line).getByText(/No notifications on their phone/),
    ).toBeVisible();
  });
});
