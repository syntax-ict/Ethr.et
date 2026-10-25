/**
 * What the phone can tell about a punch beyond its position, sent with every
 * punch for the server's risk scoring (AttendanceRiskScorer): the fix's
 * altitude, speed, heading and age, a random id for this install of the app,
 * the device clock, and whether the browser is being driven by automation.
 *
 * The install id is random and stays on this device; the server keeps only a
 * keyed hash of it, enough to tell that two punches came from one install.
 */
import type { DeviceProof } from "@/lib/attendance/passkey";

const DEVICE_KEY = "ethr-device-id";

export interface PunchSignals {
  altitude?: number;
  speed?: number;
  heading?: number;
  fix_age_ms?: number;
  device_id?: string;
  client_time: string;
  webdriver?: boolean;
  /** The registered phone's signature for this punch (passkey.ts). */
  device_proof?: DeviceProof;
}

/** A position as the Geolocation API reports it, kept whole. */
export interface Fix {
  latitude: number;
  longitude: number;
  accuracy: number;
  altitude?: number | null;
  speed?: number | null;
  heading?: number | null;
  /** When the fix was taken (ms since epoch), not when it was read. */
  timestamp?: number;
}

export function fixFrom(pos: GeolocationPosition): Fix {
  return {
    latitude: pos.coords.latitude,
    longitude: pos.coords.longitude,
    accuracy: pos.coords.accuracy,
    altitude: pos.coords.altitude,
    speed: pos.coords.speed,
    heading: pos.coords.heading,
    timestamp: pos.timestamp,
  };
}

export function deviceId(): string | undefined {
  try {
    let id = window.localStorage.getItem(DEVICE_KEY);
    if (!id) {
      id =
        typeof crypto !== "undefined" && "randomUUID" in crypto
          ? crypto.randomUUID()
          : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
      window.localStorage.setItem(DEVICE_KEY, id);
    }
    return id;
  } catch {
    // Private mode or storage blocked: punch without an install id.
    return undefined;
  }
}

/** The signals for a punch, from the fix used (if any) at time `now`. */
export function punchSignals(
  fix: Fix | null,
  now: Date = new Date(),
): PunchSignals {
  const signals: PunchSignals = { client_time: now.toISOString() };
  const id = deviceId();
  if (id) signals.device_id = id;
  if (typeof navigator !== "undefined" && navigator.webdriver === true) {
    signals.webdriver = true;
  }
  if (fix) {
    if (typeof fix.altitude === "number") signals.altitude = fix.altitude;
    if (typeof fix.speed === "number") signals.speed = fix.speed;
    if (typeof fix.heading === "number" && !Number.isNaN(fix.heading)) {
      signals.heading = fix.heading;
    }
    if (typeof fix.timestamp === "number") {
      signals.fix_age_ms = Math.max(0, now.getTime() - fix.timestamp);
    }
  }
  return signals;
}

/**
 * A best-effort fix for punches whose screen has no map (QR scan, visits):
 * resolves null rather than failing when location is refused or slow.
 */
export function currentFix(timeoutMs = 8000): Promise<Fix | null> {
  return new Promise((resolve) => {
    if (typeof navigator === "undefined" || !navigator.geolocation) {
      resolve(null);
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => resolve(fixFrom(pos)),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 60_000 },
    );
  });
}
