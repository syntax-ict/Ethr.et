import type { components } from "@/api/generated";

type StoreDeviceRequest = components["schemas"]["StoreDeviceRequest"];

/**
 * The store rule requires a non-empty `connection_config` for every adapter,
 * mock included, and the mock adapter reads none of it — so a mock device
 * carries a marker rather than a fake address.
 */
export type DeviceConnectionConfig =
  StoreDeviceRequest["connection_config"] | { mode: "simulator" };

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
}

export const EMPTY_DEVICE_FORM: DeviceFormData = {
  name: "",
  adapter_type: "mock",
  serial_number: "",
  branch_public_id: "",
  ip: "",
  port: "80",
  username: "",
  password: "",
  api_key: "",
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
): DevicePayload {
  const payload: DevicePayload = {
    name: form.name,
    adapter_type: form.adapter_type as StoreDeviceRequest["adapter_type"],
    serial_number: form.serial_number || null,
    branch_public_id: form.branch_public_id,
  };

  if (form.adapter_type === "mock") {
    if (mode === "create") payload.connection_config = { mode: "simulator" };
    return payload;
  }

  if (!isIpAdapter(form.adapter_type)) return payload;
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
