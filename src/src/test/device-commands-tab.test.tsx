import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { http, HttpResponse } from "msw";
import { server } from "./msw/server";
import { DeviceCommandsTab } from "@/features/devices/components/device-commands-tab";
import type { Device } from "@/features/devices/api";

const toastSuccess = vi.hoisted(() => vi.fn());
vi.mock("sonner", () => ({
  toast: { success: toastSuccess, error: vi.fn(), info: vi.fn() },
}));

/**
 * The Commands tab (gap 3, 2026-10-14): what ETHR has asked a device to do
 * and what came of it. Its actions appear only when the device takes commands
 * and the viewer may change devices.
 */
const DEVICE = {
  public_id: "DEV1",
  name: "Main gate",
  adapter_type: "hikvision",
  status: "online",
  accepts_commands: true,
  commands_enabled: true,
} as Device;

function renderTab(device: Device, canUpdate = true) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const Wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
  return render(<DeviceCommandsTab device={device} canUpdate={canUpdate} />, {
    wrapper: Wrapper,
  });
}

function commandsResponse() {
  return HttpResponse.json({
    data: [
      {
        public_id: "C2",
        type: "delete_user",
        status: "failed",
        device_user_id: "1042",
        employee: { public_id: "E1", name: "Abebe Kebede" },
        attempts: 3,
        last_error: "HTTP 400: badParameters",
        created_at: "2026-10-14T08:00:00Z",
        sent_at: null,
        completed_at: "2026-10-14T08:10:00Z",
      },
      {
        public_id: "C1",
        type: "set_time",
        status: "done",
        device_user_id: null,
        employee: null,
        attempts: 1,
        last_error: null,
        created_at: "2026-10-14T07:00:00Z",
        sent_at: "2026-10-14T07:00:01Z",
        completed_at: "2026-10-14T07:00:01Z",
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 15, total: 2 },
    links: {},
  });
}

describe("<DeviceCommandsTab>", () => {
  it("lists commands with the person, the outcome and why one failed", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1/commands", () => commandsResponse()),
    );
    renderTab(DEVICE);

    expect(await screen.findByText("Remove a person")).toBeInTheDocument();
    expect(screen.getByText("Abebe Kebede")).toBeInTheDocument();
    expect(screen.getByText("#1042")).toBeInTheDocument();
    expect(screen.getByText("Failed")).toBeInTheDocument();
    expect(screen.getByText("HTTP 400: badParameters")).toBeInTheDocument();
    expect(screen.getByText("Set the clock")).toBeInTheDocument();
    expect(screen.getByText("Done")).toBeInTheDocument();
  });

  it("sends the branch's staff and says how many were skipped", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1/commands", () => commandsResponse()),
      http.post("*/api/v1/devices/DEV1/commands/send-staff", () =>
        HttpResponse.json({ queued: 12, skipped: 3 }, { status: 202 }),
      ),
    );
    const user = userEvent.setup();
    renderTab(DEVICE);

    await user.click(
      await screen.findByRole("button", { name: /send all staff/i }),
    );

    await vi.waitFor(() =>
      expect(toastSuccess).toHaveBeenCalledWith(
        expect.stringMatching(/^12 people queued.*3 skipped/),
      ),
    );
  });

  it("offers no actions while commands are off, and says how to turn them on", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1/commands", () =>
        HttpResponse.json({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
          links: {},
        }),
      ),
    );
    renderTab({ ...DEVICE, commands_enabled: false });

    expect(await screen.findByText(/Commands are off/)).toBeInTheDocument();
    expect(
      await screen.findByText("Nothing has been sent to this device yet."),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /set clock now/i }),
    ).not.toBeInTheDocument();
  });

  it("hides the actions from someone who may not change devices", async () => {
    server.use(
      http.get("*/api/v1/devices/DEV1/commands", () => commandsResponse()),
    );
    renderTab(DEVICE, false);

    expect(await screen.findByText("Remove a person")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: /send all staff/i }),
    ).not.toBeInTheDocument();
  });
});
