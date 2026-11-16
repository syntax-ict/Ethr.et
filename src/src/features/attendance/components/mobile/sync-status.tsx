"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, CloudUpload, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useT } from "@/lib/i18n/useT";

/** How long "All punches sent." stays after the last one goes. */
const DONE_FOR_MS = 4000;

/**
 * The saved punches while there is signal: how many are waiting and a button
 * to send them, that they are going, and, for a few seconds, that all of them
 * went. Offline it says nothing; the offline banner already carries the count.
 */
export function SyncStatus({
  isOnline,
  pendingCount,
  syncing,
  onSync,
}: {
  isOnline: boolean;
  pendingCount: number;
  syncing: boolean;
  onSync: () => void;
}) {
  const { t } = useT();
  const [prevCount, setPrevCount] = useState(pendingCount);
  const [done, setDone] = useState(false);

  // More than none to none while online is the moment to say so.
  if (prevCount !== pendingCount) {
    setPrevCount(pendingCount);
    if (prevCount > 0 && pendingCount === 0 && isOnline) setDone(true);
    if (pendingCount > 0) setDone(false);
  }
  useEffect(() => {
    if (!done) return;
    const id = window.setTimeout(() => setDone(false), DONE_FOR_MS);
    return () => window.clearTimeout(id);
  }, [done]);

  if (!isOnline) return null;

  if (pendingCount > 0) {
    return (
      <div
        role="status"
        className="flex animate-fade-in items-center justify-between gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm"
      >
        {syncing ? (
          <span className="flex min-h-11 items-center gap-2 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            {pendingCount === 1
              ? t(
                  "attendance.punch.sync_sending_one",
                  "Sending :count punch…",
                  {
                    count: pendingCount,
                  },
                )
              : t(
                  "attendance.punch.sync_sending_many",
                  "Sending :count punches…",
                  { count: pendingCount },
                )}
          </span>
        ) : (
          <>
            <span className="flex items-center gap-2 text-muted-foreground">
              <CloudUpload className="h-4 w-4" aria-hidden="true" />
              {pendingCount}{" "}
              {pendingCount === 1
                ? t("attendance.mobile_page.record_pending")
                : t("attendance.mobile_page.records_pending")}
            </span>
            <Button
              size="sm"
              variant="outline"
              className="min-h-11"
              onClick={onSync}
            >
              {t("attendance.mobile_page.sync_now")}
            </Button>
          </>
        )}
      </div>
    );
  }

  if (done) {
    return (
      <div
        role="status"
        className="flex animate-fade-in items-center gap-2 rounded-lg border border-success-edge bg-success-soft px-3 py-2 text-sm text-success-on-soft"
      >
        <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden="true" />
        {t("attendance.punch.sync_done", "All punches sent.")}
      </div>
    );
  }

  return null;
}
