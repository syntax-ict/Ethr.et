import { apiClient, tenantHeaderValue } from "@/api/client";
import type { LogVisit } from "@/features/attendance/api";
import {
  dropQueuedVisit,
  isSendableBy,
  queuedVisits,
  visitBody,
} from "@/lib/attendance/visit-queue";

/** One send at a time on this page, however many places ask for one. */
let inFlight = false;

type Outcome = "sent" | "final" | "too_large" | "retry";

function statusOf(err: unknown): number | null {
  const status = (err as { response?: { status?: number } })?.response?.status;
  return typeof status === "number" ? status : null;
}

async function attempt(body: LogVisit): Promise<Outcome> {
  try {
    await apiClient.post("/attendance/visits", body);
    return "sent";
  } catch (err) {
    const status = statusOf(err);
    // 422: not valid as sent, including older than the offline window.
    // 404: this account has no employee record. Neither will ever pass.
    if (status === 422 || status === 404) return "final";
    if (status === 413) return "too_large";
    // No answer, 401, 403 (a setting HR may turn back on), 419, 429, 5xx.
    return "retry";
  }
}

/**
 * Sends the signed-in person's visits that were logged offline, in order.
 *
 * Only this person's, in this organisation: another person's stay on the phone
 * for them. A visit leaves the queue when the server accepts it or refuses it
 * for good. Any other failure keeps it untouched and ends the run, so a later
 * visit never overtakes it.
 */
export async function flushQueuedVisits(
  employeePublicId: string | null,
): Promise<{ sent: number }> {
  if (!employeePublicId || inFlight) return { sent: 0 };
  inFlight = true;
  let sent = 0;
  try {
    const tenant = tenantHeaderValue();
    for (const entry of queuedVisits()) {
      if (!isSendableBy(entry, employeePublicId, tenant)) continue;
      const body = visitBody(entry);
      let outcome = await attempt(body);
      if (outcome === "too_large" && body.photo) {
        // A photo that is too big must not lose the visit: send it without.
        outcome = await attempt({ ...body, photo: null });
        if (outcome === "too_large") outcome = "retry";
      } else if (outcome === "too_large") {
        outcome = "retry";
      }
      if (outcome === "retry") break;
      dropQueuedVisit(entry.idempotency_key);
      if (outcome === "sent") sent++;
    }
  } finally {
    inFlight = false;
  }
  return { sent };
}

/** For tests: forget a run that never finished. */
export function resetVisitSyncForTests() {
  inFlight = false;
}
