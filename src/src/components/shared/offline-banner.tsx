"use client";

import { useCallback, useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  AlertTriangle,
  CheckCircle2,
  CloudUpload,
  Loader2,
  WifiOff,
} from "lucide-react";
import { getPendingCount, getRefusedRecords } from "@/lib/offline-queue";
import {
  VISIT_QUEUE_CHANGED_EVENT,
  refusedVisitsOwnedBy,
  visitsOwnedBy,
} from "@/lib/attendance/visit-queue";
import { readOfflineIdentity } from "@/features/auth/offline-identity";
import { QUEUE_CHANGED_EVENT } from "@/lib/hooks/useOfflineSync";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import {
  SENT_SHOWN_FOR_MS,
  clearLastSent,
  useSyncActivity,
} from "@/lib/offline-sync-activity";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/** The punch screen says all of this itself, in more detail (SyncStatus). */
const PUNCH_SCREEN = "/attendance/mobile";

/**
 * The one place every page says what is happening to work saved on this phone:
 * that it is waiting, that it is being sent, that it arrived, or that the
 * server refused some of it. Deliberately does NOT trigger a sync itself
 * (OfflineSyncAgent, mounted beside it, does that); it reads the queue and
 * what the senders report (offline-sync-activity).
 *
 * Until 2026-11-23 it only counted. A punch saved offline was sent from the
 * dashboard correctly and the person's only sign was the count vanishing:
 * nothing said "sending", nothing said "sent", and a punch the server refused
 * was explained on the punch screen alone.
 *
 * On a shared phone the counts are the signed-in person's own, as the punch
 * screen shows them: the identity kept for offline start names them. With no
 * identity kept, every waiting punch is counted.
 */
export function OfflineBanner() {
  const { t } = useT();
  const pathname = usePathname();
  // Connectivity comes from the shared hook rather than a copy of the listener
  // block that also lived in useOfflineSync; the two could briefly disagree
  // about whether the app was online.
  const { isOnline } = useOfflineStatus();
  const activity = useSyncActivity();
  const [waiting, setWaiting] = useState(0);
  const [refused, setRefused] = useState(0);

  const refresh = useCallback(async () => {
    const owner = readOfflineIdentity()?.user.employee_public_id ?? null;
    let visits = 0;
    let refusedVisits = 0;
    try {
      visits = visitsOwnedBy(owner).length;
      refusedVisits = refusedVisitsOwnedBy(owner).length;
    } catch {
      // localStorage may not be available in some contexts
    }
    try {
      const [punches, refusedRows] = await Promise.all([
        getPendingCount(owner),
        getRefusedRecords(owner),
      ]);
      setWaiting(punches + visits);
      setRefused(refusedRows.length + refusedVisits);
    } catch {
      // IndexedDB may not be available in some contexts
      setWaiting(visits);
      setRefused(refusedVisits);
    }
  }, []);

  // The queue is an external system: read it on mount, when either queue
  // says it changed, and on a slow timer for anything that told no one.
  useEffect(() => {
    // Genuinely synchronizing with an external system (IndexedDB and
    // localStorage), not deriving state from props: the documented exception
    // to this rule, as in useOfflineSync.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void refresh();
    const onChanged = () => void refresh();
    window.addEventListener(QUEUE_CHANGED_EVENT, onChanged);
    window.addEventListener(VISIT_QUEUE_CHANGED_EVENT, onChanged);
    const interval = setInterval(onChanged, 10000);
    return () => {
      window.removeEventListener(QUEUE_CHANGED_EVENT, onChanged);
      window.removeEventListener(VISIT_QUEUE_CHANGED_EVENT, onChanged);
      clearInterval(interval);
    };
  }, [refresh]);

  // No re-read when a run ends: both senders announce the queue changed
  // (QUEUE_CHANGED_EVENT, VISIT_QUEUE_CHANGED_EVENT), which the effect above
  // already hears.
  const sending = activity.sendingPunches + activity.sendingVisits;
  const lastSent = activity.lastSent;

  // "Sent" is said for a few seconds, then let go.
  useEffect(() => {
    if (!lastSent) return;
    const left = Math.max(0, SENT_SHOWN_FOR_MS - (Date.now() - lastSent.at));
    const id = window.setTimeout(clearLastSent, left);
    return () => window.clearTimeout(id);
  }, [lastSent]);

  const onPunchScreen = (pathname ?? "").startsWith(PUNCH_SCREEN);

  // Said in two places: on its own, and beside "sent" when one run did both.
  const refusedLine =
    refused === 1
      ? t("offline.refused_one", ":count record was not accepted.", {
          count: refused,
        })
      : t("offline.refused_many", ":count records were not accepted.", {
          count: refused,
        });
  const seeWhy = (
    <Link href={PUNCH_SCREEN} className="underline underline-offset-2">
      {t("offline.see_why", "See why")}
    </Link>
  );

  // Why what is waiting has not gone, when the last run could say. Without
  // this "waiting to be sent" read the same whether ETHR was down, busy, or
  // phone attendance had been switched off, and only the last is the
  // person's to act on.
  const because =
    activity.punchesWaitingBecause ?? activity.visitsWaitingBecause;
  const becauseLine =
    because === "unreachable"
      ? t(
          "offline.because_unreachable",
          "ETHR could not be reached. Trying again soon.",
        )
      : because === "server"
        ? t("offline.because_server", "ETHR had a problem. Trying again soon.")
        : because === "busy"
          ? t("offline.because_busy", "Too many requests. Trying again soon.")
          : because === "off"
            ? t(
                "offline.because_off",
                "Phone attendance is switched off. Ask HR.",
              )
            : null;

  let tone: "warning" | "neutral" | "success" = "warning";
  let body: ReactNode = null;

  if (!isOnline) {
    body = (
      <>
        <WifiOff className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span>{t("offline.offline", "You're offline")}</span>
        {waiting > 0 && (
          <span>
            &middot;{" "}
            {waiting === 1
              ? t("offline.saved_one", ":count record saved on this phone", {
                  count: waiting,
                })
              : t("offline.saved_many", ":count records saved on this phone", {
                  count: waiting,
                })}
          </span>
        )}
      </>
    );
  } else if (sending > 0 && !onPunchScreen) {
    tone = "neutral";
    body = (
      <>
        <Loader2
          className="h-3.5 w-3.5 shrink-0 animate-spin"
          aria-hidden="true"
        />
        <span>
          {sending === 1
            ? t("offline.sending_one", "Sending :count record…", {
                count: sending,
              })
            : t("offline.sending_many", "Sending :count records…", {
                count: sending,
              })}
        </span>
      </>
    );
  } else if (lastSent && refused > 0 && !onPunchScreen) {
    // One run sent some and had others refused: one line says both, so the
    // good news does not hide the bad for four seconds.
    const sent = lastSent.punches + lastSent.visits;
    body = (
      <>
        <AlertTriangle className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span>
          {sent === 1
            ? t("offline.sent_short_one", ":count record sent", { count: sent })
            : t("offline.sent_short_many", ":count records sent", {
                count: sent,
              })}
        </span>
        <span>&middot; {refusedLine}</span>
        {seeWhy}
      </>
    );
  } else if (lastSent && !onPunchScreen) {
    const sent = lastSent.punches + lastSent.visits;
    tone = "success";
    body = (
      <>
        <CheckCircle2 className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span>
          {sent === 1
            ? t(
                "offline.sent_one",
                ":count record sent. It is on the server now.",
                { count: sent },
              )
            : t(
                "offline.sent_many",
                ":count records sent. They are on the server now.",
                { count: sent },
              )}
        </span>
      </>
    );
  } else if (refused > 0 && !onPunchScreen) {
    body = (
      <>
        <AlertTriangle className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span>{refusedLine}</span>
        {seeWhy}
      </>
    );
  } else if (waiting > 0) {
    body = (
      <>
        <CloudUpload className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span>
          {waiting === 1
            ? t("offline.waiting_one", ":count record waiting to be sent", {
                count: waiting,
              })
            : t("offline.waiting_many", ":count records waiting to be sent", {
                count: waiting,
              })}
        </span>
        {becauseLine && <span>&middot; {becauseLine}</span>}
      </>
    );
  }

  // The live region stays mounted, so a screen reader hears each message when
  // it appears; a region that mounts with its text is not announced.
  return (
    <div role="status" aria-live="polite">
      {body && (
        <div
          data-testid="offline-banner"
          data-tone={tone}
          className={cn(
            "flex flex-wrap items-center justify-center gap-x-2 gap-y-0.5 px-4 py-1.5 text-xs font-medium",
            tone === "warning" && "bg-warning-soft text-warning-on-soft",
            tone === "neutral" && "bg-muted text-muted-foreground",
            tone === "success" && "bg-success-soft text-success-on-soft",
          )}
        >
          {body}
        </div>
      )}
    </div>
  );
}
