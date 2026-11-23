import { apiClient, tenantHeaderValue } from "@/api/client";
import type { LogVisit } from "@/features/attendance/api";
import {
  dropQueuedVisit,
  isSendableBy,
  queuedVisits,
  visitBody,
} from "@/lib/attendance/visit-queue";
import { syncFinished, syncStarted } from "@/lib/offline-sync-activity";

/** One send at a time on this page, however many places ask for one. */
let inFlight = false;

/**
 * sent       the server took it.
 * final      the server refused it for good; drop it.
 * too_large  413, resolved inside the run before it is acted on.
 * keep_stop  keep it and end the run: the next request would fail the same way.
 * keep_next  keep it and carry on: the refusal belongs to this visit alone.
 */
type Outcome = "sent" | "final" | "too_large" | "keep_stop" | "keep_next";

function statusOf(err: unknown): number | null {
  const status = (err as { response?: { status?: number } })?.response?.status;
  return typeof status === "number" ? status : null;
}

function titleOf(err: unknown): string | null {
  const title = (err as { response?: { data?: { title?: unknown } } })?.response
    ?.data?.title;
  return typeof title === "string" ? title : null;
}

async function attempt(body: LogVisit): Promise<Outcome> {
  try {
    await apiClient.post("/attendance/visits", body);
    return "sent";
  } catch (err) {
    const status = statusOf(err);
    // Phone check-in is off for now. HR may turn it back on, so keep the visit.
    if (status === 422 && titleOf(err) === "Method Disabled") {
      return "keep_next";
    }
    // 422: not valid as sent, including older than the offline window.
    // 404: this account has no employee record. Neither will ever pass.
    if (status === 422 || status === 404) return "final";
    if (status === 413) return "too_large";
    // No answer, session or rate limit, or a server fault: the next visit
    // would fail the same way.
    if (status === null || status === 401 || status === 419 || status === 429) {
      return "keep_stop";
    }
    if (status >= 500) return "keep_stop";
    // 403 (a setting HR may turn back on) and any other 4xx: this visit's own.
    return "keep_next";
  }
}

/**
 * Sends the signed-in person's visits that were logged offline, in order.
 *
 * Only this person's, in this organisation: another person's stay on the phone
 * for them. A visit leaves the queue when the server accepts it or refuses it
 * for good. A failure that is the visit's own keeps it and moves on, since
 * visits are independent stops; a failure of the connection or session keeps
 * it and ends the run.
 */
export async function flushQueuedVisits(
  employeePublicId: string | null,
): Promise<{ sent: number }> {
  if (!employeePublicId || inFlight) return { sent: 0 };
  inFlight = true;
  let sent = 0;
  let announced = false;
  try {
    const tenant = tenantHeaderValue();
    const mine = queuedVisits().filter((entry) =>
      isSendableBy(entry, employeePublicId, tenant),
    );
    if (mine.length > 0) {
      syncStarted("visits", mine.length);
      announced = true;
    }
    for (const entry of mine) {
      const body = visitBody(entry);
      let outcome = await attempt(body);
      if (outcome === "too_large" && body.photo) {
        // A photo that is too big must not lose the visit: send it without.
        outcome = await attempt({ ...body, photo: null });
      }
      // Still too large with nothing left to drop: this visit's own problem.
      if (outcome === "too_large") outcome = "keep_next";
      if (outcome === "keep_stop") break;
      if (outcome === "keep_next") continue;
      dropQueuedVisit(entry.idempotency_key);
      if (outcome === "sent") sent++;
    }
  } finally {
    inFlight = false;
    if (announced) syncFinished("visits", sent);
  }
  return { sent };
}

/** For tests: forget a run that never finished. */
export function resetVisitSyncForTests() {
  inFlight = false;
}
