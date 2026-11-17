/**
 * What a device's stored status looks like, once, for the list, the device page
 * and the health dashboard, which each kept their own copy and had drifted
 * (offline was grey on two pages and muted on the third).
 *
 * `quiet` is a terminal that only sends, heard from in the last day but not
 * the last 15 minutes. It is not an alarm, so it takes the info tone, which
 * neither of the states it sits between (success, neutral) uses for a device.
 */
export type DeviceStatusKey =
  "online" | "quiet" | "offline" | "error" | "pending";

interface DeviceStatusLook {
  labelKey: string;
  badge: string;
  dot: string;
}

const LOOKS: Record<DeviceStatusKey, DeviceStatusLook> = {
  online: {
    labelKey: "devices_page.online",
    badge: "border-0 bg-success-soft text-success-on-soft",
    dot: "bg-success animate-pulse",
  },
  quiet: {
    labelKey: "devices_page.quiet",
    badge: "border-0 bg-info-soft text-info-on-soft",
    dot: "bg-info",
  },
  offline: {
    labelKey: "devices_page.offline",
    badge: "border-0 bg-neutral-soft text-neutral-on-soft",
    dot: "bg-muted-foreground",
  },
  error: {
    labelKey: "devices_page.error",
    badge: "border-0 bg-destructive-soft text-destructive-on-soft",
    dot: "bg-destructive",
  },
  pending: {
    labelKey: "devices_page.pending",
    badge: "border-0 bg-warning-soft text-warning-on-soft",
    dot: "bg-warning",
  },
};

function look(status: string): DeviceStatusLook | undefined {
  return (LOOKS as Record<string, DeviceStatusLook | undefined>)[status];
}

/** The dictionary key of the label, or null for a status this build does not know. */
export function deviceStatusLabelKey(status: string): string | null {
  return look(status)?.labelKey ?? null;
}

export function deviceStatusBadgeClass(status: string): string {
  return look(status)?.badge ?? "";
}

export function deviceStatusDotClass(status: string): string {
  return look(status)?.dot ?? "bg-muted-foreground";
}
