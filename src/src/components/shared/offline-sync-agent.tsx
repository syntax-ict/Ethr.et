"use client";

import { useCurrentUser } from "@/features/auth/api";
import { useOfflineSync } from "@/lib/hooks/useOfflineSync";

/**
 * Sends the signed-in person's saved punches from any page, not only the punch
 * screen. The service worker hands a background sync to an open window, and
 * until now only that screen listened. Renders nothing; the punch screen keeps
 * its own instance for what it shows, and the two share one in-flight guard.
 */
export function OfflineSyncAgent() {
  const { data: user } = useCurrentUser();
  useOfflineSync(user?.employee_public_id ?? null);
  return null;
}
