import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { toast } from "sonner";
import { server } from "./msw/server";
import NotificationPreferencesPage from "@/app/(dashboard)/notifications/preferences/page";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const TYPES = [
  "leave_requested",
  "leave_approved",
  "leave_rejected",
  "attendance_correction",
  "attendance_anomaly",
  "payslip_available",
  "payroll_processed",
  "announcement",
  "approval_reminder",
  "profile_update",
];
const CHANNELS = ["in_app", "email", "sms"];

/** NotificationPreferencesController::index(): the full merged matrix. */
function matrix() {
  return {
    notification_types: TYPES,
    channels: CHANNELS,
    preferences: Object.fromEntries(
      TYPES.map((type) => [type, { in_app: true, email: true, sms: false }]),
    ),
    channel_availability: { in_app: true, email: true, sms: false },
  };
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <NotificationPreferencesPage />
    </QueryClientProvider>,
  );
}

describe("Notification preferences", () => {
  it("says the preferences could not be loaded instead of loading forever", async () => {
    server.use(
      http.get("*/api/v1/notifications/preferences", () =>
        HttpResponse.json({ status: 500 }, { status: 500 }),
      ),
    );

    renderPage();

    expect(await screen.findByText("Couldn't load this")).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Try again" }),
    ).toBeInTheDocument();
  });

  it("names the profile-change row rather than printing its key", async () => {
    server.use(
      http.get("*/api/v1/notifications/preferences", () =>
        HttpResponse.json(matrix()),
      ),
    );

    renderPage();

    expect(await screen.findByText("Profile change")).toBeInTheDocument();
    expect(screen.queryByText("profile_update")).not.toBeInTheDocument();
  });

  it("saves the edited matrix", async () => {
    let sent: { preferences: Record<string, Record<string, boolean>> } | null =
      null;
    server.use(
      http.get("*/api/v1/notifications/preferences", () =>
        HttpResponse.json(matrix()),
      ),
      http.put("*/api/v1/notifications/preferences", async ({ request }) => {
        sent = (await request.json()) as typeof sent;
        return HttpResponse.json({
          ...matrix(),
          preferences: sent!.preferences,
        });
      }),
    );

    renderPage();
    const announcementEmail = await screen.findByRole("checkbox", {
      name: /Announcement.*Email/,
    });
    fireEvent.click(announcementEmail);
    fireEvent.click(screen.getByRole("button", { name: /Save/ }));

    await waitFor(() => expect(toast.success).toHaveBeenCalled());
    expect(sent!.preferences.announcement.email).toBe(false);
  });
});
