import type { QueryClient } from "@tanstack/react-query";
import { clearOfflineIdentity } from "@/features/auth/offline-identity";
import { clearApiCache } from "@/lib/api-cache";

/** localStorage key prefix of the recent searches (components/shared/recent-searches.ts). */
const RECENT_SEARCH_PREFIX = "ethr.search.recent.";

/**
 * Saved views of the employee list (`datatable:employees:views`). The views are
 * a preference and stay; their free-text `search` is somebody's name, email or
 * code, and goes.
 */
const SAVED_VIEW_KEYS = ["datatable:employees:views"];

/** localStorage keys that belong to a session and nothing else. */
const SESSION_KEYS = ["impersonating", "original_tenant"];

/** Longest the cleanup waits for Cache Storage before letting the caller go on. */
const CACHE_CLEAR_WAIT_MS = 2000;

function stripSavedViewSearch(raw: string): string | null {
  try {
    const views: unknown = JSON.parse(raw);
    if (!Array.isArray(views)) return null;

    return JSON.stringify(
      views.map((view) => {
        if (
          typeof view !== "object" ||
          view === null ||
          typeof (view as { state?: unknown }).state !== "object" ||
          (view as { state?: unknown }).state === null
        ) {
          return view;
        }
        const { search: _search, ...state } = (
          view as { state: Record<string, unknown> }
        ).state;
        return { ...view, state };
      }),
    );
  } catch {
    return null;
  }
}

/** The synchronous part: localStorage keys that name people or a session. */
function clearSessionKeys(): void {
  try {
    const recents: string[] = [];
    for (let i = 0; i < localStorage.length; i++) {
      const key = localStorage.key(i);
      if (key?.startsWith(RECENT_SEARCH_PREFIX)) recents.push(key);
    }
    for (const key of recents) localStorage.removeItem(key);

    for (const key of SESSION_KEYS) localStorage.removeItem(key);

    for (const key of SAVED_VIEW_KEYS) {
      const raw = localStorage.getItem(key);
      if (raw === null) continue;
      const stripped = stripSavedViewSearch(raw);
      // Unreadable: the views are not worth keeping if their search cannot be
      // taken out of them.
      if (stripped === null) localStorage.removeItem(key);
      else localStorage.setItem(key, stripped);
    }
  } catch {
    // Storage blocked; nothing to clear, and the session still moves on.
  }
}

/**
 * What this browser should stop holding once a session starts or ends: the
 * query cache, the API answers the service worker stored, the offline identity,
 * the recent searches, the free text of saved employee views, and the two
 * impersonation flags.
 *
 * Called on every sign-in as well as every sign-out, because the stored
 * answers are keyed by address and not by organisation: a second organisation
 * signing in on the same browser would otherwise be answered offline with the
 * first one's copy (SP-BROWSER-DATA-EXPOSURE E-2).
 *
 * It does not touch `tenant`, so a sign-in clears first and then sets it. It
 * does not touch the offline punch queue, the kiosk queue or the field visit
 * queue either: punches that are not on the server yet belong to someone's
 * work. Sign-out paths additionally call `clearOfflineOnSignOut`, which keeps
 * them on purpose.
 *
 * Never throws and never waits long: Cache Storage that neither answers nor
 * fails is left for the next send.
 */
export async function clearSessionData(
  queryClient?: Pick<QueryClient, "clear"> | null,
): Promise<void> {
  // Synchronous first, so the caller's next line can rely on it even if the
  // awaited part is cut short.
  clearSessionKeys();
  clearOfflineIdentity();
  try {
    queryClient?.clear();
  } catch {
    // The cache is in memory; a page load empties it regardless.
  }

  await Promise.race([
    clearApiCache(),
    new Promise<void>((resolve) => setTimeout(resolve, CACHE_CLEAR_WAIT_MS)),
  ]);
}
