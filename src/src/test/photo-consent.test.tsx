import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

vi.mock("@/lib/hooks/useOfflineSync", () => ({
  useOfflineSync: () => ({
    isOnline: true,
    pendingCount: 0,
    syncing: false,
    syncNow: vi.fn(),
  }),
}));
vi.mock("@/lib/attendance/offline-grant", () => ({
  currentOfflineGrant: () => null,
  refreshOfflineGrant: vi.fn(),
}));
vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

import MobilePunchPage from "@/app/(dashboard)/attendance/mobile/page";
import { PhotoConsentCard } from "@/features/profile/components/photo-consent-card";

/**
 * Consent to the attendance selfie (Proclamation 1321/2024 treats a facial
 * image as sensitive data): the phone asks before its camera opens, and the
 * employee can agree or withdraw from their profile.
 */
function renderWithQuery(ui: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{ui}</QueryClientProvider>,
  );
}

const myShift = (consent: boolean) => ({
  date: "2026-10-17",
  holiday: null,
  on_leave: false,
  shift: null,
  attendance: {
    status: "not_checked_in",
    record_public_id: null,
    check_in: null,
    check_out: null,
    status_label: null,
    auto_checked_out: false,
  },
  policy: {
    mobile_enabled: true,
    web_enabled: true,
    geofence_required: false,
    photo_required: true,
    photo_consent: consent,
    accuracy_threshold_meters: 100,
    offline_sync_enabled: true,
    geofence: null,
  },
  assistant: {
    reminders: false,
    reminders_allowed: false,
    reminder_lead_minutes: 10,
    auto_check_in: false,
    auto_check_in_allowed: false,
    auto_checkout: false,
    auto_checkout_after_minutes: 0,
  },
});

let getUserMedia: ReturnType<typeof vi.fn>;

beforeEach(() => {
  getUserMedia = vi.fn().mockRejectedValue(new Error("no camera in tests"));
  Object.defineProperty(navigator, "mediaDevices", {
    configurable: true,
    value: { getUserMedia },
  });
  Object.defineProperty(navigator, "geolocation", {
    configurable: true,
    value: {
      getCurrentPosition: (ok: PositionCallback) =>
        ok({
          coords: { latitude: 9.02, longitude: 38.75, accuracy: 10 },
        } as GeolocationPosition),
    },
  });
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "U1",
          name: "Almaz",
          role: "employee",
          employee_public_id: "E1",
        },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: [],
      }),
    ),
  );
});
afterEach(() => vi.clearAllMocks());

describe("attendance photo consent", () => {
  it("asks before the camera opens, and opens it once the employee agrees", async () => {
    let agreed = false;
    server.use(
      http.get("*/api/v1/attendance/my-shift", () =>
        HttpResponse.json(myShift(agreed)),
      ),
      http.post("*/api/v1/profile/photo-consent", () => {
        agreed = true;
        return HttpResponse.json({
          given: true,
          version: "2026-10-17",
          given_at: "2026-10-17T09:00:00+03:00",
          current_version: "2026-10-17",
        });
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<MobilePunchPage />);

    await user.click(
      await screen.findByRole("button", { name: "Take a selfie" }),
    );

    const dialog = await screen.findByRole("dialog", {
      name: "Attendance photo",
    });
    expect(dialog).toHaveTextContent("Proclamation No. 1321/2024");
    expect(dialog).toHaveTextContent("on servers in Ethiopia");
    expect(getUserMedia).not.toHaveBeenCalled();

    await user.click(screen.getByRole("button", { name: "I agree" }));
    await waitFor(() => expect(agreed).toBe(true));
    await waitFor(() => expect(getUserMedia).toHaveBeenCalledTimes(1));
  });

  it("does not ask again once the employee has agreed", async () => {
    server.use(
      http.get("*/api/v1/attendance/my-shift", () =>
        HttpResponse.json(myShift(true)),
      ),
    );
    const user = userEvent.setup();
    renderWithQuery(<MobilePunchPage />);

    await user.click(
      await screen.findByRole("button", { name: "Take a selfie" }),
    );

    await waitFor(() => expect(getUserMedia).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("lets the employee agree and withdraw from their profile", async () => {
    let state = {
      given: false,
      version: null as string | null,
      given_at: null as string | null,
      current_version: "2026-10-17",
    };
    const calls: string[] = [];
    server.use(
      http.get("*/api/v1/profile", () =>
        HttpResponse.json({
          preferences: { locale: "en", theme: "system", calendar: "gregorian" },
        }),
      ),
      http.get("*/api/v1/profile/photo-consent", () =>
        HttpResponse.json(state),
      ),
      http.post("*/api/v1/profile/photo-consent", () => {
        calls.push("give");
        state = {
          given: true,
          version: "2026-10-17",
          given_at: "2026-10-17T09:00:00+03:00",
          current_version: "2026-10-17",
        };
        return HttpResponse.json(state);
      }),
      http.delete("*/api/v1/profile/photo-consent", () => {
        calls.push("withdraw");
        state = { ...state, given: false, version: null, given_at: null };
        return HttpResponse.json(state);
      }),
    );
    const user = userEvent.setup();
    renderWithQuery(<PhotoConsentCard />);

    expect(await screen.findByText(/You have not agreed/)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "I agree" }));
    expect(
      await screen.findByRole("button", { name: "Withdraw consent" }),
    ).toBeInTheDocument();
    expect(screen.getByText(/You agreed on/)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Withdraw consent" }));
    await screen.findByRole("button", { name: "I agree" });
    expect(calls).toEqual(["give", "withdraw"]);
  });
});
