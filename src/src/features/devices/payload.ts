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
  /** Whether ETHR may send commands to the terminal (clock, people). */
  commands_enabled: boolean;
  sync_interval_minutes: string;
  /** Empty means the organisation's own zone. */
  timezone: string;
  /** One address or range per line, or comma separated. */
  webhook_ip_allowlist: string;
}

/**
 * How a terminal reaches ETHR, read from the form's own choices. It matches
 * the server's `connection_mode` for a device that has not been changed, and
 * it follows the form while the adapter or the office-network switch moves.
 */
export type ConnectionMode = "adms" | "push" | "pull";

export function connectionModeFor(
  form: Pick<DeviceFormData, "adapter_type" | "sends_to_ethr">,
): ConnectionMode {
  if (form.adapter_type === "zkteco_adms") return "adms";
  if (canSendToEthr(form.adapter_type) && form.sends_to_ethr) return "push";
  return "pull";
}

/** The adapters whose terminals can carry out commands (DeviceCommandQueue::supports). */
export function canAcceptCommands(adapterType: string): boolean {
  return (
    adapterType === "hikvision" ||
    adapterType === "zkteco_adms" ||
    adapterType === "mock"
  );
}

// Mirrors Device::MIN_SYNC_INTERVAL_MINUTES on the API.
export const MIN_SYNC_INTERVAL_MINUTES = 5;

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
  commands_enabled: false,
  sync_interval_minutes: String(MIN_SYNC_INTERVAL_MINUTES),
  timezone: "",
  webhook_ip_allowlist: "",
};

/** Adapters addressed as `http://{ip}:{port}` — the only ones this form can configure. */
export const IP_ADAPTERS = ["hikvision", "zkteco", "suprema"] as const;

export function isIpAdapter(adapterType: string): boolean {
  return (IP_ADAPTERS as readonly string[]).includes(adapterType);
}

interface PayloadOptions {
  /** Edit only: whether the stored device can carry out commands. */
  acceptsCommands?: boolean;
  /** Edit only: false when the server did not show the allowlist (not a tenant admin), so it is left alone. */
  sendsAllowlist?: boolean;
  /** Edit only: the adapter was changed, so the old vendor's stored connection no longer applies. */
  adapterChanged?: boolean;
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
  mode: "create",
  options?: PayloadOptions,
): DevicePayload;
export function buildDevicePayload(
  form: DeviceFormData,
  mode: "edit",
  options?: PayloadOptions,
): Partial<DevicePayload>;
export function buildDevicePayload(
  form: DeviceFormData,
  mode: "create" | "edit",
  options: PayloadOptions = {},
): Partial<DevicePayload> {
  const payload: Partial<DevicePayload> = {
    name: form.name,
    adapter_type: form.adapter_type as StoreDeviceRequest["adapter_type"],
    serial_number: form.serial_number || null,
    // Sent on both paths: the Register form had no switch, so every device
    // took the server's default and was pulled every five minutes whether or
    // not anyone wanted it (attendance QA A11).
    auto_sync: form.auto_sync,
  };

  // A new device must name its branch, and the server says so when it is
  // blank. An edit with none chosen keeps the stored branch.
  if (mode === "create" || form.branch_public_id !== "") {
    payload.branch_public_id = form.branch_public_id;
  }

  const location = form.location_description.trim();
  if (mode === "edit" || location !== "") {
    payload.location_description = location || null;
  }

  if (mode === "edit" || form.timezone !== "") {
    payload.timezone = form.timezone || null;
  }

  const allowlist = parseAllowlist(form.webhook_ip_allowlist);
  if (
    mode === "create" ? allowlist.length > 0 : options.sendsAllowlist !== false
  ) {
    payload.webhook_ip_allowlist = allowlist;
  }

  const commandsOffered =
    mode === "create"
      ? canAcceptCommands(form.adapter_type)
      : options.acceptsCommands === true;
  if (commandsOffered && (mode === "edit" || form.commands_enabled)) {
    payload.commands_enabled = form.commands_enabled;
  }

  if (connectionModeFor(form) === "pull") {
    payload.sync_interval_minutes = Math.max(
      MIN_SYNC_INTERVAL_MINUTES,
      Number.parseInt(form.sync_interval_minutes, 10) ||
        MIN_SYNC_INTERVAL_MINUTES,
    );
  }

  const marksConnection = mode === "create" || options.adapterChanged === true;

  if (form.adapter_type === "mock") {
    if (marksConnection) payload.connection_config = { mode: "simulator" };
    return payload;
  }

  // ZKTeco ADMS calls ETHR, so there is no address to store: the serial
  // number identifies it, and the IP allowlist admits it.
  if (form.adapter_type === "zkteco_adms") {
    if (marksConnection) payload.connection_config = { mode: "adms" };
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
