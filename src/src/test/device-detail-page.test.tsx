import { describe, it, expect, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeviceDetail } from "@/app/(dashboard)/devices/[id]/device-detail";

// The page is wrapped in <RoleGate anyPermission={["viewDevices"]}>; grant access directly.
// `granted` decides hasPermission: everything by default, or a seeded role's set.
const granted = vi.hoisted(() => ({ abilities: null as string[] | null }));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: (ability: string) =>
      granted.abilities === null || granted.abilities.includes(ability),
    can: { viewDevices: true },
    role: "hr_admin",
  }),
}));

/**
 * The device detail page reads two different pagination shapes, and both are
 * pinned here with responses shaped like the real ones. Sync logs are a
 * resource collection (`meta.last_page`); events are a bare Laravel paginator
 * (`last_page` at the top level, no `meta`). The page used to read
 * `events.meta.last_page`, so the Events tab never offered a second page —
 * and the first typed version of the hooks crashed on it in the browser.
 */
const DEVICE = {
  public_id: "DEV1",
  name: "Gate Simulator",
  location_description: null,
  serial_number: null,
  adapter_type: "mock",
  status: "online",
  auto_sync: true,
  sync_interval_minutes: 5,
  last_sync_at: null,
  branch: null,
  branch_public_id: null,
  attendance_records_count: 20,
  sync_logs_count: 0,
  created_at: "2026-10-01T10:00:00Z",
  updated_at: "2026-10-01T10:00:00Z",
};

const event = (n: number) => ({
  public_id: `EV${n}`,
  employee_name: `Employee ${n}`,
  employee_code: `E${n}`,
  date: "2026-10-01",
  check_in: "2026-10-01T08:00:00+03:00",
  check_out: null,
  source: "biometric",
  status: "present",
  confidence_score: 100,
  created_at: "2026-10-01T05:00:00Z",
});

function renderDetail() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<DeviceDetail routeId="DEV1" />, { wrapper: Wrapper });
}

describe("<DeviceDetail>", () => {
  it("pages through attendance events, whose paginator has no meta block", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1", () => HttpResponse.json(DEVICE)),
      http.get("*/api/v1/devices/DEV1/sync-logs", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
          links: {},
        }),
      ),
      http.get("*/api/v1/devices/DEV1/events", ({ request }) => {
        const page = Number(new URL(request.url).searchParams.get("page"));
        return HttpResponse.json({
          current_page: page,
          data: [event(page)],
          last_page: 2,
          per_page: 15,
          total: 16,
          from: page,
          to: page,
          path: "/api/v1/devices/DEV1/events",
          first_page_url: null,
          last_page_url: null,
          next_page_url: null,
          prev_page_url: null,
          links: [],
        });
      }),
    );
    const user = userEvent.setup();
    renderDetail();

    expect(await screen.findByText("Gate Simulator")).toBeInTheDocument();

    await user.click(screen.getByRole("tab", { name: "Attendance Events" }));
    const panel = await screen.findByRole("tabpanel");
    expect(await within(panel).findByText("Employee 1")).toBeInTheDocument();

    // The shared pager (one for the whole app) names its button "Next".
    await user.click(within(panel).getByRole("button", { name: "Next" }));
    expect(await within(panel).findByText("Employee 2")).toBeInTheDocument();
  });

  it("offers HR only what the seeded HR role may do", async () => {
    // HR is admitted to the page but holds device.viewAny and device.view only.
    // Pull, Edit and Delete were shown and refused with a 403 (N54).
    granted.abilities = ["device.viewAny", "device.view"];
    server.use(
      http.get("*/api/v1/devices/DEV1", () => HttpResponse.json(DEVICE)),
      http.get("*/api/v1/devices/DEV1/sync-logs", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
          links: {},
        }),
      ),
      http.get("*/api/v1/devices/DEV1/events", () =>
        HttpResponse.json({ data: [], current_page: 1, last_page: 1 }),
      ),
    );

    try {
      renderDetail();

      expect(
        await screen.findByRole("button", { name: /^test$/i }),
      ).toBeInTheDocument();
      for (const name of [/^pull$/i, /^edit$/i, /^delete$/i]) {
        expect(screen.queryByRole("button", { name })).not.toBeInTheDocument();
      }
    } finally {
      granted.abilities = null;
    }
  });
});

/*
 * A terminal that sends to ETHR is set up on the terminal, whose screen asks
 * for the address in parts. The page shows the push URL that way.
 */
describe("device detail for a terminal that sends to ETHR", () => {
  it("shows what to enter on the terminal, part by part", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1", () =>
        HttpResponse.json({
          ...DEVICE,
          adapter_type: "hikvision",
          receives_only: true,
          has_webhook_token: true,
          uses_webhook_token: true,
          webhook_token_prefix: "tok123ab",
          webhook_url_masked:
            "https://www.ethr.et/api/v1/devices/webhook/hikvision?token=tok123ab...",
        }),
      ),
      http.get("*/api/v1/devices/DEV1/sync-logs", () =>
        HttpResponse.json({ data: [] }),
      ),
      http.get("*/api/v1/devices/DEV1/events", () =>
        HttpResponse.json({ data: [], current_page: 1, last_page: 1 }),
      ),
    );
    renderDetail();

    expect(
      await screen.findByText("The terminal's settings"),
    ).toBeInTheDocument();
    expect(screen.getByText("www.ethr.et")).toBeInTheDocument();
    expect(screen.getByText("443")).toBeInTheDocument();
    expect(screen.getByText("HTTPS")).toBeInTheDocument();
    expect(
      screen.getByText("/api/v1/devices/webhook/hikvision"),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/shown only when it is created or regenerated/i),
    ).toBeInTheDocument();
    // The settings box describes the terminal and never carries a token.
    const box = screen.getByText("The terminal's settings").parentElement!;
    expect(box.textContent).not.toContain("?token=");
    expect(
      screen.getByText(
        /token part is shown only when the address is regenerated/i,
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByText("Enter these on the terminal"),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText(/token=tok123ab[0-9a-f]/),
    ).not.toBeInTheDocument();
  });
});

/*
 * A terminal that only sends is never probed or pulled from, so the page does
 * not offer to, and says what ETHR last heard from it instead (2026-11-12).
 */
describe("device detail for a terminal that has not been heard from", () => {
  const PUSH = {
    ...DEVICE,
    adapter_type: "hikvision",
    receives_only: true,
    status: "pending",
    auto_sync: false,
    has_webhook_token: true,
    uses_webhook_token: true,
    webhook_token_prefix: "tok123ab",
    webhook_url_masked:
      "https://www.ethr.et/api/v1/devices/webhook/hikvision?token=tok123ab...",
  };

  function serveDevice(device: object, syncLogs: object[] = []) {
    server.use(
      http.get("*/api/v1/devices/DEV1", () => HttpResponse.json(device)),
      http.get("*/api/v1/devices/DEV1/sync-logs", () =>
        HttpResponse.json({
          data: syncLogs,
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
          links: {},
        }),
      ),
      http.get("*/api/v1/devices/DEV1/events", () =>
        HttpResponse.json({ data: [], current_page: 1, last_page: 1 }),
      ),
    );
  }

  it("reads Not connected yet, with the setup hint, and offers neither Test nor Pull", async () => {
    serveDevice(PUSH);
    renderDetail();

    expect(await screen.findByText("Not connected yet")).toBeInTheDocument();
    expect(
      screen.getByText("Nothing received from this terminal yet."),
    ).toBeInTheDocument();
    for (const name of [/^test$/i, /^pull$/i]) {
      expect(screen.queryByRole("button", { name })).not.toBeInTheDocument();
    }
    // Online would be a claim ETHR cannot back.
    expect(screen.queryByText("Online")).not.toBeInTheDocument();
  });

  // QA 2026-11-14, D-4: the Sync History tab of a Hikvision push terminal
  // still said to pull records; only ZKTeco ADMS had the push wording.
  it("says a terminal that sends has no scheduled pulls, not to pull records", async () => {
    serveDevice(PUSH);
    renderDetail();

    expect(
      await screen.findByText(
        "This terminal sends each punch as it happens, so there are no scheduled pulls to list. Its punches are under Attendance Events.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByText(/pull records or wait for the automatic sync/i),
    ).not.toBeInTheDocument();
  });

  it("reads Quiet, with why ETHR cannot say more, for a terminal heard from today", async () => {
    serveDevice({
      ...PUSH,
      status: "quiet",
      last_sync_at: new Date(Date.now() - 3 * 3_600_000).toISOString(),
    });
    renderDetail();

    expect(await screen.findByText("Quiet")).toBeInTheDocument();
    expect(
      screen.getByText(/ETHR cannot call this terminal/),
    ).toBeInTheDocument();
    expect(screen.queryByText("Online")).not.toBeInTheDocument();
  });

  it("shows when a terminal last reported in while idle, and nothing when it has not", async () => {
    serveDevice({
      ...PUSH,
      status: "quiet",
      last_sync_at: new Date(Date.now() - 3 * 3_600_000).toISOString(),
      last_keepalive_at: new Date(Date.now() - 2 * 3_600_000).toISOString(),
    });
    const { unmount } = renderDetail();
    expect(
      await screen.findByText(/^Reports in when idle/),
    ).toBeInTheDocument();
    unmount();

    serveDevice({
      ...PUSH,
      status: "quiet",
      last_sync_at: new Date(Date.now() - 3 * 3_600_000).toISOString(),
      last_keepalive_at: null,
    });
    renderDetail();
    expect(await screen.findByText("Quiet")).toBeInTheDocument();
    expect(screen.queryByText(/^Reports in when idle/)).not.toBeInTheDocument();
  });

  it("says how long an offline terminal has been silent", async () => {
    serveDevice({
      ...PUSH,
      status: "offline",
      last_sync_at: new Date(Date.now() - 3 * 86_400_000).toISOString(),
    });
    renderDetail();

    expect(await screen.findByText(/^Last heard /)).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^test$/i })).toBeNull();
  });

  it("offers the connection switch in Edit, so a wrong registration can be fixed here", async () => {
    granted.abilities = null;
    serveDevice(PUSH);
    const user = userEvent.setup();
    renderDetail();

    await user.click(await screen.findByRole("button", { name: /^edit$/i }));

    const dialog = await screen.findByRole("dialog");
    const toggle = within(dialog).getByRole("switch", {
      name: "The terminal is on our office network",
    });
    expect(toggle).toBeChecked();
    expect(within(dialog).queryByLabelText("IP Address")).toBeNull();

    await user.click(toggle);
    expect(await within(dialog).findByLabelText("IP Address")).toBeRequired();
  });

  describe("the sync interval", () => {
    const PULLED = {
      ...DEVICE,
      adapter_type: "hikvision",
      receives_only: false,
    };

    it("starts at 5 minutes, offers no 1 minute, and says the interval is a ceiling", async () => {
      granted.abilities = null;
      serveDevice(PULLED);
      const user = userEvent.setup();
      renderDetail();

      expect(
        await screen.findByText("At most every 5 min"),
      ).toBeInTheDocument();
      await user.click(await screen.findByRole("button", { name: /^edit$/i }));
      const dialog = await screen.findByRole("dialog");

      expect(
        within(dialog).getByText(
          /at most this often, and sometimes later.*Use Pull when you need them at once\./,
        ),
      ).toBeInTheDocument();

      await user.click(within(dialog).getByLabelText("Sync Interval"));
      const options = await screen.findAllByRole("option");
      expect(options.map((o) => o.textContent)).toEqual([
        "Every 5 minutes",
        "Every 10 minutes",
        "Every 15 minutes",
        "Every 30 minutes",
        "Every 1 hour",
      ]);
    });

    it("opens a device that arrives under 5 minutes on 5, and saves 5", async () => {
      granted.abilities = null;
      serveDevice({ ...PULLED, sync_interval_minutes: 1 });
      let sent: Record<string, unknown> | null = null;
      server.use(
        http.put("*/api/v1/devices/DEV1", async ({ request }) => {
          sent = (await request.json()) as Record<string, unknown>;
          return HttpResponse.json({ ...PULLED, sync_interval_minutes: 5 });
        }),
      );
      const user = userEvent.setup();
      renderDetail();

      await user.click(await screen.findByRole("button", { name: /^edit$/i }));
      const dialog = await screen.findByRole("dialog");
      expect(within(dialog).getByLabelText("Sync Interval")).toHaveTextContent(
        "Every 5 minutes",
      );

      await user.click(
        within(dialog).getByRole("button", { name: /save changes/i }),
      );
      await vi.waitFor(() => expect(sent).not.toBeNull());
      expect(sent!.sync_interval_minutes).toBe(5);
    });

    it("shows neither the select nor the hint for a terminal that only sends", async () => {
      granted.abilities = null;
      serveDevice(PUSH);
      const user = userEvent.setup();
      renderDetail();

      await user.click(await screen.findByRole("button", { name: /^edit$/i }));
      const dialog = await screen.findByRole("dialog");
      expect(within(dialog).queryByLabelText("Sync Interval")).toBeNull();
      expect(within(dialog).queryByText(/at most this often/i)).toBeNull();
    });
  });

  it("shows why a pull failed, with advice, on a terminal ETHR calls", async () => {
    serveDevice(
      { ...DEVICE, adapter_type: "hikvision", receives_only: false },
      [
        {
          public_id: "LOG1",
          status: "offline",
          triggered_by: "schedule",
          events_found: 0,
          events_processed: 0,
          events_failed: 0,
          error_message:
            "cURL error 7: Failed to connect to 196.190.67.12 port 8000: Connection refused",
          error_code: "sdk_port",
          duration_ms: 12,
          started_at: "2026-11-12T06:00:00Z",
          completed_at: "2026-11-12T06:00:01Z",
          created_at: "2026-11-12T06:00:00Z",
        },
      ],
    );
    renderDetail();

    expect(await screen.findByText(/Connection refused/)).toBeInTheDocument();
    expect(
      screen.getByText(/which is the terminal's SDK port/),
    ).toBeInTheDocument();
    // A Hikvision terminal has the office network setting to fall back on.
    expect(
      screen.getByText(/use the office network setting/),
    ).toBeInTheDocument();
  });

  it("says the terminal called and was refused, and why", async () => {
    serveDevice({
      ...PUSH,
      webhook_rejected: {
        reason: "token_retired",
        ip: "196.190.67.12",
        at: new Date(Date.now() - 5 * 60_000).toISOString(),
      },
    });
    renderDetail();

    expect(
      await screen.findByText(/^The terminal called .* and ETHR refused it\.$/),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/still sending the token from before/),
    ).toBeInTheDocument();
  });

  it("shows nothing for a refusal it has no words for", async () => {
    serveDevice({
      ...PUSH,
      webhook_rejected: {
        reason: "something_new",
        ip: null,
        at: "2026-11-13T06:00:00Z",
      },
    });
    renderDetail();

    expect(await screen.findByText("Not connected yet")).toBeInTheDocument();
    expect(screen.queryByText(/ETHR refused it/)).toBeNull();
  });

  it("gives another vendor's refused sign-in no Hikvision advice", async () => {
    serveDevice({ ...DEVICE, adapter_type: "suprema", receives_only: false }, [
      {
        public_id: "LOG2",
        status: "offline",
        triggered_by: "schedule",
        events_found: 0,
        events_processed: 0,
        events_failed: 0,
        error_message: "HTTP 401",
        error_code: "auth",
        duration_ms: 12,
        started_at: "2026-11-12T06:00:00Z",
        completed_at: "2026-11-12T06:00:01Z",
        created_at: "2026-11-12T06:00:00Z",
      },
    ]);
    renderDetail();

    expect(
      await screen.findByText(/refused the sign-in details ETHR sent/),
    ).toBeInTheDocument();
    expect(screen.queryByText(/Digest/)).toBeNull();
    expect(screen.queryByText(/office network setting/)).toBeNull();
  });
});

/**
 * Every failed load used to read as the device's "not found" message. Only a
 * 404 means that; anything else is a failed request with a retry.
 */
describe("device detail when the device does not load", () => {
  it("says the data did not load, with a retry, for a server error", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1", () =>
        HttpResponse.json({ detail: "Server Error" }, { status: 500 }),
      ),
    );
    renderDetail();

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Couldn't load this",
    );
    expect(
      screen.getByRole("button", { name: /Try again/ }),
    ).toBeInTheDocument();
  });

  it("keeps the not-found message for a 404", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1", () =>
        HttpResponse.json({ detail: "Not found" }, { status: 404 }),
      ),
    );
    renderDetail();

    expect(await screen.findByText("Device not found")).toBeInTheDocument();
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});

/*
 * Regenerating the token changes the address the terminal posts to, so one
 * click must not do it: the page asks first and calls the API on confirm.
 */
describe("device detail: regenerating the webhook token", () => {
  it("asks before it calls the API, and calls it once on confirm", async () => {
    let calls = 0;
    server.use(
      http.get("*/api/v1/devices/DEV1", () =>
        HttpResponse.json({
          ...DEVICE,
          adapter_type: "hikvision",
          receives_only: true,
          has_webhook_token: true,
          uses_webhook_token: true,
          webhook_token_prefix: "tok123ab",
          webhook_url_masked:
            "https://www.ethr.et/api/v1/devices/webhook/hikvision?token=tok123ab...",
        }),
      ),
      http.get("*/api/v1/devices/DEV1/sync-logs", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 },
          links: {},
        }),
      ),
      http.get("*/api/v1/devices/DEV1/events", () =>
        HttpResponse.json({ data: [], current_page: 1, last_page: 1 }),
      ),
      http.post("*/api/v1/devices/DEV1/regenerate-token", () => {
        calls += 1;
        return HttpResponse.json({ webhook_token: "new" });
      }),
    );
    const user = userEvent.setup();
    renderDetail();

    await user.click(
      await screen.findByRole("button", {
        name: /regenerate to see the address again/i,
      }),
    );
    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText(/must be given the new address/i),
    ).toBeInTheDocument();
    expect(calls).toBe(0);

    await user.click(
      within(dialog).getByRole("button", { name: /regenerate token/i }),
    );
    await vi.waitFor(() => expect(calls).toBe(1));
  });
});
