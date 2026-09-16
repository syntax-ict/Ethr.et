import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import {
  ActiveSessionsCard,
  TrustedDevicesCard,
} from "@/features/auth/components/active-sessions-card";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

/**
 * Active sessions and trusted devices are security controls, and both sat at
 * zero coverage. The interesting failure is not "the list renders" — it is a
 * revoke that reports success while the revoked session stays on screen. A
 * missing `invalidateQueries` produces exactly that: the user signs a stolen
 * session out, sees a green toast, and still sees the session listed. So every
 * revoke here is asserted by what the list shows *afterwards*, never by the
 * request having been made.
 */

interface SessionRow {
  id: number;
  device: string;
  ip_address: string | null;
  last_used_at: string | null;
  created_at: string | null;
  expires_at: string | null;
  is_current: boolean;
}

function buildSession(overrides: Partial<SessionRow> = {}): SessionRow {
  return {
    id: 1,
    device: "Chrome on Windows",
    ip_address: "196.189.0.1",
    last_used_at: "2026-09-16T08:30:00Z",
    created_at: "2026-09-01T08:30:00Z",
    expires_at: null,
    is_current: false,
    ...overrides,
  };
}

/** `useDateFormatters` reads `tenant.timezone` from the /auth/me query. */
function mockMe(timezone: string | null = "Africa/Addis_Ababa") {
  server.use(
    http.get("*/auth/me", () =>
      HttpResponse.json({
        user: { public_id: "01HZUSER", name: "Abebe", role: "employee" },
        permissions: [],
        tenant: {
          public_id: "01HZTENANT",
          name: "Acme",
          subdomain: "acme",
          status: "active",
          timezone,
        },
      }),
    ),
  );
}

function renderCard(ui: React.ReactElement) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(<QueryClientProvider client={qc}>{ui}</QueryClientProvider>);
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("<ActiveSessionsCard>", () => {
  it("badges the current session and offers no way to revoke it", async () => {
    mockMe();
    server.use(
      http.get("*/auth/sessions", () =>
        HttpResponse.json({
          data: [
            buildSession({ id: 1, device: "This browser", is_current: true }),
            buildSession({ id: 2, device: "Firefox on Ubuntu" }),
          ],
        }),
      ),
    );

    renderCard(<ActiveSessionsCard />);

    expect(await screen.findByText("Firefox on Ubuntu")).toBeInTheDocument();
    expect(screen.getByText("This device")).toBeInTheDocument();

    // One row is revocable, one is not — signing yourself out from a tidy-up
    // screen would be a surprise, and Log out already does it.
    expect(screen.getAllByRole("button", { name: /Revoke/ })).toHaveLength(1);
  });

  it("drops a revoked session from the list, not just from the request log", async () => {
    mockMe();
    let sessions = [
      buildSession({ id: 1, device: "This browser", is_current: true }),
      buildSession({ id: 2, device: "Firefox on Ubuntu" }),
    ];
    let deletedId: number | null = null;

    server.use(
      http.get("*/auth/sessions", () => HttpResponse.json({ data: sessions })),
      http.delete("*/auth/sessions/:id", ({ params }) => {
        deletedId = Number(params.id);
        sessions = sessions.filter((s) => s.id !== deletedId);
        return HttpResponse.json({ message: "ok", was_current: false });
      }),
    );

    renderCard(<ActiveSessionsCard />);
    await screen.findByText("Firefox on Ubuntu");

    fireEvent.click(screen.getByRole("button", { name: /Revoke/ }));

    // The refetch is the security property. Without it the revoked session is
    // still on screen and the user has no reason to think otherwise.
    await waitFor(() =>
      expect(screen.queryByText("Firefox on Ubuntu")).not.toBeInTheDocument(),
    );
    expect(deletedId).toBe(2);

    const { toast } = await import("sonner");
    expect(toast.success).toHaveBeenCalledWith("Session signed out.");
  });

  it("refreshes trusted devices too when every other session is signed out", async () => {
    mockMe();
    let sessions = [
      buildSession({ id: 1, device: "This browser", is_current: true }),
      buildSession({ id: 2, device: "Firefox on Ubuntu" }),
    ];
    let devices = [
      { id: 9, device_name: "Old phone", last_used_at: null, expires_at: null },
    ];

    server.use(
      http.get("*/auth/sessions", () => HttpResponse.json({ data: sessions })),
      http.get("*/auth/devices", () => HttpResponse.json({ data: devices })),
      http.post("*/auth/sessions/revoke-all", () => {
        sessions = sessions.filter((s) => s.is_current);
        // Signing out everywhere untrusts the devices too, which is why the
        // hook invalidates both keys. If it only invalidated sessions, the
        // device below would linger and still look like it skips MFA.
        devices = [];
        return HttpResponse.json({ message: "ok", revoked: 1 });
      }),
    );

    renderCard(
      <>
        <ActiveSessionsCard />
        <TrustedDevicesCard />
      </>,
    );
    await screen.findByText("Firefox on Ubuntu");
    await screen.findByText("Old phone");

    fireEvent.click(
      screen.getByRole("button", { name: /Sign out all other sessions/ }),
    );

    await waitFor(() =>
      expect(screen.queryByText("Firefox on Ubuntu")).not.toBeInTheDocument(),
    );
    await waitFor(() =>
      expect(screen.queryByText("Old phone")).not.toBeInTheDocument(),
    );
  });

  it("says so when the list cannot be loaded, and retries", async () => {
    mockMe();
    let attempt = 0;
    server.use(
      http.get("*/auth/sessions", () => {
        attempt += 1;
        if (attempt === 1) {
          return new HttpResponse(null, { status: 500 });
        }
        return HttpResponse.json({
          data: [buildSession({ id: 2, device: "Firefox on Ubuntu" })],
        });
      }),
    );

    renderCard(<ActiveSessionsCard />);

    // Failing silently here would leave a user believing they have no other
    // sessions, which is the opposite of the truth.
    expect(
      await screen.findByText("Could not load your sessions."),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /Try again/ }));

    expect(await screen.findByText("Firefox on Ubuntu")).toBeInTheDocument();
  });

  it("renders last-used in the tenant timezone, not the browser one", async () => {
    // Deliberately nowhere near the default or the runner: UTC+14. 08:30Z is
    // 22:30 the same day there and 11:30 in Addis, so the hour alone says which
    // zone was used — whichever zone the machine running this happens to be in.
    mockMe("Pacific/Kiritimati");
    server.use(
      http.get("*/auth/sessions", () =>
        HttpResponse.json({
          data: [
            buildSession({
              id: 2,
              device: "Firefox on Ubuntu",
              last_used_at: "2026-09-16T08:30:00Z",
            }),
          ],
        }),
      ),
    );

    renderCard(<ActiveSessionsCard />);
    await screen.findByText("Firefox on Ubuntu");

    // en-GB renders September as `Sep` on older ICU and `Sept` on newer, so the
    // month is matched loosely and the hour — the part under test — exactly.
    await waitFor(() =>
      expect(screen.getByText(/16 Sept? 2026, 22:30/)).toBeInTheDocument(),
    );
  });

  it("falls back to Addis Ababa when the tenant has no timezone set", async () => {
    mockMe(null);
    server.use(
      http.get("*/auth/sessions", () =>
        HttpResponse.json({
          data: [
            buildSession({
              id: 2,
              device: "Firefox on Ubuntu",
              last_used_at: "2026-09-16T08:30:00Z",
            }),
          ],
        }),
      ),
    );

    renderCard(<ActiveSessionsCard />);
    await screen.findByText("Firefox on Ubuntu");

    await waitFor(() =>
      expect(screen.getByText(/16 Sept? 2026, 11:30/)).toBeInTheDocument(),
    );
  });

  it("shows a never-used session as not used yet rather than an epoch date", async () => {
    mockMe();
    server.use(
      http.get("*/auth/sessions", () =>
        HttpResponse.json({
          data: [
            buildSession({
              id: 2,
              device: "Firefox on Ubuntu",
              last_used_at: null,
            }),
          ],
        }),
      ),
    );

    renderCard(<ActiveSessionsCard />);
    await screen.findByText("Firefox on Ubuntu");

    expect(screen.getByText(/Not used yet/)).toBeInTheDocument();
  });
});

describe("<TrustedDevicesCard>", () => {
  it("drops a removed device from the list", async () => {
    mockMe();
    let devices = [
      {
        id: 9,
        device_name: "Old phone",
        last_used_at: null,
        expires_at: "2026-10-16T08:30:00Z",
      },
    ];

    server.use(
      http.get("*/auth/devices", () => HttpResponse.json({ data: devices })),
      http.delete("*/auth/devices/:id", ({ params }) => {
        devices = devices.filter((d) => d.id !== Number(params.id));
        return HttpResponse.json({ message: "ok" });
      }),
    );

    const { container } = renderCard(<TrustedDevicesCard />);
    await screen.findByText("Old phone");

    const removeButton = container.querySelector(
      "button.text-destructive",
    ) as HTMLButtonElement;
    fireEvent.click(removeButton);

    // A device that still appears trusted is a device the user believes is
    // still skipping MFA.
    await waitFor(() =>
      expect(screen.queryByText("Old phone")).not.toBeInTheDocument(),
    );

    const { toast } = await import("sonner");
    expect(toast.success).toHaveBeenCalledWith(
      "Device removed. MFA will be required on it again.",
    );
  });

  it("names an unnamed device instead of rendering a blank row", async () => {
    mockMe();
    server.use(
      http.get("*/auth/devices", () =>
        HttpResponse.json({
          data: [
            {
              id: 9,
              device_name: null,
              last_used_at: null,
              expires_at: null,
            },
          ],
        }),
      ),
    );

    renderCard(<TrustedDevicesCard />);

    expect(await screen.findByText("Unknown device")).toBeInTheDocument();
  });
});
