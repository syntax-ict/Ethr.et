import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeviceSetupWizard } from "@/features/devices/components/device-setup-wizard";
import { firstContactFor } from "@/features/devices/first-contact";
import {
  connectionModeFor,
  wizardChoice,
  type WizardBrand,
  type WizardReach,
} from "@/features/devices/payload";
import type { Device } from "@/features/devices/api";

/**
 * The guided setup (device setup upgrade, PR 3): two plain questions choose the
 * adapter and the mode, the wizard shows what to type on the terminal, and then
 * it polls the device every five seconds for the first contact.
 */
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    isAtLeast: () => true,
    hasRole: () => true,
    hasPermission: () => true,
    can: { viewDevices: true },
    role: "tenant_admin",
  }),
}));

vi.mock("@/features/auth/api", async (importOriginal) => ({
  ...(await importOriginal<object>()),
  useCurrentTenant: () => ({ data: { timezone: "Africa/Addis_Ababa" } }),
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const TODAY = {
  day: "2026-11-25",
  received: 0,
  recorded: 0,
  held: 0,
  duplicate: 0,
  quarantined: 0,
  waiting: 0,
};

const BASE = {
  location_description: null,
  auto_sync: true,
  sync_interval_minutes: 5,
  last_sync_at: null,
  branch: null,
  branch_public_id: "B1",
  attendance_records_count: 0,
  sync_logs_count: 0,
  created_at: "2026-11-25T06:00:00Z",
  updated_at: "2026-11-25T06:00:00Z",
  status: "pending",
  webhook_ip_allowlist: [],
  accepts_commands: true,
  commands_enabled: true,
  today: TODAY,
};

const PUSH_URL =
  "https://www.ethr.et/api/v1/devices/webhook/hikvision?token=issued-once";

const ADMS = {
  ...BASE,
  public_id: "DEV1",
  name: "Gate",
  adapter_type: "zkteco_adms",
  serial_number: "CQZ7232460001",
  receives_only: false,
  pulled: false,
  connection_mode: "adms",
  uses_webhook_token: false,
  adms: { server: "ethr.et", last_rejected: null },
};

const PUSH = {
  ...BASE,
  public_id: "DEV1",
  name: "Gate",
  adapter_type: "hikvision",
  serial_number: null,
  receives_only: true,
  pulled: false,
  connection_mode: "push",
  uses_webhook_token: true,
  has_webhook_token: true,
  webhook_url: PUSH_URL,
};

const PULL = {
  ...BASE,
  public_id: "DEV1",
  name: "Gate",
  adapter_type: "hikvision",
  serial_number: null,
  receives_only: false,
  pulled: true,
  connection_mode: "pull",
  uses_webhook_token: true,
  has_webhook_token: true,
  webhook_url: PUSH_URL,
};

const BRANCHES = [{ public_id: "B1", name: "Head office" }];

/** What `GET /devices/DEV1` answers now, and how many times it was asked. */
let current: Record<string, unknown>;
let polls: number;
let created: unknown[];

function serve(registered: Record<string, unknown>) {
  current = registered;
  polls = 0;
  created = [];
  server.use(
    http.post("*/api/v1/devices", async ({ request }) => {
      created.push(await request.json());
      return HttpResponse.json(registered, { status: 201 });
    }),
    http.get("*/api/v1/devices/DEV1", () => {
      polls++;
      return HttpResponse.json(current);
    }),
    http.get("*/api/v1/devices/DEV1/status", () =>
      HttpResponse.json({
        device_public_id: "DEV1",
        status: "online",
        error_code: null,
      }),
    ),
    http.post("*/api/v1/devices/DEV1/allowlist", () =>
      HttpResponse.json(current),
    ),
  );
}

function renderWizard(
  handlers: Partial<{
    onAdvanced: () => void;
    onDiscover: (device: Device) => void;
    onOpenChange: (open: boolean) => void;
  }> = {},
) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const props = {
    onAdvanced: vi.fn(),
    onDiscover: vi.fn(),
    onOpenChange: vi.fn(),
    ...handlers,
  };
  const view = render(
    <QueryClientProvider client={client}>
      <DeviceSetupWizard open branches={BRANCHES} {...props} />
    </QueryClientProvider>,
  );
  return { ...props, ...view };
}

type User = ReturnType<typeof userEvent.setup>;

async function answer(user: User, brand: string, reach: RegExp): Promise<void> {
  await user.click(await screen.findByRole("radio", { name: brand }));
  await user.click(screen.getByRole("radio", { name: reach }));
  await user.click(screen.getByRole("button", { name: /^next$/i }));
}

const OFFICE = /only sends from the office network/i;
const PUBLIC = /reach it at a public address/i;

async function register(user: User, extra?: () => Promise<void>) {
  await user.type(await screen.findByLabelText(/device name/i), "Gate");
  await extra?.();
  await user.click(
    screen.getByRole("button", { name: /register and continue/i }),
  );
}

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
});

afterEach(() => {
  vi.useRealTimers();
});

function fresh(): User {
  return userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
}

describe("wizardChoice", () => {
  const cases: [WizardBrand, WizardReach, string, boolean, string | null][] = [
    ["hikvision", "public", "hikvision", false, "pull"],
    ["hikvision", "office", "hikvision", true, "push"],
    ["zkteco", "public", "zkteco", false, "pull"],
    ["zkteco", "office", "zkteco_adms", false, "adms"],
    ["suprema", "public", "suprema", false, "pull"],
    ["suprema", "office", "", false, null],
  ];

  it.each(cases)(
    "%s, %s chooses the adapter and mode the form would hold",
    (brand, reach, adapter, sends, mode) => {
      const choice = wizardChoice(brand, reach);
      if (mode === null) {
        expect(choice).toBeNull();
        return;
      }
      expect(choice).toEqual({ adapter_type: adapter, sends_to_ethr: sends });
      expect(connectionModeFor(choice!)).toBe(mode);
    },
  );
});

describe("firstContactFor", () => {
  const device = (extra: object) =>
    ({ ...ADMS, ...extra }) as unknown as Device;

  it("reads an ADMS terminal's first call, and a refusal newer than it", () => {
    expect(firstContactFor(device({}), "adms")).toEqual({ kind: "waiting" });
    expect(
      firstContactFor(
        device({
          adms: {
            server: "x",
            last_rejected: { ip: "196.188.1.1", at: "2026-11-25T08:00:00Z" },
          },
        }),
        "adms",
      ),
    ).toEqual({
      kind: "refused",
      ip: "196.188.1.1",
      at: "2026-11-25T08:00:00Z",
    });
    expect(
      firstContactFor(
        device({
          last_sync_at: "2026-11-25T08:05:00Z",
          adms: {
            server: "x",
            last_rejected: { ip: "196.188.1.1", at: "2026-11-25T08:00:00Z" },
          },
        }),
        "adms",
      ).kind,
    ).toBe("heard");
  });

  it("reads a push terminal's punch, matched, held or still being worked", () => {
    const push = (today: object) =>
      firstContactFor(device({ today: { ...TODAY, ...today } }), "push");
    expect(push({})).toEqual({ kind: "waiting" });
    expect(push({ received: 1, recorded: 1 })).toEqual({
      kind: "punch",
      matched: true,
      held: false,
    });
    expect(push({ received: 1, held: 1 })).toEqual({
      kind: "punch",
      matched: false,
      held: true,
    });
    expect(push({ waiting: 1 })).toEqual({
      kind: "punch",
      matched: false,
      held: false,
    });
  });
});

describe("<DeviceSetupWizard>", () => {
  it("opens the full form from Advanced, with nothing registered", async () => {
    serve(ADMS);
    const user = fresh();
    const { onAdvanced } = renderWizard();

    await user.click(
      await screen.findByRole("button", { name: /^advanced$/i }),
    );

    expect(onAdvanced).toHaveBeenCalledTimes(1);
    expect(created).toHaveLength(0);
  });

  it("explains that a Suprema terminal that only sends has no path here", async () => {
    serve(ADMS);
    const user = fresh();
    renderWizard();

    await user.click(await screen.findByRole("radio", { name: "Suprema" }));
    await user.click(screen.getByRole("radio", { name: OFFICE }));

    expect(screen.getByRole("status")).toHaveTextContent(/Suprema/);
    expect(screen.getByRole("button", { name: /^next$/i })).toBeDisabled();
  });

  describe("ZKTeco on the office network (ADMS)", () => {
    it("registers by serial, shows the values with copy buttons, and Allow on a refused call", async () => {
      serve(ADMS);
      const user = fresh();
      renderWizard();

      await answer(user, "ZKTeco", OFFICE);
      // The adapter is chosen: Details does not offer to undo it.
      expect(screen.queryByLabelText(/adapter|device type/i)).toBeNull();
      await register(user, async () => {
        await user.type(
          screen.getByLabelText(/serial number/i),
          "CQZ7232460001",
        );
      });

      expect(created[0]).toMatchObject({
        adapter_type: "zkteco_adms",
        serial_number: "CQZ7232460001",
        // The only branch is filled in, and sent: Register must not be refused for it.
        branch_public_id: "B1",
        connection_config: { mode: "adms" },
      });

      const box = await screen.findByTestId("adms-setup");
      expect(box).toHaveTextContent("ethr.et");
      expect(box).toHaveTextContent("CQZ7232460001");
      expect(
        screen.getByText(/open COMM, then Cloud Server Setting/i),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: "Copy Server address" }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: /^copy serial number/i }),
      ).toBeInTheDocument();

      // The device is saved once: no way back to Details to register again.
      expect(screen.queryByRole("button", { name: /^back$/i })).toBeNull();

      await user.click(
        screen.getByRole("button", { name: /check the connection/i }),
      );
      expect(
        await screen.findByText(/waiting for the terminal's first call/i),
      ).toBeInTheDocument();
      expect(screen.getByRole("button", { name: /^next$/i })).toBeDisabled();

      current = {
        ...ADMS,
        adms: {
          server: "ethr.et",
          last_rejected: { ip: "196.188.40.7", at: new Date().toISOString() },
        },
      };
      await vi.advanceTimersByTimeAsync(5000);

      expect(
        await screen.findByText(/called from 196\.188\.40\.7/i),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: /allow 196\.188\.40\.7/i }),
      ).toBeInTheDocument();

      current = { ...ADMS, last_sync_at: new Date().toISOString() };
      await vi.advanceTimersByTimeAsync(5000);

      expect(await screen.findByText(/^Connected\./)).toBeInTheDocument();
      await waitFor(() =>
        expect(screen.getByRole("button", { name: /^next$/i })).toBeEnabled(),
      );
      await user.click(screen.getByRole("button", { name: /^next$/i }));

      // ADMS keeps a roster the terminal reports, and takes commands.
      expect(
        await screen.findByRole("button", {
          name: "Discover enrolled users",
        }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: "Send all staff" }),
      ).toBeInTheDocument();
    });

    it("requires the serial number before registering", async () => {
      serve(ADMS);
      const user = fresh();
      renderWizard();

      await answer(user, "ZKTeco", OFFICE);
      await register(user);

      expect(created).toHaveLength(0);
    });
  });

  describe("Hikvision on the office network (push)", () => {
    it("asks for no address, shows the one-time address, and cannot be closed by accident", async () => {
      serve(PUSH);
      const user = fresh();
      const { onOpenChange } = renderWizard();

      await answer(user, "Hikvision", OFFICE);
      expect(screen.queryByLabelText(/^ip address$/i)).toBeNull();
      await register(user);

      expect(created[0]).toMatchObject({
        adapter_type: "hikvision",
        connection_config: { mode: "push" },
      });
      expect(await screen.findByTestId("issued-address")).toHaveTextContent(
        "token=issued-once",
      );
      expect(
        screen.getByText(/open Network, then HTTP listening/i),
      ).toBeVisible();
      expect(
        screen.getByRole("button", { name: "Copy Server address" }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: "Copy Path (URL)" }),
      ).toBeInTheDocument();

      await user.keyboard("{Escape}");
      expect(onOpenChange).not.toHaveBeenCalled();

      // Finish later asks first: the address cannot be shown again.
      await user.click(screen.getByRole("button", { name: /finish later/i }));
      expect(
        await screen.findByRole("alertdialog", {
          name: /leave without finishing/i,
        }),
      ).toHaveTextContent(/cannot be shown again/i);
      expect(onOpenChange).not.toHaveBeenCalled();
      await user.click(screen.getByRole("button", { name: /^stay$/i }));
      await user.click(screen.getByRole("button", { name: /finish later/i }));
      await user.click(screen.getByRole("button", { name: /^leave$/i }));
      expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it("asks for a punch, shows it arrived and matched a person, and offers no roster", async () => {
      serve(PUSH);
      const user = fresh();
      renderWizard();

      await answer(user, "Hikvision", OFFICE);
      await register(user);
      await user.click(
        await screen.findByRole("button", { name: /check the connection/i }),
      );
      expect(
        await screen.findByText(/ask someone to punch once/i),
      ).toBeInTheDocument();

      current = {
        ...PUSH,
        last_sync_at: new Date().toISOString(),
        today: { ...TODAY, received: 1, held: 1 },
      };
      await vi.advanceTimersByTimeAsync(5000);
      expect(
        await screen.findByText(/matches no person yet/i),
      ).toBeInTheDocument();

      current = {
        ...PUSH,
        last_sync_at: new Date().toISOString(),
        today: { ...TODAY, received: 1, recorded: 1 },
      };
      await vi.advanceTimersByTimeAsync(5000);
      expect(
        await screen.findByText(/recorded for a person/i),
      ).toBeInTheDocument();

      await user.click(await screen.findByRole("button", { name: /^next$/i }));

      // A terminal that only sends cannot be asked who is enrolled.
      expect(
        await screen.findByRole("button", { name: "Send all staff" }),
      ).toBeInTheDocument();
      expect(
        screen.queryByRole("button", { name: "Discover enrolled users" }),
      ).toBeNull();
    });

    it("says nothing more can be asked of it when it takes no commands either", async () => {
      serve({ ...PUSH, accepts_commands: false, commands_enabled: false });
      const user = fresh();
      renderWizard();

      await answer(user, "Hikvision", OFFICE);
      await register(user);
      await user.click(
        await screen.findByRole("button", { name: /check the connection/i }),
      );
      current = {
        ...PUSH,
        accepts_commands: false,
        commands_enabled: false,
        today: { ...TODAY, received: 1, recorded: 1 },
      };
      await vi.advanceTimersByTimeAsync(5000);
      await user.click(await screen.findByRole("button", { name: /^next$/i }));

      expect(
        await screen.findByText(/cannot be asked who is enrolled/i),
      ).toBeInTheDocument();
      expect(
        screen.queryByRole("button", { name: "Send all staff" }),
      ).toBeNull();
    });
  });

  describe("Hikvision at a public address (pull)", () => {
    it("asks for the address, tells how to open the port, and tests the connection", async () => {
      serve(PULL);
      const user = fresh();
      renderWizard();

      await answer(user, "Hikvision", PUBLIC);
      await register(user, async () => {
        await user.type(screen.getByLabelText(/^ip address$/i), "203.0.113.10");
      });

      expect(created[0]).toMatchObject({
        adapter_type: "hikvision",
        connection_config: { ip: "203.0.113.10", port: 80 },
      });
      expect(
        await screen.findByText(
          /calls the terminal at 203\.0\.113\.10, port 80/i,
        ),
      ).toBeInTheDocument();
      expect(screen.getByText(/forward port 80/i)).toBeInTheDocument();
      expect(screen.queryByTestId("issued-address")).toBeNull();

      await user.click(
        screen.getByRole("button", { name: /check the connection/i }),
      );
      const next = await screen.findByRole("button", { name: /^next$/i });
      expect(next).toBeDisabled();

      await user.click(
        screen.getByRole("button", { name: /test connection/i }),
      );
      expect(
        await screen.findByText("Device is online and responding"),
      ).toBeInTheDocument();
      await waitFor(() => expect(next).toBeEnabled());
      await user.click(next);

      expect(
        await screen.findByRole("button", { name: "Discover enrolled users" }),
      ).toBeInTheDocument();
    });

    it("does not poll a terminal ETHR can only learn about by calling it", async () => {
      serve(PULL);
      const user = fresh();
      renderWizard();

      await answer(user, "Hikvision", PUBLIC);
      await register(user, async () => {
        await user.type(screen.getByLabelText(/^ip address$/i), "203.0.113.10");
      });
      await user.click(
        await screen.findByRole("button", { name: /check the connection/i }),
      );
      await screen.findByRole("button", { name: /test connection/i });
      const asked = polls;

      await vi.advanceTimersByTimeAsync(20_000);
      expect(polls).toBe(asked);
    });
  });

  describe("polling", () => {
    async function toWaiting(user: User) {
      await answer(user, "ZKTeco", OFFICE);
      await register(user, async () => {
        await user.type(
          screen.getByLabelText(/serial number/i),
          "CQZ7232460001",
        );
      });
      await user.click(
        await screen.findByRole("button", { name: /check the connection/i }),
      );
      await screen.findByText(/waiting for the terminal's first call/i);
    }

    it("asks every 5 seconds while the step is open, and stops when it is left", async () => {
      serve(ADMS);
      const user = fresh();
      renderWizard();
      await toWaiting(user);

      const first = polls;
      await vi.advanceTimersByTimeAsync(5000);
      expect(polls).toBe(first + 1);
      await vi.advanceTimersByTimeAsync(5000);
      expect(polls).toBe(first + 2);

      await user.click(
        screen.getByRole("button", { name: /back to the terminal steps/i }),
      );
      const left = polls;
      await vi.advanceTimersByTimeAsync(30_000);
      expect(polls).toBe(left);
    });

    it("stops when the wizard is closed", async () => {
      serve(ADMS);
      const user = fresh();
      const view = renderWizard();
      await toWaiting(user);
      await vi.advanceTimersByTimeAsync(5000);

      view.unmount();
      const closed = polls;
      await vi.advanceTimersByTimeAsync(30_000);
      expect(polls).toBe(closed);
    });
  });

  it("keeps the step values readable on the terminal step", async () => {
    serve(ADMS);
    const user = fresh();
    renderWizard();

    await answer(user, "ZKTeco", OFFICE);
    await register(user, async () => {
      await user.type(screen.getByLabelText(/serial number/i), "CQZ7232460001");
    });

    const box = await screen.findByTestId("adms-setup");
    expect(within(box).getByText("Server address")).toBeInTheDocument();
  });
});
