import { describe, it, expect } from "vitest";
import {
  buildDevicePayload,
  EMPTY_DEVICE_FORM,
  type DeviceFormData,
} from "@/features/devices/payload";

/**
 * The device form's payload, pinned against the backend rules it has to pass.
 * Before these existed, the default "Add device" (mock adapter) always sent
 * `port: 0` and was refused with 422 by `connection_config.port` `min:1`, and
 * every edit resent the whole connection config with a blank address and blank
 * credentials — the API never returns them, so the form could not know them.
 */
const hikvision: DeviceFormData = {
  ...EMPTY_DEVICE_FORM,
  name: "Gate A",
  adapter_type: "hikvision",
  branch_public_id: "01BRANCH",
  ip: "192.168.1.100",
  port: "8000",
  username: "admin",
  password: "secret",
};

describe("buildDevicePayload", () => {
  it("registers a mock device with a marker config and no fake port", () => {
    const payload = buildDevicePayload(
      { ...EMPTY_DEVICE_FORM, name: "Sim", branch_public_id: "01BRANCH" },
      "create",
    );

    expect(payload.adapter_type).toBe("mock");
    expect(payload.connection_config).toEqual({ mode: "simulator" });
  });

  it("sends address and credentials when registering an IP device", () => {
    expect(buildDevicePayload(hikvision, "create").connection_config).toEqual({
      ip: "192.168.1.100",
      port: 8000,
      username: "admin",
      password: "secret",
    });
  });

  it("omits credentials that were not typed instead of sending null", () => {
    const config = buildDevicePayload(
      { ...hikvision, username: "", password: "" },
      "create",
    ).connection_config;

    expect(config).toEqual({ ip: "192.168.1.100", port: 8000 });
    expect(config).not.toHaveProperty("password");
  });

  it("leaves the stored connection alone when an edit leaves the address blank", () => {
    const payload = buildDevicePayload(
      { ...hikvision, name: "Renamed", ip: "", username: "", password: "" },
      "edit",
    );

    expect(payload.name).toBe("Renamed");
    expect(payload).not.toHaveProperty("connection_config");
  });

  it("never resends a mock device's config on edit", () => {
    const payload = buildDevicePayload(
      { ...EMPTY_DEVICE_FORM, name: "Sim 2", branch_public_id: "01BRANCH" },
      "edit",
    );

    expect(payload).not.toHaveProperty("connection_config");
  });

  it("does not send an IP config for an adapter this form cannot configure", () => {
    const payload = buildDevicePayload(
      { ...hikvision, adapter_type: "generic" },
      "edit",
    );

    expect(payload).not.toHaveProperty("connection_config");
  });
});
