import { getLocale } from "@/lib/i18n/translations";
import { getPendingCount } from "@/lib/offline-queue";
import { recentFailedRequests } from "@/lib/support/recent-failures";
import type { NewSupportRequest } from "@/features/support/api";

type Context = NonNullable<NewSupportRequest["context"]>;

/**
 * What the screen knows right now, for a help request: the page (no query
 * string), language, screen size, whether it is online, punches waiting to
 * sync, and the last few failed requests. The person sees all of it in the
 * dialog before sending, and can leave it out.
 */
export async function collectSupportContext(): Promise<Context> {
  let pending: number | null = null;
  try {
    pending = await getPendingCount();
  } catch {
    // No IndexedDB (a private window, an old browser): say nothing rather
    // than a count of zero that would read as "nothing waiting".
    pending = null;
  }

  return {
    page: window.location.pathname,
    locale: getLocale(),
    viewport: `${window.innerWidth}x${window.innerHeight}`,
    online: navigator.onLine,
    pending_offline: pending,
    recent_errors: recentFailedRequests(),
  };
}
