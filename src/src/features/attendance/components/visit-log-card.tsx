"use client";

import { useEffect, useState } from "react";
import { Camera, Loader2, MapPin, Route } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import {
  useAttendanceDevice,
  useLogVisit,
  useMyVisits,
  usePunchSigner,
  type LogVisit,
} from "@/features/attendance/api";
import { currentFix, punchSignals } from "@/lib/attendance/punch-signals";
import {
  VISIT_QUEUE_CHANGED_EVENT,
  dropQueuedVisit,
  loggedAt,
  queueVisit,
  refusedVisitsOwnedBy,
  visitsOwnedBy,
  type QueuedVisit,
} from "@/lib/attendance/visit-queue";
import { tenantHeaderValue } from "@/api/client";
import { useCurrentUser } from "@/features/auth/api";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import { fitSelfie } from "@/lib/uploads/selfie";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";

/** A chosen photo, scaled and compressed to the selfie size limit. */
async function photoFromFile(file: File): Promise<string> {
  const url = URL.createObjectURL(file);
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const el = new Image();
      el.onload = () => resolve(el);
      el.onerror = reject;
      el.src = url;
    });
    return fitSelfie((maxEdge, quality) => {
      const scale = Math.min(1, maxEdge / Math.max(img.width, img.height));
      const canvas = document.createElement("canvas");
      canvas.width = Math.round(img.width * scale);
      canvas.height = Math.round(img.height * scale);
      canvas
        .getContext("2d")
        ?.drawImage(img, 0, 0, canvas.width, canvas.height);
      return canvas.toDataURL("image/jpeg", quality);
    });
  } finally {
    URL.revokeObjectURL(url);
  }
}

/**
 * Field workers log the stops they make during the day: a client, a farm, a
 * health post. A visit is evidence of work, never a punch, so it does not
 * change hours. Without a connection it is kept on the phone and sent when
 * the connection returns.
 */
export function VisitLogCard({ photoConsent }: { photoConsent: boolean }) {
  const { t } = useT();
  const { formatTime } = useDateFormatters();
  const { isOnline } = useOfflineStatus();
  const log = useLogVisit();
  const { data: deviceState } = useAttendanceDevice();
  const signPunch = usePunchSigner(deviceState);
  const visits = useMyVisits(isOnline);
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState("");
  const [photo, setPhoto] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const { data: me } = useCurrentUser();
  const employeePublicId = me?.employee_public_id ?? null;
  // Only this person's own. Sending is the shell's OfflineSyncAgent, from any
  // page; this card only shows what is waiting.
  const [queued, setQueued] = useState(0);
  // Visits the server refused for good. They are no longer sent, so each is
  // shown with the server's reason until the person removes it.
  const [refused, setRefused] = useState<QueuedVisit[]>([]);
  useEffect(() => {
    const refresh = () => {
      setQueued(visitsOwnedBy(employeePublicId).length);
      setRefused(refusedVisitsOwnedBy(employeePublicId));
    };
    refresh();
    window.addEventListener(VISIT_QUEUE_CHANGED_EVENT, refresh);
    return () => window.removeEventListener(VISIT_QUEUE_CHANGED_EVENT, refresh);
  }, [employeePublicId]);

  async function submit() {
    setBusy(true);
    const fix = await currentFix(15_000);
    if (!fix) {
      setBusy(false);
      toast.error(
        t(
          "visits.no_location",
          "Your location is needed to log a visit. Allow location and try again.",
        ),
      );
      return;
    }
    const visit: LogVisit = {
      idempotency_key: `visit-${Date.now()}-${Math.random().toString(36).slice(2)}`,
      latitude: fix.latitude,
      longitude: fix.longitude,
      accuracy: fix.accuracy,
      note: note.trim() || null,
      photo,
      ...punchSignals(fix),
    };

    if (!isOnline) {
      const kept = queueVisit(
        { ...visit, occurred_at: new Date().toISOString() },
        employeePublicId,
        tenantHeaderValue(),
      );
      setBusy(false);
      if (!kept) {
        toast.error(
          t("visits.queue_failed", "Couldn't keep the visit on this phone."),
        );
        return;
      }
      toast.success(
        t(
          "visits.saved_offline",
          "Saved on this phone. It will be sent when you are back online.",
        ),
      );
      reset();
      return;
    }

    // A live visit is signed by the registered phone, like a punch.
    const signed = await signPunch();
    if (signed.stop) {
      setBusy(false);
      toast.error(
        t(
          "device.sign_cancelled",
          "Unlock your phone when it asks, to confirm it is you.",
        ),
      );
      return;
    }

    log.mutate(
      { ...visit, ...(signed.proof ? { device_proof: signed.proof } : {}) },
      {
        onSuccess: () => {
          toast.success(t("visits.logged", "Visit logged"));
          reset();
        },
        onError: (err) =>
          toast.error(
            apiErrorDetail(err) ?? t("visits.failed", "Couldn't log the visit"),
          ),
        onSettled: () => setBusy(false),
      },
    );
  }

  function reset() {
    setOpen(false);
    setNote("");
    setPhoto(null);
    setBusy(false);
  }

  const items = visits.data?.data ?? [];

  return (
    <Card>
      <CardContent className="space-y-3 p-4">
        <div className="flex items-center justify-between gap-3">
          <div className="flex items-center gap-2">
            <Route
              className="h-4 w-4 text-muted-foreground"
              aria-hidden="true"
            />
            <p className="text-sm font-medium">{t("visits.title", "Visits")}</p>
          </div>
          <Button size="sm" onClick={() => setOpen(true)}>
            <MapPin className="mr-1 h-4 w-4" aria-hidden="true" />
            {t("visits.log", "Log a visit")}
          </Button>
        </div>
        {queued > 0 && (
          <p className="text-xs text-muted-foreground">
            {t("visits.queued", ":count waiting to be sent", { count: queued })}
          </p>
        )}
        {refused.length > 0 && (
          <section
            aria-labelledby="refused-visits-title"
            className="space-y-2 rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
          >
            <div className="space-y-1">
              <h3 id="refused-visits-title" className="font-medium">
                {t("visits.refused_title", "Visits that were not recorded")}
              </h3>
              <p>
                {t(
                  "attendance.punch.refused_hint",
                  "Ask HR to enter them for you. Remove each one here once you have noted it.",
                )}
              </p>
            </div>
            <ul className="space-y-2">
              {refused.map((v) => {
                const at = loggedAt(v.idempotency_key);
                return (
                  <li
                    key={v.idempotency_key}
                    className="flex items-start justify-between gap-3 rounded-md bg-card px-3 py-2 text-foreground"
                  >
                    <div
                      id={`refused-${v.idempotency_key}`}
                      className="min-w-0"
                    >
                      <p className="font-medium">
                        {v.note ?? t("visits.unnamed", "Visit")}
                        {at && (
                          <>
                            {" "}
                            <span className="tabular-nums">
                              {formatTime(at)}
                            </span>
                          </>
                        )}
                      </p>
                      <p className="text-muted-foreground">
                        {v.refused?.reason ??
                          t(
                            "visits.refused_local",
                            "The server will not accept this visit as it is.",
                          )}
                      </p>
                    </div>
                    <Button
                      size="sm"
                      variant="outline"
                      className="min-h-11 shrink-0"
                      aria-describedby={`refused-${v.idempotency_key}`}
                      onClick={() => dropQueuedVisit(v.idempotency_key)}
                    >
                      {t("common.remove", "Remove")}
                    </Button>
                  </li>
                );
              })}
            </ul>
          </section>
        )}
        {items.length > 0 && (
          <ul className="space-y-1 text-xs text-muted-foreground">
            {items.map((v) => (
              <li key={v.public_id} className="flex justify-between gap-2">
                <span className="truncate">
                  {v.site_name ?? v.note ?? t("visits.unnamed", "Visit")}
                </span>
                <span className="shrink-0 tabular-nums">
                  {formatTime(v.occurred_at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardContent>

      <Dialog
        open={open}
        onOpenChange={(next) => (next ? setOpen(true) : reset())}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("visits.log", "Log a visit")}</DialogTitle>
            <DialogDescription>
              {t(
                "visits.description",
                "Your location and the time are recorded. A visit does not change your hours.",
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <div>
              <Label htmlFor="visit-note">{t("visits.note", "Note")}</Label>
              <Textarea
                id="visit-note"
                maxLength={500}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                placeholder={t(
                  "visits.note_placeholder",
                  "Who or what you visited",
                )}
              />
            </div>
            {photoConsent && (
              <div>
                <Label
                  htmlFor="visit-photo"
                  className="flex items-center gap-2"
                >
                  <Camera className="h-4 w-4" aria-hidden="true" />
                  {photo
                    ? t("visits.photo_added", "Photo added")
                    : t("visits.photo", "Add a photo (optional)")}
                </Label>
                <input
                  id="visit-photo"
                  type="file"
                  accept="image/*"
                  capture="environment"
                  className="mt-1 block w-full text-sm"
                  onChange={async (e) => {
                    const file = e.target.files?.[0];
                    setPhoto(file ? await photoFromFile(file) : null);
                  }}
                />
              </div>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={reset}>
              {t("common.cancel", "Cancel")}
            </Button>
            <Button onClick={() => void submit()} disabled={busy}>
              {busy && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
              {t("visits.save", "Log it")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
