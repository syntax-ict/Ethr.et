import { describe, it, expect, vi, beforeEach } from "vitest";
import {
  render,
  screen,
  within,
  waitFor,
  fireEvent,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeviceActionsMenu } from "@/features/devices/components/device-actions-menu";
import type { Device } from "@/features/devices/api";

/**
 * One menu of device actions, shared by the list and the dashboard. What it
 * offers follows the device (a terminal that only sends is never called) and
 * the viewer's permissions.
 */
const granted = vi.hoisted(() => ({ abilities: null as string[] | null }));
vi.mock("@/lib/hooks/usePermissions", () => ({
  usePermissions: () => ({
    hasPermission: (ability: string) =>
      granted.abilities === null || granted.abilities.includes(ability),
  }),
}));

const toast = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
  info: vi.fn(),
}));
vi.mock("sonner", () => ({ toast }));

const ISSUED =
  "https://www.ethr.et/api/v1/devices/webhook/hikvision?token=issued-once";

const PULLED = {
  public_id: "DEV1",
  name: "Main gate",
  adapter_type: "hikvision",
  status: "online",
  receives_only: false,
  accepts_commands: false,
  commands_enabled: false,
  has_webhook_token: false,
} as unknown as Device;

const SENDS = {
  ...PULLED,
  receives_only: true,
  has_webhook_token: true,
} as unknown as Device;

const handlers = {
  onEdit: vi.fn(),
  onDelete: vi.fn(),
  onDiscover: vi.fn(),
  onImportHistory: vi.fn(),
};

function renderMenu(
  device: Device,
  props: Partial<Parameters<typeof DeviceActionsMenu>[0]> = {},
) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <DeviceActionsMenu device={device} {...handlers} {...props} />
    </QueryClientProvider>,
  );
}

async function openMenu(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole("button", { name: "Actions" }));
}

const item = (name: RegExp) => screen.queryByRole("menuitem", { name });

beforeEach(() => {
  granted.abilities = null;
  vi.clearAllMocks();
});

describe("<DeviceActionsMenu>", () => {
  it("offers everything for a device ETHR calls, to a full administrator", async () => {
    const user = userEvent.setup();
    renderMenu(PULLED);
    await openMenu(user);

    for (const name of [
      /view details/i,
      /test connection/i,
      /pull records/i,
      /discover enrolled users/i,
      /import attendance history/i,
      /^edit$/i,
      /^delete$/i,
    ]) {
      expect(item(name)).toBeInTheDocument();
    }
    expect(item(/copy webhook url/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/ETHR cannot call it/i)).not.toBeInTheDocument();
  });

  it("offers an ADMS terminal its users but no pull and no history, since ETHR never pulls from it", async () => {
    const user = userEvent.setup();
    renderMenu({
      ...PULLED,
      adapter_type: "zkteco_adms",
      receives_only: false,
      pulled: false,
    } as unknown as Device);
    await openMenu(user);

    expect(item(/discover enrolled users/i)).toBeInTheDocument();
    expect(item(/pull records/i)).not.toBeInTheDocument();
    expect(item(/import attendance history/i)).not.toBeInTheDocument();
  });

  it("offers a terminal that only sends no test, pull, users or history, and says why", async () => {
    const user = userEvent.setup();
    renderMenu(SENDS);
    await openMenu(user);

    for (const name of [
      /test connection/i,
      /pull records/i,
      /discover enrolled users/i,
      /import attendance history/i,
    ]) {
      expect(item(name)).not.toBeInTheDocument();
    }
    for (const name of [/view details/i, /^edit$/i, /^delete$/i]) {
      expect(item(name)).toBeInTheDocument();
    }
    expect(screen.getByText(/ETHR cannot call it/i)).toBeInTheDocument();
  });

  it("links View details to the device page, and drops it on that page", async () => {
    const user = userEvent.setup();
    const first = renderMenu(PULLED);
    await openMenu(user);
    expect(item(/view details/i)).toHaveAttribute("href", "/devices/DEV1");
    first.unmount();

    renderMenu(PULLED, { hideDetailsLink: true });
    await openMenu(user);
    expect(item(/view details/i)).not.toBeInTheDocument();
  });

  it("offers no copy of the address, which the page no longer has", async () => {
    const user = userEvent.setup();
    renderMenu(SENDS);
    await openMenu(user);
    expect(item(/copy webhook url/i)).not.toBeInTheDocument();
    expect(item(/regenerate token/i)).toBeInTheDocument();
  });

  it("asks before regenerating the token, and calls the API once on confirm", async () => {
    let calls = 0;
    server.use(
      http.post("*/api/v1/devices/DEV1/regenerate-token", () => {
        calls += 1;
        return HttpResponse.json({
          webhook_token: "new",
          webhook_url: ISSUED,
          reason: "lost",
        });
      }),
    );
    const user = userEvent.setup();
    renderMenu(SENDS);
    await openMenu(user);
    await user.click(item(/regenerate token/i)!);

    const dialog = await screen.findByRole("dialog");
    expect(
      within(dialog).getByText(/must be given the new address/i),
    ).toBeInTheDocument();
    expect(calls).toBe(0);

    await user.click(
      within(dialog).getByRole("button", { name: /regenerate token/i }),
    );
    await waitFor(() => expect(calls).toBe(1));
    await waitFor(() => expect(toast.success).toHaveBeenCalled());
  });

  it("asks why, defaults to lost, sends the reason, and shows the new address once", async () => {
    const bodies: unknown[] = [];
    server.use(
      http.post(
        "*/api/v1/devices/DEV1/regenerate-token",
        async ({ request }) => {
          bodies.push(await request.json());
          return HttpResponse.json({
            webhook_token: "issued-once",
            webhook_url: ISSUED,
            reason: bodies.length === 1 ? "lost" : "moving",
          });
        },
      ),
    );
    const user = userEvent.setup();
    renderMenu(SENDS);
    await openMenu(user);
    await user.click(item(/regenerate token/i)!);

    const dialog = await screen.findByRole("dialog");
    const lost = within(dialog).getByRole("radio", { name: /lost or stolen/i });
    const moving = within(dialog).getByRole("radio", {
      name: /moving or reconfiguring/i,
    });
    expect(lost).toBeChecked();
    expect(moving).not.toBeChecked();

    await user.click(moving);
    await user.click(
      within(dialog).getByRole("button", { name: /regenerate token/i }),
    );

    await waitFor(() => expect(bodies).toEqual([{ reason: "moving" }]));
    const shown = await screen.findByTestId("issued-address");
    expect(shown).toHaveTextContent(ISSUED);
    // The four values a person types into the terminal, token included.
    expect(screen.getByText("Enter these on the terminal")).toBeInTheDocument();
    expect(
      screen.getByText("/api/v1/devices/webhook/hikvision?token=issued-once"),
    ).toBeInTheDocument();

    // Escape and a click outside do not close it; only Done does.
    await user.keyboard("{Escape}");
    expect(screen.getByTestId("issued-address")).toBeInTheDocument();
    fireEvent.pointerDown(document.body);
    fireEvent.pointerUp(document.body);
    expect(screen.getByTestId("issued-address")).toBeInTheDocument();
    expect(
      screen.getByText(/shown only now and cannot be shown again/i),
    ).toBeInTheDocument();
    expect(screen.getByText(/keeps working for 24 hours/i)).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /^done$/i }));
    await waitFor(() =>
      expect(screen.queryByTestId("issued-address")).not.toBeInTheDocument(),
    );
  });

  it("does not regenerate when the confirmation is cancelled", async () => {
    let calls = 0;
    server.use(
      http.post("*/api/v1/devices/DEV1/regenerate-token", () => {
        calls += 1;
        return HttpResponse.json({});
      }),
    );
    const user = userEvent.setup();
    renderMenu(SENDS);
    await openMenu(user);
    await user.click(item(/regenerate token/i)!);
    const dialog = await screen.findByRole("dialog");
    await user.click(within(dialog).getByRole("button", { name: /cancel/i }));
    expect(calls).toBe(0);
  });

  it("offers Set time and Send staff only to a device that takes commands, to someone who may update", async () => {
    const user = userEvent.setup();
    const off = renderMenu(PULLED);
    await openMenu(user);
    expect(item(/set clock now/i)).not.toBeInTheDocument();
    expect(item(/send all staff/i)).not.toBeInTheDocument();
    off.unmount();

    const commands = {
      ...PULLED,
      accepts_commands: true,
      commands_enabled: true,
    } as unknown as Device;
    const on = renderMenu(commands);
    await openMenu(user);
    expect(item(/set clock now/i)).toBeInTheDocument();
    expect(item(/send all staff/i)).toBeInTheDocument();
    on.unmount();

    granted.abilities = ["device.viewAny", "device.view"];
    renderMenu(commands);
    await openMenu(user);
    expect(item(/set clock now/i)).not.toBeInTheDocument();
    expect(item(/send all staff/i)).not.toBeInTheDocument();
  });

  it("leaves out update and delete items the viewer may not use", async () => {
    granted.abilities = ["device.viewAny", "device.view"];
    const user = userEvent.setup();
    renderMenu({ ...PULLED, has_webhook_token: true } as unknown as Device);
    await openMenu(user);

    for (const name of [
      /pull records/i,
      /import attendance history/i,
      /regenerate token/i,
      /^edit$/i,
      /^delete$/i,
    ]) {
      expect(item(name)).not.toBeInTheDocument();
    }
    expect(item(/test connection/i)).toBeInTheDocument();
  });

  it("renders nothing when there is nothing to offer", () => {
    granted.abilities = [];
    // A terminal that only sends, with no address, no callbacks and no
    // permissions, is offered only the link to its page; on that page, nothing.
    const sends = { ...SENDS, has_webhook_token: false } as unknown as Device;
    const { container } = renderMenu(sends, {
      hideDetailsLink: true,
      onEdit: undefined,
      onDelete: undefined,
      onDiscover: undefined,
      onImportHistory: undefined,
    });
    expect(container).toBeEmptyDOMElement();
  });

  it("uses the page's own test handler instead of firing a second request", async () => {
    const onTest = vi.fn();
    const user = userEvent.setup();
    renderMenu(PULLED, { onTest, testing: false });
    await openMenu(user);
    await user.click(item(/test connection/i)!);
    expect(onTest).toHaveBeenCalledTimes(1);
  });
});
