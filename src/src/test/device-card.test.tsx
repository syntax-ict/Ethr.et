import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeviceCard } from "@/features/attendance/components/device-card";
import type {
  AttendanceDeviceInfo,
  AttendanceDeviceState,
} from "@/features/attendance/api";

/**
 * The punch screen's phone card. "Your registered phone" is the same answer
 * on every browser, so the card may say "this is it" only where this browser
 * is known to be that phone; on any other phone it names the registered one
 * and offers to register this one, which is how a lost phone is replaced.
 */
vi.mock("@/lib/attendance/passkey", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  passkeySupported: () => Promise.resolve(true),
}));

vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useCurrentTenant: () => ({ data: { timezone: "Africa/Addis_Ababa" } }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

function phone(
  overrides: Partial<AttendanceDeviceInfo> = {},
): AttendanceDeviceInfo {
  return {
    public_id: "dev-1",
    status: "active",
    label: "iPhone, Safari",
    synced_passkey: false,
    enrolled_inside_geofence: true,
    created_at: "2026-10-20T06:00:00Z",
    last_used_at: null,
    approved_at: null,
    ...overrides,
  };
}

function serve(overrides: Partial<AttendanceDeviceState>) {
  server.use(
    http.get("*/attendance/device", () =>
      HttpResponse.json({
        binding: "enforce",
        rp_id: "ethr.et",
        host_allowed: true,
        canonical_url: null,
        device: null,
        pending: null,
        exempt_until: null,
        ...overrides,
      }),
    ),
  );
}

function renderCard() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <DeviceCard />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("DeviceCard", () => {
  it("says this is the registered phone only where this browser is that phone", async () => {
    localStorage.setItem("ethr.attendance.this-phone", "dev-1");
    serve({ device: phone() });

    renderCard();

    expect(
      await screen.findByText("This is your registered phone"),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Register this phone" }),
    ).toBeNull();
  });

  it("on another phone, names the registered one and offers to register this one", async () => {
    localStorage.setItem("ethr.attendance.this-phone", "someone-elses");
    serve({ device: phone() });

    renderCard();

    expect(
      await screen.findByText("Your registered phone: iPhone, Safari"),
    ).toBeInTheDocument();
    expect(screen.queryByText("This is your registered phone")).toBeNull();
    expect(
      await screen.findByRole("button", { name: "Register this phone" }),
    ).toBeInTheDocument();
  });

  it("with nothing known about this browser, does not claim it is the registered phone", async () => {
    serve({ device: phone() });

    renderCard();

    expect(
      await screen.findByText("Your registered phone: iPhone, Safari"),
    ).toBeInTheDocument();
  });

  it("on the phone waiting to replace it, says that phone is waiting", async () => {
    localStorage.setItem("ethr.attendance.this-phone", "dev-2");
    serve({
      device: phone(),
      pending: phone({
        public_id: "dev-2",
        status: "pending",
        label: "Android, Chrome",
      }),
    });

    renderCard();

    expect(
      await screen.findByText("This phone is waiting for approval"),
    ).toBeInTheDocument();
  });
});
