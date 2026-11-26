"use client";

import { useEffect } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useCurrentUser } from "@/features/auth/api";
import { flushQueuedVisits } from "@/lib/attendance/visit-sync";
import {
  QUEUE_CHANGED_EVENT,
  useOfflineSync,
} from "@/lib/hooks/useOfflineSync";

/**
 * Sends the signed-in person's saved punches and field visits from any page,
 * not only the punch screen. The service worker hands a background sync to an
 * open window, and until now only that screen listened. Renders nothing; the
 * punch screen keeps its own instance for what it shows, and the two share one
 * in-flight guard.
 *
 * Visits are tried on mount, on reconnecting, on the service worker's sync and
 * whenever a punch run ends. They have no timer of their own: one that stays
 * kept after a failure waits for the next of those.
 */
export function OfflineSyncAgent() {
  const { data: user } = useCurrentUser();
  const queryClient = useQueryClient();
  const employeePublicId = user?.employee_public_id ?? null;
  useOfflineSync(employeePublicId);

  useEffect(() => {
    if (!employeePublicId) return;
    let live = true;
    function sendVisits() {
      if (typeof navigator !== "undefined" && !navigator.onLine) return;
      void flushQueuedVisits(employeePublicId).then(({ sent }) => {
        if (live && sent > 0) {
          void queryClient.invalidateQueries({
            queryKey: ["attendance", "visits"],
          });
        }
      });
    }
    sendVisits();
    window.addEventListener("online", sendVisits);
    window.addEventListener("ethr:sync-attendance", sendVisits);
    window.addEventListener(QUEUE_CHANGED_EVENT, sendVisits);
    return () => {
      live = false;
      window.removeEventListener("online", sendVisits);
      window.removeEventListener("ethr:sync-attendance", sendVisits);
      window.removeEventListener(QUEUE_CHANGED_EVENT, sendVisits);
    };
  }, [employeePublicId, queryClient]);

  return null;
}
