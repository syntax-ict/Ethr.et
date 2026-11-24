import type { Device } from "@/features/devices/api";

/**
 * What the dialog after registering (or after regenerating an address) tells
 * the administrator, by how the terminal connects. The three modes need three
 * different things typed, or none, so one dialog that always showed the push
 * address was wrong for two of them.
 */
export type IssuedSetup =
  /** The terminal calls ETHR at this address, shown once: only a hash is kept. */
  | { mode: "push"; url: string; graceHours?: number | null }
  /** The terminal calls ETHR and is found by its serial; no address, no token. */
  | {
      mode: "adms";
      server: string;
      serial: string | null;
      allowlistEmpty: boolean;
    }
  /** ETHR calls the terminal at the address that was entered. */
  | { mode: "pull"; devicePublicId: string; adapterType: string };

/**
 * The setup to show for a device just registered, or null when there is
 * nothing to tell: the simulator and other types with no terminal to set up.
 */
export function issuedSetupFor(device: Device): IssuedSetup | null {
  if (device.connection_mode === "adms") {
    if (!device.adms?.server) return null;
    return {
      mode: "adms",
      server: device.adms.server,
      serial: device.serial_number ?? null,
      allowlistEmpty: (device.webhook_ip_allowlist ?? []).length === 0,
    };
  }

  if (!device.uses_webhook_token) return null;

  if (device.connection_mode === "push") {
    return device.webhook_url
      ? { mode: "push", url: device.webhook_url }
      : null;
  }

  return {
    mode: "pull",
    devicePublicId: device.public_id,
    adapterType: device.adapter_type,
  };
}
