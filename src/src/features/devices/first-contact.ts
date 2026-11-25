import type { Device } from "@/features/devices/api";
import type { ConnectionMode } from "@/features/devices/payload";

/**
 * What the setup wizard can honestly say about a terminal that was just
 * registered, read from the device as the server describes it. Nothing here
 * asks the terminal anything: an ADMS terminal calls ETHR, and a push terminal
 * posts a punch, so "heard" is a timestamp the server wrote.
 */
export type FirstContact =
  /** Nothing yet. */
  | { kind: "waiting" }
  /** ADMS: the terminal called and was refused; `ip` is where it called from. */
  | { kind: "refused"; ip: string | null; at: string }
  /** ADMS: the terminal has called and was admitted. */
  | { kind: "heard"; at: string | null }
  /**
   * Push: a punch arrived. `matched` is a person it was recorded for, `held`
   * is a badge number that names nobody yet, and neither means it is still
   * being worked by the queue.
   */
  | { kind: "punch"; matched: boolean; held: boolean };

/** Whether the first contact has happened and the wizard may move on. */
export function isConfirmed(contact: FirstContact): boolean {
  return contact.kind === "heard" || contact.kind === "punch";
}

export function firstContactFor(
  device: Device,
  mode: ConnectionMode,
): FirstContact {
  if (mode === "adms") {
    const rejected = device.adms?.last_rejected ?? null;
    const heardAt = device.last_sync_at ?? null;

    if (heardAt) {
      const refusedSince =
        rejected && new Date(rejected.at).getTime() > new Date(heardAt).getTime();
      if (!refusedSince) return { kind: "heard", at: heardAt };
    }
    if (rejected) {
      return { kind: "refused", ip: rejected.ip ?? null, at: rejected.at };
    }
    return { kind: "waiting" };
  }

  if (mode === "push") {
    const today = device.today;
    const received = Number(today?.received ?? 0);
    const recorded = Number(today?.recorded ?? 0);
    const held = Number(today?.held ?? 0);
    const waiting = Number(today?.waiting ?? 0);

    if (received > 0 || waiting > 0 || device.last_sync_at) {
      return { kind: "punch", matched: recorded > 0, held: held > 0 };
    }
  }

  return { kind: "waiting" };
}
