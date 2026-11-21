import { clearSyncedRecords } from "@/lib/offline-queue";

/** localStorage key prefix of the per-person offline grant. */
export const OFFLINE_GRANT_PREFIX = "ethr.offline_grant.";

/**
 * What a phone should stop holding once its person signs out.
 *
 * Gone: punches already on the server (their selfies included) and the offline
 * grants, which name a person who is no longer signed in.
 *
 * Kept on purpose: punches still waiting or refused, and the field visit
 * queue. They are not on the server yet; deleting them loses someone's work,
 * and they are sent when that person signs in again.
 *
 * Every step is wrapped: IndexedDB or localStorage may be missing or refuse,
 * and signing out must never fail because of it. Nor wait on it: a store that
 * neither answers nor fails is given SIGN_OUT_CLEAR_WAIT_MS, then left for the
 * next send, which clears it too.
 */
export const SIGN_OUT_CLEAR_WAIT_MS = 2000;

export async function clearOfflineOnSignOut(): Promise<void> {
  try {
    await Promise.race([
      clearSyncedRecords(),
      new Promise<void>((resolve) =>
        setTimeout(resolve, SIGN_OUT_CLEAR_WAIT_MS),
      ),
    ]);
  } catch {
    // No IndexedDB here, or it refused.
  }
  try {
    const keys: string[] = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (key?.startsWith(OFFLINE_GRANT_PREFIX)) keys.push(key);
    }
    for (const key of keys) localStorage.removeItem(key);
  } catch {
    // Storage blocked.
  }
}
