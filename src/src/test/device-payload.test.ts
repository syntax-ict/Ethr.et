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
  it("registers a ZKTeco ADMS device by serial, with no address", () => {
    const form: DeviceFormData = {
      ...EMPTY_DEVICE_FORM,
      adapter_type: "zkteco_adms",
      name: "Gate",
      serial_number: "CQZ7232460001",
      branch_public_id: "01BRANCH",
      ip: "192.168.1.9",
    };

    const created = buildDevicePayload(form, "create");
    expect(created.serial_number).toBe("CQZ7232460001");
    expect(created.connection_config).toEqual({ mode: "adms" });
    expect(buildDevicePayload(form, "edit").connection_config).toBeUndefined();
  });

  it("does not put an ADMS device on the pull schedule, on create or edit", () => {
    const form: DeviceFormData = {
      ...EMPTY_DEVICE_FORM,
      adapter_type: "zkteco_adms",
      name: "Gate",
      serial_number: "CQZ7232460001",
      branch_public_id: "01BRANCH",
      auto_sync: true,
    };

    expect(buildDevicePayload(form, "create").auto_sync).toBe(false);
    expect(buildDevicePayload(form, "edit").auto_sync).toBe(false);
  });

  it("registers a mock device with a marker config and no fake port", () => {
    const payload = buildDevicePayload(
      {
        ...EMPTY_DEVICE_FORM,
        adapter_type: "mock",
        name: "Sim",
        branch_public_id: "01BRANCH",
      },
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

  it("sends the port that was typed and never substitutes a default", () => {
    // The edit form used to pre-fill port 80, so changing only a ZKTeco's IP
    // silently replaced its stored 4370. A blank port must reach the server
    // as null — which it refuses — not as a guess.
    const typed = buildDevicePayload(
      { ...hikvision, ip: "203.0.113.9", port: "4370" },
      "edit",
    ).connection_config;
    const blank = buildDevicePayload(
      { ...hikvision, ip: "203.0.113.9", port: "" },
      "edit",
    ).connection_config;

    expect(typed).toMatchObject({ port: 4370 });
    expect(blank).toMatchObject({ port: null });
  });

  it("never resends a mock device's config on edit", () => {
    const payload = buildDevicePayload(
      {
        ...EMPTY_DEVICE_FORM,
        adapter_type: "mock",
        name: "Sim 2",
        branch_public_id: "01BRANCH",
      },
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

describe("the device form's default", () => {
  it("is a real device type, not the simulator", () => {
    // Production, 2026-10-12 (attendance QA A3): the form defaulted to "mock",
    // so naming a device and pressing Register created a simulator whose pull
    // invented punches for real employees.
    expect(EMPTY_DEVICE_FORM.adapter_type).not.toBe("mock");
  });

  // The Register form had no auto-sync switch, so every device took the
  // server's default (attendance QA A11).
  it("sends the auto-sync choice on create and edit", () => {
    expect(EMPTY_DEVICE_FORM.auto_sync).toBe(true);
    expect(
      buildDevicePayload({ ...hikvision, auto_sync: false }, "create")
        .auto_sync,
    ).toBe(false);
    expect(
      buildDevicePayload({ ...hikvision, ip: "", auto_sync: false }, "edit")
        .auto_sync,
    ).toBe(false);
  });
});

/*
 * A Hikvision terminal on an office network (2026-11-11). ETHR cannot reach a
 * private address from shared hosting and refuses one, and the form had no
 * way to register a terminal without an address.
 */
describe("a terminal that sends to ETHR", () => {
  const sending: DeviceFormData = {
    ...hikvision,
    ip: "",
    port: "80",
    username: "",
    password: "",
    sends_to_ethr: true,
  };

  it("is registered with no address, and is not put on the pull schedule", () => {
    const payload = buildDevicePayload(sending, "create");

    expect(payload.connection_config).toEqual({ mode: "push" });
    expect(payload.auto_sync).toBe(false);
  });

  it("says so on edit as well, which is how a terminal is switched over", () => {
    expect(buildDevicePayload(sending, "edit").connection_config).toEqual({
      mode: "push",
    });
  });

  it("is a Hikvision choice only: another vendor still sends its address", () => {
    const zkteco = buildDevicePayload(
      { ...sending, adapter_type: "zkteco", ip: "203.0.113.10", port: "4370" },
      "create",
    );

    expect(zkteco.connection_config).toEqual({
      ip: "203.0.113.10",
      port: 4370,
    });
  });
});

/*
 * The one device form (device setup upgrade, PR 2): Register, the list's Edit
 * and the device page's Edit share these fields, so the payload carries them
 * all, whichever door the form was opened from.
 */
describe("the fields every device form shares", () => {
  it("sends the location as typed, or null when it is blank", () => {
    expect(
      buildDevicePayload(
        { ...hikvision, location_description: "  Front gate " },
        "create",
      ).location_description,
    ).toBe("Front gate");
    expect(
      buildDevicePayload({ ...hikvision, location_description: " " }, "edit")
        .location_description,
    ).toBeNull();
  });

  it("sends the device clock zone, or null to follow the organisation", () => {
    expect(
      buildDevicePayload({ ...hikvision, timezone: "UTC" }, "create").timezone,
    ).toBe("UTC");
    expect(buildDevicePayload(hikvision, "create").timezone).toBeNull();
  });

  it("splits the allowlist on lines, commas and spaces, dropping blanks", () => {
    const payload = buildDevicePayload(
      {
        ...hikvision,
        webhook_ip_allowlist: "203.0.113.7\n 198.51.100.0/24, 192.0.2.1  \n\n",
      },
      "create",
    );

    expect(payload.webhook_ip_allowlist).toEqual([
      "203.0.113.7",
      "198.51.100.0/24",
      "192.0.2.1",
    ]);
  });

  it("sends an empty allowlist on edit so it can be cleared, and none on create", () => {
    expect(buildDevicePayload(hikvision, "edit").webhook_ip_allowlist).toEqual(
      [],
    );
    expect(buildDevicePayload(hikvision, "create")).not.toHaveProperty(
      "webhook_ip_allowlist",
    );
  });

  it("leaves the branch out on edit when none is chosen, so the stored one stays", () => {
    const blank = { ...hikvision, branch_public_id: "" };

    expect(buildDevicePayload(blank, "edit")).not.toHaveProperty(
      "branch_public_id",
    );
    expect(buildDevicePayload(hikvision, "edit").branch_public_id).toBe(
      "01BRANCH",
    );
  });

  it("clamps the sync interval to the server's floor", () => {
    const interval = (value: string) =>
      buildDevicePayload({ ...hikvision, sync_interval_minutes: value }, "edit")
        .sync_interval_minutes;

    expect(interval("1")).toBe(5);
    expect(interval("")).toBe(5);
    expect(interval("abc")).toBe(5);
    expect(interval("30")).toBe(30);
  });

  it("sends the commands switch only when the device accepts commands", () => {
    const form = { ...hikvision, commands_enabled: true };

    expect(buildDevicePayload(form, "edit")).not.toHaveProperty(
      "commands_enabled",
    );
    expect(
      buildDevicePayload(form, "edit", { acceptsCommands: true })
        .commands_enabled,
    ).toBe(true);
    expect(
      buildDevicePayload({ ...form, commands_enabled: false }, "edit", {
        acceptsCommands: true,
      }).commands_enabled,
    ).toBe(false);
  });

  it("keeps the allowlist on an ADMS device, which has no address and no pull", () => {
    const payload = buildDevicePayload(
      {
        ...EMPTY_DEVICE_FORM,
        adapter_type: "zkteco_adms",
        name: "Gate",
        serial_number: "CQZ7232460001",
        branch_public_id: "01BRANCH",
        webhook_ip_allowlist: "203.0.113.7",
      },
      "create",
    );

    expect(payload.webhook_ip_allowlist).toEqual(["203.0.113.7"]);
    expect(payload.connection_config).toEqual({ mode: "adms" });
    expect(payload.auto_sync).toBe(false);
  });
});
