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
  dropQueuedVisit,
  queueVisit,
  queuedVisits,
} from "@/lib/attendance/visit-queue";
import { apiErrorDetail } from "@/lib/api/error-message";
import { useOfflineStatus } from "@/lib/hooks/useOfflineStatus";
import { fitSelfie } from "@/lib/uploads/selfie";
import { useT } from "@/lib/i18n/useT";

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
  const { isOnline } = useOfflineStatus();
  const log = useLogVisit();
  const { data: deviceState } = useAttendanceDevice();
  const signPunch = usePunchSigner(deviceState);
  const visits = useMyVisits(isOnline);
  const [open, setOpen] = useState(false);
  const [note, setNote] = useState("");
  const [photo, setPhoto] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [queued, setQueued] = useState(() =>
    typeof window === "undefined" ? 0 : queuedVisits().length,
  );

  // Send what was logged offline as soon as there is a connection.
  useEffect(() => {
    if (!isOnline) return;
    let cancelled = false;
    void (async () => {
      for (const visit of queuedVisits()) {
        if (cancelled) return;
        try {
          await log.mutateAsync(visit);
          dropQueuedVisit(visit.idempotency_key);
        } catch {
          // Refused for good (too old, method off): keep it out of the way.
          dropQueuedVisit(visit.idempotency_key);
        }
      }
      if (!cancelled) setQueued(queuedVisits().length);
    })();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOnline]);

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
      const kept = queueVisit({
        ...visit,
        occurred_at: new Date().toISOString(),
      });
      setBusy(false);
      if (!kept) {
        toast.error(
          t("visits.queue_failed", "Couldn't keep the visit on this phone."),
        );
        return;
      }
      setQueued(queuedVisits().length);
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
        {items.length > 0 && (
          <ul className="space-y-1 text-xs text-muted-foreground">
            {items.map((v) => (
              <li key={v.public_id} className="flex justify-between gap-2">
                <span className="truncate">
                  {v.site_name ?? v.note ?? t("visits.unnamed", "Visit")}
                </span>
                <span className="shrink-0 tabular-nums">
                  {new Date(v.occurred_at).toLocaleTimeString([], {
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
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
