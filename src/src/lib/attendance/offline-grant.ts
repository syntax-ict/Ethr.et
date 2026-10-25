import { apiClient } from "@/api/client";

/**
 * The server's permission to punch offline (see OfflineGrant on the API).
 *
 * Fetched while the phone has signal and kept on the device; every punch
 * queued without signal carries it, so the server can vouch that the punch
 * was made by this person after the grant was issued - however the phone's
 * clock was set. A punch queued without one is still accepted, but flagged
 * for review.
 */
interface StoredGrant {
  grant: string;
  issued_at: string;
  expires_at: string;
  /** With a registered phone: what an offline punch is signed with (passkey.ts). */
  device_nonce?: string | null;
  credential_id?: string | null;
  rp_id?: string | null;
}

const PREFIX = "ethr.offline_grant.";

/** Renew a grant with less than this left, so a long outage stays covered. */
const RENEW_BEFORE_MS = 24 * 60 * 60 * 1000;

function read(employeePublicId: string): StoredGrant | null {
  try {
    const raw = window.localStorage.getItem(PREFIX + employeePublicId);
    return raw ? (JSON.parse(raw) as StoredGrant) : null;
  } catch {
    return null;
  }
}

/** The grant to attach to a punch made now, or null when none is valid. */
export function currentOfflineGrant(employeePublicId: string): string | null {
  const stored = read(employeePublicId);
  if (!stored) return null;
  return Date.parse(stored.expires_at) > Date.now() ? stored.grant : null;
}

/** The registered phone's signing details from the current grant, or null. */
export function currentOfflineDevice(
  employeePublicId: string,
): { device_nonce: string; credential_id: string; rp_id: string } | null {
  const stored = read(employeePublicId);
  if (!stored || Date.parse(stored.expires_at) <= Date.now()) return null;
  return stored.device_nonce && stored.credential_id && stored.rp_id
    ? {
        device_nonce: stored.device_nonce,
        credential_id: stored.credential_id,
        rp_id: stored.rp_id,
      }
    : null;
}

/**
 * Fetch a fresh grant when the stored one is missing or close to expiry, or
 * when the phone has just been registered and the grant does not name it.
 */
export async function refreshOfflineGrant(
  employeePublicId: string,
  hasRegisteredPhone = false,
): Promise<void> {
  const stored = read(employeePublicId);
  const fresh =
    stored && Date.parse(stored.expires_at) - Date.now() > RENEW_BEFORE_MS;
  if (fresh && (!hasRegisteredPhone || stored.device_nonce)) {
    return;
  }

  try {
    const { data } = await apiClient.get<StoredGrant>(
      "/attendance/offline-grant",
    );
    window.localStorage.setItem(
      PREFIX + employeePublicId,
      JSON.stringify(data),
    );
  } catch {
    // Offline or refused: the next visit with signal tries again.
  }
}
