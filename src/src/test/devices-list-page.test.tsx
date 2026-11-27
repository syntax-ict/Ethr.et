import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import DevicesPage from "@/app/(dashboard)/devices/page";

/**
 * A terminal that only sends is never called, so its card does not offer Test,
 * Pull, enrolled users or history, and says what ETHR last heard from it. A
 * terminal ETHR calls keeps all of them (2026-11-12).
 */
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: { viewDevices: true },
    role: "hr_admin",
  }),
}));

vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useCurrentTenant: () => ({ data: { timezone: "Africa/Addis_Ababa" } }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const base = {
  location_description: null,
  serial_number: null,
  auto_sync: true,
  sync_interval_minutes: 5,
  last_sync_at: null,
  branch: null,
  branch_public_id: null,
  attendance_records_count: 0,
  sync_logs_count: 0,
  created_at: "2026-11-12T06:00:00Z",
  updated_at: "2026-11-12T06:00:00Z",
};

function serve(
  devices: object[],
  branches: { public_id: string; name: string }[] = [],
) {
  server.use(
    http.get("*/api/v1/devices", () =>
      HttpResponse.json({
        data: devices,
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 2 },
        links: { next: null, prev: null },
      }),
    ),
    http.get("*/api/v1/devices/unmatched-punches", () =>
      HttpResponse.json({ data: [] }),
    ),
    http.get("*/api/v1/organization/branches", () =>
      HttpResponse.json({
        data: branches,
        meta: {
          current_page: 1,
          last_page: 1,
          per_page: 25,
          total: branches.length,
        },
        links: { next: null, prev: null },
      }),
    ),
  );
}

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <DevicesPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

describe("<DevicesPage> cards", () => {
  it("shows a terminal that only sends as not connected yet, with no actions it cannot perform", async () => {
    serve([
      {
        ...base,
        public_id: "PUSH1",
        name: "Habru front door",
        adapter_type: "hikvision",
        receives_only: true,
        status: "pending",
        auto_sync: false,
      },
      {
        ...base,
        public_id: "PULL1",
        name: "Main gate",
        adapter_type: "hikvision",
        receives_only: false,
        status: "online",
      },
    ]);
    renderPage();

    const push = (await screen.findByText("Habru front door")).closest(
      '[class*="p-4"]',
    ) as HTMLElement;
    const pull = screen
      .getByText("Main gate")
      .closest('[class*="p-4"]') as HTMLElement;

    expect(within(push).getByText("Not connected yet")).toBeInTheDocument();
    expect(
      within(push).getByText("Nothing received from this terminal yet."),
    ).toBeInTheDocument();
    for (const name of [/^test$/i, /^pull$/i]) {
      expect(
        within(push).queryByRole("button", { name }),
      ).not.toBeInTheDocument();
    }

    expect(within(pull).getByText("Online")).toBeInTheDocument();
    expect(
      within(pull).getByRole("button", { name: /^test$/i }),
    ).toBeInTheDocument();
    expect(
      within(pull).getByRole("button", { name: /^pull$/i }),
    ).toBeInTheDocument();
  });
});

describe("<DevicesPage> quiet terminals", () => {
  it("reads Quiet, with why ETHR cannot say more, for a terminal that only sends, and offers it as a filter", async () => {
    serve([
      {
        ...base,
        public_id: "QUIET1",
        name: "Habru front door",
        adapter_type: "hikvision",
        receives_only: true,
        status: "quiet",
        auto_sync: false,
        last_sync_at: new Date(Date.now() - 3 * 3_600_000).toISOString(),
      },
    ]);
    renderPage();

    const card = (await screen.findByText("Habru front door")).closest(
      '[class*="p-4"]',
    ) as HTMLElement;

    expect(within(card).getByText("Quiet")).toBeInTheDocument();
    expect(within(card).queryByText("Online")).not.toBeInTheDocument();
    expect(
      within(card).getByText(/ETHR cannot call this terminal/),
    ).toBeInTheDocument();

    await userEvent.click(screen.getByRole("combobox", { name: /status/i }));
    expect(
      await screen.findByRole("option", { name: "Quiet" }),
    ).toBeInTheDocument();
  });
});

/**
 * Add Device opens the guided setup (device setup upgrade, PR 3). The full
 * Register form these tests exercise is its Advanced link.
 */
async function openRegisterForm(user: ReturnType<typeof userEvent.setup>) {
  await user.click(
    (await screen.findAllByRole("button", { name: /add device/i }))[0],
  );
  await user.click(await screen.findByRole("button", { name: /^advanced$/i }));
  return screen.findByRole("dialog", { name: /register device/i });
}

// QA 2026-11-14, D-3: Register with no branch answered "The branch public id
// field is required.", from a form that did not say a branch was needed.
describe("<DevicesPage> register dialog", () => {
  it("asks for a branch before Register when there is a choice to make", async () => {
    serve(
      [],
      [
        { public_id: "B1", name: "Head office" },
        { public_id: "B2", name: "Kombolcha" },
      ],
    );
    renderPage();

    const dialog = await openRegisterForm(userEvent.setup());

    expect(await within(dialog).findByText("Branch *")).toBeInTheDocument();
    expect(
      within(dialog).getByRole("combobox", { name: /branch/i }),
    ).toHaveAttribute("aria-required", "true");
    expect(
      within(dialog).getByRole("button", { name: /^register$/i }),
    ).toBeDisabled();
  });

  it("fills in the only branch, so Register is not held back", async () => {
    serve([], [{ public_id: "B1", name: "Head office" }]);
    renderPage();

    const dialog = await openRegisterForm(userEvent.setup());

    expect(
      (await within(dialog).findAllByText("Head office")).length,
    ).toBeGreaterThan(0);
    expect(
      within(dialog).getByRole("button", { name: /^register$/i }),
    ).toBeEnabled();
  });
  it("offers no Auto-Sync switch once the ZKTeco ADMS adapter is chosen", async () => {
    serve([], [{ public_id: "B1", name: "Head office" }]);
    renderPage();

    const dialog = await openRegisterForm(userEvent.setup());
    expect(
      within(dialog).getByRole("switch", { name: /auto-sync/i }),
    ).toBeInTheDocument();

    await userEvent.click(
      within(dialog).getByRole("combobox", { name: /adapter|device type/i }),
    );
    await userEvent.click(
      await screen.findByRole("option", { name: /ZKTeco \(ADMS/ }),
    );

    expect(
      within(dialog).queryByRole("switch", { name: /auto-sync/i }),
    ).not.toBeInTheDocument();
  });

  it("opens by itself when the address ends in #add, as the health dashboard's button sends it", async () => {
    serve([], [{ public_id: "B1", name: "Head office" }]);
    window.location.hash = "#add";
    try {
      renderPage();

      const dialog = await screen.findByRole("dialog");
      expect(
        within(dialog).getByRole("heading", { name: /add a device/i }),
      ).toBeInTheDocument();
    } finally {
      window.location.hash = "";
    }
  });
});

/*
 * The dialog after registering follows how the terminal connects: the server
 * says which (connection_mode), and the page shows that mode's setup instead
 * of always showing the Hikvision push address (device setup upgrade, PR 1).
 */
describe("<DevicesPage> after registering", () => {
  const REGISTERED = {
    ...base,
    public_id: "NEW1",
    name: "Front door",
    adapter_type: "hikvision",
    status: "pending",
    receives_only: false,
    pulled: true,
    connection_mode: "pull",
    uses_webhook_token: true,
    webhook_url:
      "https://www.ethr.et/api/v1/devices/webhook/hikvision?token=issued-once",
    webhook_ip_allowlist: [],
  };

  async function registerAs(created: object) {
    serve([], [{ public_id: "B1", name: "Head office" }]);
    server.use(
      http.post("*/api/v1/devices", () =>
        HttpResponse.json(created, { status: 201 }),
      ),
    );
    const user = userEvent.setup();
    renderPage();

    const form = await openRegisterForm(user);
    await user.type(within(form).getByLabelText(/device name/i), "Front door");
    await user.type(
      within(form).getByLabelText(/^ip address$/i),
      "203.0.113.10",
    );
    await user.click(within(form).getByRole("button", { name: /^register$/i }));
  }

  it("tells a pulled terminal ETHR will call it, and shows no address", async () => {
    await registerAs(REGISTERED);

    expect(
      await screen.findByText("ETHR will call the terminal"),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("issued-address")).not.toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /test connection/i }),
    ).toBeInTheDocument();
  });

  it("shows a push terminal the address it must be given", async () => {
    await registerAs({
      ...REGISTERED,
      receives_only: true,
      pulled: false,
      connection_mode: "push",
    });

    expect(await screen.findByTestId("issued-address")).toHaveTextContent(
      "token=issued-once",
    );
    expect(screen.getByText("Enter these on the terminal")).toBeInTheDocument();
  });

  it("shows an ADMS terminal the server and serial, and no token", async () => {
    await registerAs({
      ...REGISTERED,
      adapter_type: "zkteco_adms",
      serial_number: "CQZ7232460001",
      receives_only: false,
      pulled: false,
      connection_mode: "adms",
      uses_webhook_token: false,
      adms: { server: "ethr.et", last_rejected: null },
    });

    const box = await screen.findByTestId("adms-setup");
    expect(box).toHaveTextContent("CQZ7232460001");
    expect(box).toHaveTextContent("ethr.et");
    expect(screen.queryByTestId("issued-address")).not.toBeInTheDocument();
    expect(screen.queryByText(/token=/)).not.toBeInTheDocument();
  });
});

/*
 * The list's Edit used to be the Register form with fewer fields: no location,
 * commands, interval, time zone or allowlist. It is the device page's form now
 * (device setup upgrade, PR 2), so what the page can save, the list can too.
 */
describe("<DevicesPage> edit", () => {
  it("opens the full form and saves the allowlist", async () => {
    serve(
      [
        {
          ...base,
          public_id: "PULL1",
          name: "Main gate",
          adapter_type: "hikvision",
          receives_only: false,
          pulled: true,
          connection_mode: "pull",
          uses_webhook_token: true,
          accepts_commands: true,
          commands_enabled: false,
          timezone: null,
          webhook_ip_allowlist: [],
          branch_public_id: "B1",
          status: "online",
        },
      ],
      [{ public_id: "B1", name: "Head office" }],
    );
    let sent: Record<string, unknown> | null = null;
    server.use(
      http.put("*/api/v1/devices/PULL1", async ({ request }) => {
        sent = (await request.json()) as Record<string, unknown>;
        return HttpResponse.json({ ...base, public_id: "PULL1" });
      }),
    );
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Main gate");
    await user.click(screen.getByRole("button", { name: /^actions$/i }));
    await user.click(await screen.findByRole("menuitem", { name: /^edit$/i }));
    const dialog = await screen.findByRole("dialog");

    for (const label of [
      /^location/i,
      /device clock time zone/i,
      /webhook allowed ip addresses/i,
    ]) {
      expect(within(dialog).getByLabelText(label)).toBeInTheDocument();
    }
    expect(
      within(dialog).getByRole("switch", {
        name: /send commands to this device/i,
      }),
    ).toBeInTheDocument();

    await user.type(
      within(dialog).getByLabelText(/webhook allowed ip addresses/i),
      "203.0.113.7",
    );
    await user.click(
      within(dialog).getByRole("button", { name: /save changes/i }),
    );

    await vi.waitFor(() => expect(sent).not.toBeNull());
    expect(sent).toMatchObject({
      webhook_ip_allowlist: ["203.0.113.7"],
      name: "Main gate",
      branch_public_id: "B1",
    });
    expect(sent).not.toHaveProperty("connection_config");
  });
});
