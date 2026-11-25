import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { DeviceForm } from "@/features/devices/components/device-form";
import type { Device } from "@/features/devices/api";
import type { DevicePayload } from "@/features/devices/payload";

/**
 * Register and Edit are one form (device setup upgrade, PR 2). What a mode
 * shows follows how the terminal reaches ETHR, not which dialog opened it, so
 * the same labels have to appear whether the device is new or stored.
 */
beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("locale", "en");
});

const BRANCHES = [{ public_id: "B1", name: "Head office" }];

function stored(overrides: Partial<Device>): Device {
  return {
    public_id: "DEV1",
    name: "Front door",
    location_description: "Ground floor",
    serial_number: null,
    adapter_type: "hikvision",
    status: "online",
    auto_sync: true,
    commands_enabled: false,
    accepts_commands: true,
    receives_only: false,
    pulled: true,
    connection_mode: "pull",
    uses_webhook_token: true,
    sync_interval_minutes: 10,
    timezone: null,
    webhook_ip_allowlist: ["203.0.113.7"],
    branch_public_id: "B1",
    ...overrides,
  } as Device;
}

function formElement(
  mode: "create" | "edit",
  device?: Device,
  onSubmit: (payload: Partial<DevicePayload>) => void = () => {},
  submitLabel = "Go",
) {
  const common = {
    branches: BRANCHES,
    isPending: false,
    submitLabel,
    onCancel: () => {},
  };
  return mode === "edit" && device ? (
    <DeviceForm {...common} mode="edit" device={device} onSubmit={onSubmit} />
  ) : (
    <DeviceForm {...common} mode="create" onSubmit={onSubmit} />
  );
}

function renderForm(
  mode: "create" | "edit",
  device?: Device,
  onSubmit = vi.fn(),
) {
  render(
    formElement(
      mode,
      device,
      onSubmit,
      mode === "create" ? "Register" : "Save changes",
    ),
  );
  return onSubmit;
}

async function chooseAdapter(name: RegExp) {
  await userEvent.click(
    screen.getByRole("combobox", { name: /adapter type/i }),
  );
  await userEvent.click(await screen.findByRole("option", { name }));
}

const SECTIONS = ["Identity", "Connection", "Advanced"];

describe("<DeviceForm> sections", () => {
  it("shows the same sections and fields to a terminal ETHR calls, new or stored", () => {
    for (const mode of ["create", "edit"] as const) {
      const { unmount } = render(formElement(mode, stored({})));

      for (const name of SECTIONS) {
        expect(screen.getByText(name)).toBeInTheDocument();
      }
      for (const label of [
        "Device Name",
        "Location Description",
        "Serial Number",
        "IP Address",
        "Port",
        "Webhook allowed IP addresses",
        "Device clock time zone",
        "Sync Interval",
      ]) {
        expect(screen.getByLabelText(label)).toBeInTheDocument();
      }
      expect(
        screen.getByRole("switch", { name: /auto-sync/i }),
      ).toBeInTheDocument();
      unmount();
    }
  });

  it("shows an ADMS terminal no address and no Auto-Sync, and an optional allowlist, new or stored", async () => {
    for (const mode of ["create", "edit"] as const) {
      const { unmount } = render(
        formElement(
          mode,
          stored({
            adapter_type: "zkteco_adms",
            connection_mode: "adms",
            uses_webhook_token: false,
            pulled: false,
            webhook_ip_allowlist: [],
          }),
        ),
      );
      if (mode === "create") await chooseAdapter(/ZKTeco \(ADMS/);

      expect(screen.queryByLabelText("IP Address")).toBeNull();
      expect(
        screen.queryByRole("switch", { name: /auto-sync/i }),
      ).not.toBeInTheDocument();
      expect(screen.queryByLabelText(/sync interval/i)).toBeNull();
      expect(
        screen.getByLabelText("Allowed IP addresses (optional)"),
      ).toBeInTheDocument();
      expect(
        screen.getByText(/can be left empty for now/i),
      ).toBeInTheDocument();
      // Not a promise of an Allow button on the first call: the device page
      // offers it once a call has been refused.
      expect(screen.getByText(/first call is refused/i)).toBeInTheDocument();
      unmount();
    }
  });

  it("shows a terminal that sends no address and no pull schedule, new or stored", async () => {
    for (const mode of ["create", "edit"] as const) {
      const { unmount } = render(
        formElement(
          mode,
          stored({
            receives_only: true,
            pulled: false,
            connection_mode: "push",
          }),
        ),
      );
      const toggle = screen.getByRole("switch", {
        name: "The terminal is on our office network",
      });
      if (mode === "create") await userEvent.click(toggle);

      expect(toggle).toBeChecked();
      expect(screen.queryByLabelText("IP Address")).toBeNull();
      expect(screen.queryByLabelText(/sync interval/i)).toBeNull();
      expect(
        screen.queryByRole("switch", { name: /auto-sync/i }),
      ).not.toBeInTheDocument();
      unmount();
    }
  });
});

describe("<DeviceForm> a stored terminal with no address", () => {
  it("is shown as one that only sends, and can be given an address", async () => {
    const user = userEvent.setup();
    renderForm(
      "edit",
      stored({
        adapter_type: "zkteco",
        receives_only: true,
        pulled: false,
        connection_mode: "push",
        auto_sync: false,
      }),
    );

    expect(
      screen.queryByRole("switch", { name: /auto-sync/i }),
    ).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/sync interval/i)).toBeNull();
    expect(screen.getByLabelText("IP Address")).not.toBeRequired();

    await user.type(screen.getByLabelText("IP Address"), "203.0.113.10");
    expect(
      screen.getByRole("switch", { name: /auto-sync/i }),
    ).toBeInTheDocument();
  });
});

describe("<DeviceForm> saving", () => {
  it("saves everything a stored device can change, the allowlist and location included", async () => {
    const user = userEvent.setup();
    const onSubmit = renderForm("edit", stored({ commands_enabled: false }));

    const allowlist = screen.getByLabelText("Webhook allowed IP addresses");
    expect(allowlist).toHaveValue("203.0.113.7");
    await user.clear(allowlist);
    await user.type(allowlist, "198.51.100.4, 198.51.100.5");
    await user.click(
      screen.getByRole("switch", { name: /send commands to this device/i }),
    );
    await user.click(screen.getByRole("button", { name: "Save changes" }));

    expect(onSubmit).toHaveBeenCalledTimes(1);
    const payload = onSubmit.mock.calls[0][0];
    expect(payload).toMatchObject({
      name: "Front door",
      location_description: "Ground floor",
      adapter_type: "hikvision",
      branch_public_id: "B1",
      auto_sync: true,
      commands_enabled: true,
      sync_interval_minutes: 10,
      timezone: null,
      webhook_ip_allowlist: ["198.51.100.4", "198.51.100.5"],
    });
    // The stored address is never shown, so a blank one keeps it.
    expect(payload).not.toHaveProperty("connection_config");
  });

  it("registers an ADMS terminal with its allowlist, and never on the pull schedule", async () => {
    const user = userEvent.setup();
    const onSubmit = renderForm("create");

    await user.type(screen.getByLabelText("Device Name"), "Gate");
    await chooseAdapter(/ZKTeco \(ADMS/);
    await user.type(screen.getByLabelText("Serial Number"), "CQZ7232460001");
    await user.type(
      screen.getByLabelText("Allowed IP addresses (optional)"),
      "203.0.113.9",
    );
    await user.click(screen.getByRole("button", { name: "Register" }));

    const payload = onSubmit.mock.calls[0][0];
    expect(payload).toMatchObject({
      adapter_type: "zkteco_adms",
      serial_number: "CQZ7232460001",
      auto_sync: false,
      connection_config: { mode: "adms" },
      webhook_ip_allowlist: ["203.0.113.9"],
    });
  });

  it("registers an ADMS terminal with the allowlist left empty", async () => {
    const user = userEvent.setup();
    const onSubmit = renderForm("create");

    await user.type(screen.getByLabelText("Device Name"), "Gate");
    await chooseAdapter(/ZKTeco \(ADMS/);
    await user.type(screen.getByLabelText("Serial Number"), "CQZ7232460001");
    await user.click(screen.getByRole("button", { name: "Register" }));

    const payload = onSubmit.mock.calls[0][0];
    expect(payload.auto_sync).toBe(false);
    expect(payload).not.toHaveProperty("webhook_ip_allowlist");
  });

  it("leaves the allowlist alone when the server did not show it", async () => {
    const user = userEvent.setup();
    const device = stored({});
    delete (device as { webhook_ip_allowlist?: string[] }).webhook_ip_allowlist;
    const onSubmit = renderForm("edit", device);

    await user.click(screen.getByRole("button", { name: "Save changes" }));

    expect(onSubmit.mock.calls[0][0]).not.toHaveProperty(
      "webhook_ip_allowlist",
    );
  });

  it("fills in the only branch on register", async () => {
    renderForm("create");
    const branch = screen.getByRole("combobox", { name: /branch/i });
    expect(within(branch).getByText("Head office")).toBeInTheDocument();
  });
});
