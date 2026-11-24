import { apiClient, tenantHeaderValue } from "@/api/client";
import type { LogVisit } from "@/features/attendance/api";
import {
  dropQueuedVisit,
  isSendableBy,
  markVisitRefused,
  queuedVisits,
  visitBody,
} from "@/lib/attendance/visit-queue";
import {
  syncFinished,
  syncStarted,
  type SyncFailure,
} from "@/lib/offline-sync-activity";

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

/**
 * What one try came to. `reason` is the server's own sentence for a final
 * refusal, shown to the person beside the visit. `failure` is why a kept
 * visit is still here, when that is something to tell them.
 */
type Attempt = {
  outcome: Outcome;
  reason?: string | null;
  failure?: SyncFailure | null;
};

function statusOf(err: unknown): number | null {
  const status = (err as { response?: { status?: number } })?.response?.status;
  return typeof status === "number" ? status : null;
}

function titleOf(err: unknown): string | null {
  const title = (err as { response?: { data?: { title?: unknown } } })?.response
    ?.data?.title;
  return typeof title === "string" ? title : null;
}

function detailOf(err: unknown): string | null {
  const detail = (err as { response?: { data?: { detail?: unknown } } })
    ?.response?.data?.detail;
  return typeof detail === "string" && detail.trim() !== "" ? detail : null;
}

async function attempt(body: LogVisit): Promise<Attempt> {
  try {
    await apiClient.post("/attendance/visits", body);
    return { outcome: "sent" };
  } catch (err) {
    const status = statusOf(err);
    // Phone check-in is off for now. HR may turn it back on, so keep the visit.
    if (status === 422 && titleOf(err) === "Method Disabled") {
      return { outcome: "keep_next", failure: "off" };
    }
    // 422: not valid as sent, including older than the offline window.
    // 404: this account has no employee record. Neither will ever pass.
    if (status === 422 || status === 404) {
      return { outcome: "final", reason: detailOf(err) };
    }
    if (status === 413) return { outcome: "too_large" };
    // No answer, session or rate limit, or a server fault: the next visit
    // would fail the same way.
    if (status === null)
      return { outcome: "keep_stop", failure: "unreachable" };
    if (status === 429) return { outcome: "keep_stop", failure: "busy" };
    if (status === 401 || status === 419) return { outcome: "keep_stop" };
    if (status >= 500) return { outcome: "keep_stop", failure: "server" };
    // 403 (a setting HR may turn back on) and any other 4xx: this visit's own.
    return { outcome: "keep_next" };
  }
}

/**
 * Sends the signed-in person's visits that were logged offline, in order.
 *
 * Only this person's, in this organisation: another person's stay on the phone
 * for them. A visit leaves the queue when the server accepts it. One the
 * server refuses for good stays, marked refused with the server's reason, and
 * is never sent again: it is shown to the person, who removes it once noted
 * (it used to be dropped, and nobody was told). A failure that is the visit's
 * own keeps it and moves on, since visits are independent stops; a failure of
 * the connection or session keeps it and ends the run.
 */
export async function flushQueuedVisits(
  employeePublicId: string | null,
): Promise<{ sent: number }> {
  if (!employeePublicId || inFlight) return { sent: 0 };
  inFlight = true;
  let sent = 0;
  let announced = false;
  let failure: SyncFailure | null = null;
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
      let tried = await attempt(body);
      if (tried.outcome === "too_large" && body.photo) {
        // A photo that is too big must not lose the visit: send it without.
        tried = await attempt({ ...body, photo: null });
      }
      // Still too large with nothing left to drop: this visit's own problem.
      if (tried.outcome === "too_large") tried = { outcome: "keep_next" };
      if (tried.failure) failure = tried.failure;
      if (tried.outcome === "keep_stop") break;
      if (tried.outcome === "keep_next") continue;
      if (tried.outcome === "final") {
        markVisitRefused(entry.idempotency_key, tried.reason ?? null);
        continue;
      }
      dropQueuedVisit(entry.idempotency_key);
      sent++;
    }
  } finally {
    inFlight = false;
    if (announced) syncFinished("visits", sent, failure);
  }
  return { sent };
}

/** For tests: forget a run that never finished. */
export function resetVisitSyncForTests() {
  inFlight = false;
}
