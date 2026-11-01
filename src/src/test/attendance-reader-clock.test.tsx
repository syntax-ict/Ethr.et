import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
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
import KioskPage from "@/app/kiosk/page";
import { CalendarProvider } from "@/lib/calendar/calendar-context";

/**
 * The punch screens follow the reader's clock: an Amharic reader on the
 * Ethiopian calendar reads 07:30 in Addis as 1:30 ጠዋት. They used to print a
 * 24-hour (phone) or 12-hour English (kiosk) clock whatever the reader chose.
 */

/** 07:30:15 in Addis Ababa on 7 October 2026, Meskerem 27, 2019. */
const NOW = new Date("2026-10-07T04:30:15Z");

beforeEach(() => {
  vi.useFakeTimers({ toFake: ["Date"] });
  vi.setSystemTime(NOW);
});

afterEach(() => {
  vi.useRealTimers();
  localStorage.removeItem("ethr.calendar");
  localStorage.removeItem("kiosk_token");
  localStorage.setItem("locale", "en");
});

const myShift = {
  date: "2026-10-07",
  timezone: "Africa/Addis_Ababa",
  working_day: true,
  holiday: null,
  on_leave: false,
  shift: {
    public_id: "S1",
    name: "Day",
    start_time: "06:00:00",
    end_time: "14:00:00",
    crosses_midnight: false,
    grace_minutes: 15,
    early_departure_minutes: 15,
    starts_at: "2026-10-07T03:00:00Z",
    ends_at: "2026-10-07T11:00:00Z",
  },
  attendance: {
    status: "checked_in",
    record_public_id: "R1",
    check_in: "2026-10-07T03:05:00Z",
    check_out: null,
    status_label: "Present",
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

describe("the phone punch screen", () => {
  beforeEach(() => {
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
      http.get("*/api/v1/attendance/my-shift", () =>
        HttpResponse.json(myShift),
      ),
    );
  });

  it("shows Ethiopian time to an Amharic reader on the Ethiopian calendar", async () => {
    localStorage.setItem("locale", "am");
    localStorage.setItem("ethr.calendar", "ethiopian");
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <CalendarProvider>
          <MobilePunchPage />
        </CalendarProvider>
      </QueryClientProvider>,
    );

    // The running clock, the date, the shift and the check-in time.
    expect(await screen.findByText("1:30:15 ጠዋት")).toBeInTheDocument();
    expect(screen.getByText(/መስከረም 27/)).toBeInTheDocument();
    expect(await screen.findByText(/12:00 ጠዋት/)).toBeInTheDocument();
    expect(screen.getByText(/8:00 ቀን/)).toBeInTheDocument();
    expect(screen.getByText(/12:05 ጠዋት/)).toBeInTheDocument();
    expect(screen.queryByText("07:30:15")).toBeNull();
  });

  it("keeps the 24-hour clock for an English reader", async () => {
    localStorage.setItem("locale", "en");
    localStorage.setItem("ethr.calendar", "gregorian");
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <CalendarProvider>
          <MobilePunchPage />
        </CalendarProvider>
      </QueryClientProvider>,
    );

    expect(await screen.findByText("07:30:15")).toBeInTheDocument();
    expect(await screen.findByText(/06:00/)).toBeInTheDocument();
  });
});

describe("the kiosk", () => {
  beforeEach(() => {
    localStorage.setItem("kiosk_token", "good-token");
    server.use(
      http.post("*/kiosk/authenticate", () =>
        HttpResponse.json({
          session: { public_id: "K1", name: "Front desk", branch: null },
          tenant: { name: "Acme", subdomain: "acme", logo_path: null },
          settings: { pin_required: false, auto_reset_seconds: 4 },
        }),
      ),
    );
  });

  it("shows Ethiopian time and date when it reads Amharic", async () => {
    localStorage.setItem("locale", "am");
    render(<KioskPage />);

    expect(await screen.findByText("1:30 ጠዋት")).toBeInTheDocument();
    expect(screen.getByText(/መስከረም 27/)).toBeInTheDocument();
  });

  it("shows the 24-hour clock and the Gregorian date in English", async () => {
    localStorage.setItem("locale", "en");
    render(<KioskPage />);

    expect(await screen.findByText("07:30")).toBeInTheDocument();
    expect(screen.getByText(/Wednesday, 7 Oct/)).toBeInTheDocument();
  });

  it("says when the server recorded the punch, not the terminal's time", async () => {
    server.use(
      http.post("*/kiosk/check-in", () =>
        HttpResponse.json({
          employee_name: "Abebe Kebede",
          was_duplicate: false,
          punch: "check_in",
          check_in: "2026-10-07T04:15:00Z",
          check_out: null,
        }),
      ),
    );
    localStorage.setItem("locale", "en");
    const user = userEvent.setup();
    render(<KioskPage />);

    await user.type(
      await screen.findByRole("textbox", { name: /employee code/i }),
      "EMP-0001",
    );
    await user.click(screen.getByRole("button", { name: /^record$/i }));

    // 07:15 in Addis, while the terminal's own clock reads 07:30.
    expect(await screen.findByText(/07:15/)).toBeInTheDocument();
  });
});
