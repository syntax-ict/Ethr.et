import type { components } from "@/api/generated";

type StoreDeviceRequest = components["schemas"]["StoreDeviceRequest"];

/**
 * The store rule requires a non-empty `connection_config` for every adapter,
 * mock included, and the mock adapter reads none of it — so a mock device
 * carries a marker rather than a fake address.
 */
export type DeviceConnectionConfig =
  | StoreDeviceRequest["connection_config"]
  | { mode: "simulator" }
  | { mode: "adms" }
  | { mode: "push" };

export type DevicePayload = Omit<StoreDeviceRequest, "connection_config"> & {
  connection_config?: DeviceConnectionConfig;
};

export interface DeviceFormData {
  name: string;
  adapter_type: string;
  serial_number: string;
  branch_public_id: string;
  ip: string;
  port: string;
  username: string;
  password: string;
  api_key: string;
  /** Whether the scheduler pulls this device every few minutes. */
  auto_sync: boolean;
  /**
   * A Hikvision terminal on an office network: it sends its punches to ETHR
   * and has no address ETHR could reach, so none is asked for.
   */
  sends_to_ethr: boolean;
  location_description: string;
  /** An IANA zone the device's own clock is set to; blank follows the organisation. */
  timezone: string;
  /** One IP address or range per line (commas and spaces also split). */
  webhook_ip_allowlist: string;
  commands_enabled: boolean;
  /** Minutes, as typed or picked; clamped to the server's floor on the way out. */
  sync_interval_minutes: string;
}

// Mirrors Device::MIN_SYNC_INTERVAL_MINUTES on the API.
export const MIN_SYNC_INTERVAL_MINUTES = 5;

/** Split the allowlist text into the array the API takes. */
export function parseAllowlist(text: string): string[] {
  return text
    .split(/[\s,]+/)
    .map((ip) => ip.trim())
    .filter(Boolean);
}

/** The adapters that can be registered to send to ETHR with no address. */
export function canSendToEthr(adapterType: string): boolean {
  return adapterType === "hikvision";
}

/**
 * Whether this build offers the device simulator. Never in production: its
 * pull invents punches for real employees, and the API refuses it there
 * (attendance QA A3). It was the form's default, so a tenant admin who only
 * named a device and pressed Register created one.
 */
export const SIMULATOR_IN_FORM = process.env.NODE_ENV !== "production";

export const EMPTY_DEVICE_FORM: DeviceFormData = {
  name: "",
  adapter_type: "hikvision",
  serial_number: "",
  branch_public_id: "",
  ip: "",
  port: "80",
  username: "",
  password: "",
  api_key: "",
  auto_sync: true,
  sends_to_ethr: false,
  location_description: "",
  timezone: "",
  webhook_ip_allowlist: "",
  commands_enabled: false,
  sync_interval_minutes: String(MIN_SYNC_INTERVAL_MINUTES),
};

/** Adapters addressed as `http://{ip}:{port}` — the only ones this form can configure. */
export const IP_ADAPTERS = ["hikvision", "zkteco", "suprema"] as const;

export function isIpAdapter(adapterType: string): boolean {
  return (IP_ADAPTERS as readonly string[]).includes(adapterType);
}

/**
 * Build the request body for the device form.
 *
 * On edit, the stored connection settings are never shown — the API does not
 * return them, because they hold device credentials — so a blank address means
 * "keep what is stored" and `connection_config` is left out. When an address is
 * entered, only the credentials actually typed are sent; the server merges the
 * rest from the stored config.
 */
export function buildDevicePayload(
  form: DeviceFormData,
  mode: "create" | "edit",
  options: {
    /** `device.accepts_commands`: only then is the commands switch offered. */
    acceptsCommands?: boolean;
  } = {},
): DevicePayload {
  const payload: DevicePayload = {
    name: form.name,
    adapter_type: form.adapter_type as StoreDeviceRequest["adapter_type"],
    serial_number: form.serial_number || null,
    branch_public_id: form.branch_public_id,
    // Sent on both paths: the Register form had no switch, so every device
    // took the server's default and was pulled every five minutes whether or
    // not anyone wanted it (attendance QA A11).
    auto_sync: form.auto_sync,
    location_description: form.location_description.trim() || null,
    timezone: form.timezone || null,
    sync_interval_minutes: Math.max(
      MIN_SYNC_INTERVAL_MINUTES,
      Number.parseInt(form.sync_interval_minutes, 10) ||
        MIN_SYNC_INTERVAL_MINUTES,
    ),
  };

  // A blank branch on edit keeps the stored one: the field is not sent, since
  // an empty string reaches the API as null and `string` refuses that.
  if (mode === "edit" && form.branch_public_id === "") {
    delete (payload as Partial<DevicePayload>).branch_public_id;
  }

  // On edit the array is always sent, so emptying the box clears the list.
  const allowlist = parseAllowlist(form.webhook_ip_allowlist);
  if (mode === "edit" || allowlist.length > 0) {
    payload.webhook_ip_allowlist = allowlist;
  }

  if (options.acceptsCommands) {
    payload.commands_enabled = form.commands_enabled;
  }

  if (form.adapter_type === "mock") {
    if (mode === "create") payload.connection_config = { mode: "simulator" };
    return payload;
  }

  // ZKTeco ADMS calls ETHR, so there is no address to store: the serial
  // number identifies it, and the IP allowlist admits it.
  if (form.adapter_type === "zkteco_adms") {
    if (mode === "create") payload.connection_config = { mode: "adms" };
    // Nothing to pull from a terminal that only sends.
    payload.auto_sync = false;
    return payload;
  }

  if (!isIpAdapter(form.adapter_type)) return payload;

  // Sent on edit too: it is how a terminal ETHR used to call is switched
  // over, and the server then drops the address and credentials it held.
  if (canSendToEthr(form.adapter_type) && form.sends_to_ethr) {
    payload.connection_config = { mode: "push" };
    // Nothing to pull from a terminal that only sends.
    payload.auto_sync = false;
    return payload;
  }

  if (mode === "edit" && form.ip.trim() === "") return payload;

  // No fallback port: a blank one goes as null and the server says so, rather
  // than this guessing 80 over a ZKTeco's 4370.
  const port = Number.parseInt(form.port, 10);
  payload.connection_config = {
    ip: form.ip.trim(),
    port: Number.isNaN(port) ? null : port,
    ...(form.username && { username: form.username }),
    ...(form.password && { password: form.password }),
    ...(form.api_key && { api_key: form.api_key }),
  };

  return payload;
}
