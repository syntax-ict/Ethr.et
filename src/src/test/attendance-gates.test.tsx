import { describe, it, expect } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import AttendanceSettingsPage from "@/app/(dashboard)/attendance/settings/page";
import OvertimePage from "@/app/(dashboard)/attendance/overtime/page";

/**
 * These pages were gated on the HR-admin role tier while their endpoints check
 * an ability. A custom role holding the ability was turned away at the door;
 * one at the tier without it was let in to a page of 403s.
 */

function me(role: string, permissions: string[]) {
  return http.get("*/api/v1/auth/me", () =>
    HttpResponse.json({
      user: { public_id: "U1", name: "Test User", role },
      tenant: { public_id: "T1", name: "Demo", timezone: "Africa/Addis_Ababa" },
      permissions,
    }),
  );
}

/** Mirrors AttendanceSettingResource::toArray. */
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
  updated_at: "2026-09-01T06:00:00+00:00",
};

function renderWithQuery(ui: ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>,
  );
}

describe("attendance page gates follow the API's abilities", () => {
  it("opens attendance settings to a custom role holding attendance.manage", async () => {
    server.use(
      me("custom", ["attendance.manage"]),
      http.get("*/api/v1/attendance/settings", () =>
        HttpResponse.json(SETTINGS),
      ),
    );
    renderWithQuery(<AttendanceSettingsPage />);

    expect(
      await screen.findByRole("button", { name: /Save/ }),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("role-gate-denied")).toBeNull();
  });

  it("turns away an HR admin whose role lacks attendance.manage", async () => {
    // The gate renders "denied" while /auth/me is in flight, so wait for the
    // permissions to land before asserting it stays shut.
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    server.use(me("hr_admin", ["employee.create"]));
    render(
      <QueryClientProvider client={queryClient}>
        <AttendanceSettingsPage />
      </QueryClientProvider>,
    );

    await waitFor(() =>
      expect(queryClient.getQueryData(["auth", "me"])).toBeDefined(),
    );
    await new Promise((r) => setTimeout(r, 100));
    expect(screen.getByTestId("role-gate-denied")).toBeInTheDocument();
  });

  it("opens the overtime summary to a custom role holding attendance.viewAll", async () => {
    server.use(
      me("custom", ["attendance.viewAll"]),
      http.get("*/api/v1/attendance/overtime", () =>
        HttpResponse.json({
          period: "monthly",
          employees: [
            {
              employee_public_id: "E1",
              employee_name: "Abebe Kebede",
              total_overtime_minutes: 720,
              days_with_overtime: 4,
            },
          ],
        }),
      ),
    );
    renderWithQuery(<OvertimePage />);

    expect(await screen.findByText("Abebe Kebede")).toBeInTheDocument();
  });
});
