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
const queue = vi.hoisted(() => ({ enqueue: vi.fn() }));
vi.mock("@/lib/offline-queue", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/offline-queue")>()),
  enqueueOfflineRecord: queue.enqueue,
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
  queue.enqueue.mockReset();
  queue.enqueue.mockResolvedValue(undefined);
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

  /** A working day whose shift is under way, so the rail has a window. */
  function shiftUnderWay(over: Record<string, unknown> = {}) {
    const now = Date.now();
    return {
      ...myShift,
      day: { kind: "working", weekend: false, holiday: null },
      shift: {
        public_id: "S1",
        name: "Day shift",
        start_time: "08:00:00",
        end_time: "17:00:00",
        crosses_midnight: false,
        grace_minutes: 10,
        early_departure_minutes: 10,
        starts_at: new Date(now - 60 * 60_000).toISOString(),
        ends_at: new Date(now + 7 * 60 * 60_000).toISOString(),
      },
      ...over,
    };
  }

  it("draws the shift rail and a moment line on a working day with a shift", async () => {
    signedInAs("E1");
    server.use(
      http.get("*/api/v1/attendance/my-shift", () =>
        HttpResponse.json(shiftUnderWay()),
      ),
    );
    renderScreen();

    expect(
      await screen.findByRole("img", { name: /Your shift today, from/ }),
    ).toBeInTheDocument();
    expect(await screen.findByText(/Started .* ago/)).toBeInTheDocument();
  });

  it("draws no rail when there is no shift", async () => {
    signedInAs("E1");
    server.use(
      http.get("*/api/v1/attendance/my-shift", () =>
        HttpResponse.json(myShift),
      ),
    );
    renderScreen();

    await screen.findByRole("button", { name: /check in/i });
    expect(screen.queryByRole("img", { name: /Your shift today/ })).toBeNull();
    expect(screen.queryByText(/Started .* ago/)).toBeNull();
  });

  it("confirms a check-in in place and drops what the punch needed", async () => {
    signedInAs("E1");
    server.use(
      http.get("*/api/v1/attendance/my-shift", () =>
        HttpResponse.json(shiftUnderWay()),
      ),
      http.post("*/api/v1/attendance/mobile/check-in", () =>
        HttpResponse.json({
          id: "R1",
          outcome: { code: "checked_in", minutes: 0, by: "mobile", at: null },
        }),
      ),
    );
    renderScreen();

    const button = await screen.findByRole("button", { name: /^check in/i });
    await waitFor(() => expect(button).toBeEnabled());
    expect(screen.getByRole("button", { name: /take a selfie/i })).toBeTruthy();
    fireEvent.click(button);

    expect(
      await screen.findByText("Checked in successfully"),
    ).toBeInTheDocument();
    expect(screen.getByText(/so far/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Done" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /take a selfie/i })).toBeNull();
    // The day strip stays above it.
    expect(
      screen.getByRole("img", { name: /Your shift today/ }),
    ).toBeInTheDocument();
  });

  it("asks for the day again once the punches saved on the phone are sent", async () => {
    signedInAs("E1");
    let lookups = 0;
    server.use(
      http.get("*/api/v1/attendance/my-shift", () => {
        lookups += 1;
        return HttpResponse.json(myShift);
      }),
    );
    sync.pendingCount = 1;
    renderScreen();
    await screen.findByRole("button", { name: /^check in/i });
    await waitFor(() => expect(lookups).toBe(1));

    // The page re-renders every second, which is when it reads the new count.
    sync.pendingCount = 0;
    await waitFor(() => expect(lookups).toBe(2), { timeout: 4000 });
  });

  describe("a punch the server does not answer", () => {
    /** The shift lookup, and a check-in route that the test controls. */
    function withShift(over: Record<string, unknown> = {}) {
      server.use(
        http.get("*/api/v1/attendance/my-shift", () =>
          HttpResponse.json({
            ...myShift,
            policy: { ...myShift.policy, ...over },
          }),
        ),
      );
    }

    async function pressCheckIn() {
      const button = await screen.findByRole("button", { name: /^check in/i });
      await waitFor(() => expect(button).toBeEnabled());
      fireEvent.click(button);
    }

    it("says there was no answer and offers to save here or try again", async () => {
      signedInAs("E1");
      withShift();
      server.use(
        http.post("*/api/v1/attendance/mobile/check-in", () =>
          HttpResponse.error(),
        ),
      );
      renderScreen();
      await pressCheckIn();

      const alert = await screen.findByText(/No answer from the server/);
      expect(alert).toBeInTheDocument();
      expect(alert.textContent).toMatch(/Your .+ at .+ is not recorded yet./);
      expect(
        screen.getByRole("button", { name: "Save on this phone" }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: "Try again" }),
      ).toBeInTheDocument();
    });

    it("saves the same key and the first tap's time on the phone", async () => {
      signedInAs("E1");
      withShift();
      const bodies: Array<Record<string, unknown>> = [];
      server.use(
        http.post(
          "*/api/v1/attendance/mobile/check-in",
          async ({ request }) => {
            bodies.push((await request.json()) as Record<string, unknown>);
            return HttpResponse.error();
          },
        ),
      );
      renderScreen();

      const firstTapFrom = Date.now();
      await pressCheckIn();
      const firstTapTo = Date.now();
      const save = await screen.findByRole("button", {
        name: "Save on this phone",
      });
      await new Promise((resolve) => setTimeout(resolve, 40));
      fireEvent.click(save);

      expect(await screen.findByText(/Saved offline/)).toBeInTheDocument();
      expect(queue.enqueue).toHaveBeenCalledTimes(1);
      const saved = queue.enqueue.mock.calls[0][0];
      expect(bodies).toHaveLength(1);
      expect(saved.idempotency_key).toBe(bodies[0].idempotency_key);
      expect(saved.type).toBe("check_in");
      const capturedAt = new Date(saved.captured_at as string).getTime();
      expect(capturedAt).toBeGreaterThanOrEqual(firstTapFrom);
      expect(capturedAt).toBeLessThanOrEqual(firstTapTo);
    });

    it("stops saying the punch did not arrive once the day shows it", async () => {
      signedInAs("E1");
      let arrived = false;
      server.use(
        http.get("*/api/v1/attendance/my-shift", () =>
          HttpResponse.json({
            ...myShift,
            attendance: {
              ...myShift.attendance,
              status: arrived ? "checked_in" : "not_checked_in",
            },
          }),
        ),
        http.post("*/api/v1/attendance/mobile/check-in", () => {
          // The request landed; only its answer was lost.
          arrived = true;
          return HttpResponse.error();
        }),
      );
      renderScreen();
      await pressCheckIn();

      await waitFor(() =>
        expect(screen.queryByText(/No answer from the server/)).toBeNull(),
      );
      expect(
        screen.queryByRole("button", { name: "Save on this phone" }),
      ).toBeNull();
    });

    it("tries again under the same key", async () => {
      signedInAs("E1");
      withShift();
      const keys: unknown[] = [];
      server.use(
        http.post(
          "*/api/v1/attendance/mobile/check-in",
          async ({ request }) => {
            keys.push(
              ((await request.json()) as Record<string, unknown>)
                .idempotency_key,
            );
            return keys.length === 1
              ? HttpResponse.error()
              : HttpResponse.json({ id: "R1" });
          },
        ),
      );
      renderScreen();
      await pressCheckIn();

      fireEvent.click(await screen.findByRole("button", { name: "Try again" }));

      await waitFor(() => expect(keys).toHaveLength(2));
      expect(keys[1]).toBe(keys[0]);
      expect(queue.enqueue).not.toHaveBeenCalled();
    });

    it("keeps a real refusal as the server's message, with no way to save it", async () => {
      signedInAs("E1");
      withShift();
      server.use(
        http.post("*/api/v1/attendance/mobile/check-in", () =>
          HttpResponse.json(
            { detail: "You are too far from the branch." },
            { status: 422 },
          ),
        ),
      );
      renderScreen();
      await pressCheckIn();

      expect(
        await screen.findByText("You are too far from the branch."),
      ).toBeInTheDocument();
      expect(screen.queryByText(/No answer from the server/)).toBeNull();
      expect(
        screen.queryByRole("button", { name: "Save on this phone" }),
      ).toBeNull();
      expect(screen.queryByRole("button", { name: "Try again" })).toBeNull();
    });

    it("offers only Try again where the organisation does not allow punches saved offline", async () => {
      signedInAs("E1");
      withShift({ offline_sync_enabled: false });
      server.use(
        http.post("*/api/v1/attendance/mobile/check-in", () =>
          HttpResponse.error(),
        ),
      );
      renderScreen();
      await pressCheckIn();

      expect(
        await screen.findByRole("button", { name: "Try again" }),
      ).toBeInTheDocument();
      expect(
        screen.queryByRole("button", { name: "Save on this phone" }),
      ).toBeNull();
    });
  });
});
