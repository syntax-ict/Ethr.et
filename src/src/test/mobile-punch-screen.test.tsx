import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";

const sync = vi.hoisted(() => ({
  isOnline: true,
  pendingCount: 0,
}));

vi.mock("@/lib/hooks/useOfflineSync", () => ({
  useOfflineSync: () => ({
    isOnline: sync.isOnline,
    pendingCount: sync.pendingCount,
    syncing: false,
    syncNow: vi.fn(),
  }),
}));
vi.mock("@/lib/attendance/offline-grant", () => ({
  currentOfflineDevice: () => null,
  currentOfflineGrant: () => null,
  refreshOfflineGrant: vi.fn(),
}));
vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

import MobilePunchPage from "@/app/(dashboard)/attendance/mobile/page";

/**
 * The phone punch screen: what it says while it waits, what it says when the
 * phone refuses its location, and that one tap is one punch.
 */
const myShift = {
  date: "2026-10-17",
  timezone: "Africa/Addis_Ababa",
  working_day: true,
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
    qr_enabled: false,
    attendance_mode: "standard",
    geofence_required: false,
    photo_required: false,
    liveness_required: false,
    photo_consent: true,
    accuracy_threshold_meters: 100,
    offline_sync_enabled: true,
    geofence: null,
    places: [],
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
};

function signedInAs(employee: string | null) {
  server.use(
    http.get("*/api/v1/auth/me", () =>
      HttpResponse.json({
        user: {
          public_id: "U1",
          name: "Almaz",
          role: "employee",
          employee_public_id: employee,
        },
        tenant: {
          public_id: "T1",
          name: "Demo",
          timezone: "Africa/Addis_Ababa",
        },
        permissions: [],
      }),
    ),
    http.get("*/api/v1/attendance/device", () =>
      HttpResponse.json({
        binding: "off",
        host_allowed: true,
        device: null,
        pending: null,
        canonical_url: null,
      }),
    ),
  );
}

function geolocation(
  behaviour: "fix" | { code: number; message: string },
): void {
  Object.defineProperty(navigator, "geolocation", {
    configurable: true,
    value: {
      getCurrentPosition: (
        ok: PositionCallback,
        fail: PositionErrorCallback,
      ) =>
        behaviour === "fix"
          ? ok({
              coords: { latitude: 9.02, longitude: 38.75, accuracy: 10 },
              timestamp: Date.now(),
            } as GeolocationPosition)
          : fail(behaviour as unknown as GeolocationPositionError),
    },
  });
}

function renderScreen() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <MobilePunchPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  sync.isOnline = true;
  sync.pendingCount = 0;
  localStorage.setItem("locale", "en");
  geolocation("fix");
});
afterEach(() => vi.clearAllMocks());

describe("the phone punch screen", () => {
  it("shows no punch-type choice while the shift is still loading", async () => {
    signedInAs("E1");
    server.use(
      http.get("*/api/v1/attendance/my-shift", () => new Promise(() => {})),
    );
    renderScreen();

    expect(await screen.findByText("Checking your shift…")).toBeInTheDocument();
    expect(screen.queryByRole("radiogroup")).toBeNull();
  });

  it("lets a person punch when the shift lookup is slow", async () => {
    signedInAs("E1");
    server.use(
      http.get("*/api/v1/attendance/my-shift", () => new Promise(() => {})),
    );
    renderScreen();

    expect(await screen.findByText("Checking your shift…")).toBeInTheDocument();
    expect(screen.queryByRole("radiogroup")).toBeNull();

    // After the 4 second cap the screen behaves as it does with no shift data.
    expect(
      await screen.findByRole("radiogroup", {}, { timeout: 6000 }),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /check in/i })).toBeEnabled();
  }, 15000);

  it("explains a refused location in words, not the browser's message", async () => {
    signedInAs("E1");
    geolocation({ code: 1, message: "User denied Geolocation" });
    server.use(
      http.get("*/api/v1/attendance/my-shift", () =>
        HttpResponse.json(myShift),
      ),
    );
    renderScreen();

    expect(
      await screen.findByText(/Allow location for ETHR in your browser/),
    ).toBeInTheDocument();
    expect(screen.queryByText(/User denied Geolocation/)).toBeNull();
  });

  it("sends one punch for two taps in the same moment", async () => {
    signedInAs("E1");
    let posts = 0;
    server.use(
      http.get("*/api/v1/attendance/my-shift", () =>
        HttpResponse.json(myShift),
      ),
      http.post("*/api/v1/attendance/mobile/check-in", async () => {
        posts += 1;
        await new Promise((resolve) => setTimeout(resolve, 50));
        return HttpResponse.json({ id: "R1" });
      }),
    );
    renderScreen();

    const button = await screen.findByRole("button", { name: /check in/i });
    await waitFor(() => expect(button).toBeEnabled());

    act(() => {
      fireEvent.click(button);
      fireEvent.click(button);
    });

    await waitFor(() => expect(posts).toBe(1));
    await new Promise((resolve) => setTimeout(resolve, 100));
    expect(posts).toBe(1);
  });

  it("says an account with no employee record cannot check in by phone", async () => {
    signedInAs(null);
    renderScreen();

    expect(
      await screen.findByText("This account has no employee record"),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /check in/i })).toBeNull();
  });

  it("counts the punches saved on the phone while offline", async () => {
    sync.isOnline = false;
    sync.pendingCount = 2;
    signedInAs("E1");
    renderScreen();

    expect(
      await screen.findByText("2 punches saved on this phone."),
    ).toBeInTheDocument();
  });
});
