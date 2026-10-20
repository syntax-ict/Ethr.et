"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import {
  AlarmClock,
  Camera,
  CheckCircle2,
  CloudUpload,
  History,
  Loader2,
  LogIn,
  LogOut,
  MapPin,
  MapPinCheck,
  MapPinOff,
  RefreshCw,
  Sparkles,
  WifiOff,
} from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Switch } from "@/components/ui/switch";
import { Skeleton } from "@/components/ui/skeleton";
import { useMobilePunch, useMyShift } from "@/features/attendance/api";
import { useCurrentUser } from "@/features/auth/api";
import {
  useGivePhotoConsent,
  useUpdatePreferences,
} from "@/features/profile/api";
import { PhotoConsentDialog } from "@/features/attendance/components/photo-consent-notice";
import { useOfflineSync } from "@/lib/hooks/useOfflineSync";
import { enqueueOfflineRecord } from "@/lib/offline-queue";
import {
  currentOfflineGrant,
  refreshOfflineGrant,
} from "@/lib/attendance/offline-grant";
import { requestAttendanceSync } from "@/lib/hooks/useServiceWorker";
import { tenantHeaderValue } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { captureSelfie } from "@/lib/uploads/selfie";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import {
  autoCheckInDecision,
  geofenceState,
  type GeofenceState,
  type Position,
} from "@/lib/attendance/punch-assistant";
import { cn } from "@/lib/utils";

type PunchType = "check_in" | "check_out";

interface Done {
  type: PunchType;
  at: Date;
  auto: boolean;
  offline: boolean;
}

function formatDistance(meters: number): string {
  return meters >= 1000
    ? `${(meters / 1000).toFixed(1)} km`
    : `${Math.round(meters)} m`;
}

/**
 * The phone's punch screen.
 *
 * Built around one decision - "am I checking in or out?" - which it answers
 * from the server (`GET /attendance/my-shift`) rather than asking: the
 * screen used to open on a Check in / Check out toggle the employee had to
 * set, with no sign of their shift, their status or whether they stood
 * inside their branch's area. It now shows all three, enforces the
 * organisation's selfie rule (the photo was labelled "optional" whatever the
 * setting said), and offers the assistant: reminders, and an opt-in
 * automatic check-in on arrival.
 */
export default function MobilePunchPage() {
  const { t, locale } = useT();
  const { data: user } = useCurrentUser();
  const hasEmployee = Boolean(user?.employee_public_id);
  const shiftQuery = useMyShift(hasEmployee);
  const shift = shiftQuery.data;
  const { isOnline, pendingCount, syncing, syncNow } = useOfflineSync();
  const punch = useMobilePunch();
  const updatePrefs = useUpdatePreferences();
  const giveConsent = useGivePhotoConsent();
  const [consentOpen, setConsentOpen] = useState(false);

  const [now, setNow] = useState(() => new Date());
  const [position, setPosition] = useState<Position | null>(null);
  const [locating, setLocating] = useState(false);
  const [locationError, setLocationError] = useState("");
  const [photo, setPhoto] = useState<string | null>(null);
  const [cameraOn, setCameraOn] = useState(false);
  const [manualType, setManualType] = useState<PunchType>("check_in");
  const [again, setAgain] = useState(false);
  const [done, setDone] = useState<Done | null>(null);
  const [error, setError] = useState("");
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const autoTried = useRef(false);

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(id);
  }, []);

  // Keep a fresh offline grant while there is signal, so a punch made in a
  // power cut or outage later can be vouched for (see offline-grant.ts).
  const employeePublicId = user?.employee_public_id ?? null;
  useEffect(() => {
    if (!isOnline || !employeePublicId) return;
    void refreshOfflineGrant(employeePublicId);
  }, [isOnline, employeePublicId]);

  useEffect(() => {
    locate();
    return () => stopCamera();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function locate() {
    setLocationError("");
    if (!navigator.geolocation) {
      setLocationError(t("attendance.mobile_page.geolocation_unsupported"));
      return;
    }
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setPosition({
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
          accuracy: pos.coords.accuracy,
        });
        setLocating(false);
      },
      (err) => {
        setLocationError(err.message);
        setLocating(false);
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
    );
  }

  async function startCamera() {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: "user" },
      });
      streamRef.current = stream;
      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        await videoRef.current.play();
      }
      setCameraOn(true);
    } catch {
      toast.error(t("attendance.mobile_page.camera_denied"));
    }
  }

  // The camera opens only for an employee who agreed to the photo notice;
  // the server refuses a selfie from anyone else (PhotoConsent).
  const photoConsent = Boolean(shift?.policy.photo_consent);

  function takeSelfie() {
    if (photoConsent) {
      void startCamera();
      return;
    }
    setConsentOpen(true);
  }

  function agreeToPhoto() {
    giveConsent.mutate(undefined, {
      onSuccess: () => {
        setConsentOpen(false);
        void startCamera();
      },
      onError: (err: unknown) =>
        toast.error(
          apiErrorDetail(err) ??
            t(
              "attendance.photo_consent.save_failed",
              "Your answer could not be saved. Connect to the internet and try again.",
            ),
        ),
    });
  }

  function stopCamera() {
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
    setCameraOn(false);
  }

  function snapPhoto() {
    const video = videoRef.current;
    if (!video) return;
    // At most 1.5 MB (attendance photo policy), stepping quality and size
    // down until it fits; also what a queued offline punch carries.
    setPhoto(captureSelfie(video));
    stopCamera();
  }

  // What the button does: the server's answer when it has one.
  const status = shift?.attendance.status;
  const type: PunchType = shift
    ? status === "checked_in"
      ? "check_out"
      : "check_in"
    : manualType;
  const finishedForToday = status === "checked_out" && !again;
  const photoRequired = Boolean(shift?.policy.photo_required);
  const fence: GeofenceState = shift
    ? geofenceState(shift, position)
    : { kind: "none" };
  const blockedByFence =
    Boolean(shift?.policy.geofence_required) &&
    type === "check_in" &&
    fence.kind === "outside";

  const reason = !position
    ? locationError
      ? t("attendance.mobile_page.location_required_notice")
      : t("attendance.punch.needs_location", "Waiting for your location…")
    : photoRequired && type === "check_in" && !photo
      ? t("attendance.punch.needs_selfie", "Take a selfie to check in.")
      : blockedByFence
        ? t(
            "attendance.punch.needs_area",
            "Move inside your branch area to check in.",
          )
        : null;

  async function submit(auto = false) {
    if (!position) return;
    setError("");
    const idempotencyKey = `mobile-${Date.now()}-${Math.random().toString(36).slice(2)}`;

    if (!isOnline) {
      if (!user?.employee_public_id) {
        setError(t("attendance.mobile_page.cannot_determine_identity"));
        return;
      }
      try {
        await enqueueOfflineRecord({
          employee_public_id: user.employee_public_id,
          type,
          idempotency_key: idempotencyKey,
          // The server's grant, fetched while online, lets it vouch for this
          // punch; without one it is accepted but reviewed.
          offline_token:
            currentOfflineGrant(user.employee_public_id) ??
            `offline-${Date.now()}`,
          latitude: position.latitude,
          longitude: position.longitude,
          accuracy: position.accuracy,
          photo: photo && type === "check_in" ? photo : null,
          captured_at: new Date().toISOString(),
          tenant: tenantHeaderValue(),
          locale,
        });
        // Sent when the connection returns, even with ETHR closed by then
        // (where Background Sync is supported).
        void requestAttendanceSync();
        setDone({ type, at: new Date(), auto: false, offline: true });
      } catch {
        setError(t("attendance.mobile_page.offline_save_failed"));
      }
      return;
    }

    punch.mutate(
      {
        type,
        idempotency_key: idempotencyKey,
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: position.accuracy,
        ...(photo && type === "check_in" ? { photo } : {}),
        ...(auto ? { auto: true } : {}),
      },
      {
        onSuccess: () => {
          setDone({ type, at: new Date(), auto, offline: false });
          setPhoto(null);
          setAgain(false);
        },
        onError: (err: unknown) => {
          // A refused automatic check-in stays quiet: the employee can still
          // tap the button, and an error they did not ask for is noise.
          if (auto) return;
          setError(
            apiErrorDetail(err) ?? t("attendance.mobile_page.submit_failed"),
          );
        },
      },
    );
  }

  // Automatic check-in on arrival: once per visit, only when every condition
  // holds (see autoCheckInDecision); the server checks again.
  useEffect(() => {
    if (!shift || !position || autoTried.current || punch.isPending) return;
    if (!isOnline) return;
    if (!autoCheckInDecision(shift, new Date(), position).ok) return;
    // From a timer, not the effect body: the submission sets state.
    const id = window.setTimeout(() => {
      if (autoTried.current) return;
      autoTried.current = true;
      void submit(true);
    }, 0);
    return () => window.clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [shift, position, isOnline]);

  const zone = shift?.timezone;
  const clock = new Intl.DateTimeFormat(locale, {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
    timeZone: zone,
  }).format(now);
  const dateLine = new Intl.DateTimeFormat(locale, {
    weekday: "long",
    day: "numeric",
    month: "long",
    timeZone: zone,
  }).format(now);
  const timeOf = (iso: string | null | undefined) =>
    iso
      ? new Intl.DateTimeFormat(locale, {
          hour: "2-digit",
          minute: "2-digit",
          hour12: false,
          timeZone: zone,
        }).format(new Date(iso))
      : "";

  if (done) {
    return (
      <div className="mx-auto max-w-md space-y-6 py-6 text-center">
        <span className="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-success-soft text-success-on-soft">
          <CheckCircle2 className="h-10 w-10" aria-hidden="true" />
        </span>
        <div>
          <p className="text-xl font-semibold text-foreground">
            {done.type === "check_in"
              ? t("attendance.checked_in_label")
              : t("attendance.checked_out_label")}{" "}
            <span className="tabular-nums">
              {timeOf(done.at.toISOString())}
            </span>
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            {done.offline
              ? t("attendance.mobile_page.saved_offline_toast")
              : done.auto
                ? t(
                    "attendance.punch.auto_done",
                    "Checked in automatically when you arrived.",
                  )
                : t(
                    "attendance.punch.recorded",
                    "Your attendance is recorded.",
                  )}
          </p>
        </div>
        <div className="flex justify-center gap-2">
          <Button asChild variant="outline">
            <Link href="/attendance">
              <History className="mr-2 h-4 w-4" aria-hidden="true" />
              {t("attendance.punch.history", "My history")}
            </Link>
          </Button>
          <Button asChild>
            <Link href="/dashboard">{t("attendance.punch.done", "Done")}</Link>
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-md space-y-4">
      {!isOnline && (
        <div className="flex items-center gap-2 rounded-lg border border-warning-edge bg-warning-soft px-3 py-2 text-sm text-warning-on-soft">
          <WifiOff className="h-4 w-4 shrink-0" aria-hidden="true" />
          {t("attendance.mobile_page.offline_banner")}
        </div>
      )}
      {pendingCount > 0 && isOnline && (
        <div className="flex items-center justify-between gap-2 rounded-lg border border-border bg-card px-3 py-2 text-sm">
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
            disabled={syncing}
            onClick={() => void syncNow()}
          >
            {syncing && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />}
            {t("attendance.mobile_page.sync_now")}
          </Button>
        </div>
      )}

      {/* Today: the clock, the shift, the status. */}
      <section className="rounded-2xl border border-border bg-card p-5 text-center shadow-xs">
        <p className="text-sm text-muted-foreground">{dateLine}</p>
        <p className="mt-1 text-5xl font-semibold tabular-nums tracking-tight text-foreground">
          {clock}
        </p>

        <div className="mt-3 min-h-6 text-sm">
          {shiftQuery.isLoading && hasEmployee ? (
            <Skeleton className="mx-auto h-5 w-48" />
          ) : shift?.holiday ? (
            <span className="text-muted-foreground">
              {t("attendance.punch.holiday", "Holiday")} ·{" "}
              {locale === "am" && shift.holiday.name_am
                ? shift.holiday.name_am
                : shift.holiday.name}
            </span>
          ) : shift?.on_leave ? (
            <span className="text-muted-foreground">
              {t("attendance.punch.on_leave", "You are on leave today")}
            </span>
          ) : shift?.shift && shift.working_day ? (
            <span className="text-muted-foreground">
              {shift.shift.name} ·{" "}
              <span className="font-medium tabular-nums text-foreground">
                {shift.shift.start_time}–{shift.shift.end_time}
              </span>
            </span>
          ) : shift ? (
            <span className="text-muted-foreground">
              {t("attendance.punch.no_shift", "No shift scheduled today")}
            </span>
          ) : null}
        </div>

        {shift && (
          <div className="mt-3 flex justify-center">
            <Badge
              variant="outline"
              className={cn(
                "border-0 px-2.5 py-1",
                status === "checked_in" &&
                  "bg-success-soft text-success-on-soft",
                status === "checked_out" &&
                  "bg-neutral-soft text-neutral-on-soft",
                status === "not_checked_in" &&
                  "bg-warning-soft text-warning-on-soft",
              )}
            >
              {status === "checked_in"
                ? `${t("attendance.punch.in_since", "Checked in at")} ${timeOf(shift.attendance.check_in)}`
                : status === "checked_out"
                  ? `${t("attendance.punch.out_at", "Checked out at")} ${timeOf(shift.attendance.check_out)}`
                  : t("dashboard.not_checked_in", "Not checked in")}
            </Badge>
          </div>
        )}
        {shift?.attendance.auto_checked_out && (
          <p className="mt-2 text-xs text-muted-foreground">
            {t(
              "attendance.punch.auto_closed_note",
              "Closed automatically at the end of your shift. File a correction if you left at another time.",
            )}
          </p>
        )}
      </section>

      {/* Without the server's answer (offline, or no shift data), the
          employee says which punch this is. */}
      {!shift && (
        <div
          role="radiogroup"
          aria-label={t("attendance.punch.type", "Punch type")}
          className="grid grid-cols-2 gap-1 rounded-xl border border-border bg-muted p-1"
        >
          {(["check_in", "check_out"] as const).map((value) => (
            <button
              key={value}
              type="button"
              role="radio"
              aria-checked={manualType === value}
              onClick={() => setManualType(value)}
              className={cn(
                "rounded-lg py-2 text-sm font-medium transition-colors",
                manualType === value
                  ? "bg-card text-foreground shadow-xs"
                  : "text-muted-foreground",
              )}
            >
              {value === "check_in"
                ? t("common.check_in")
                : t("common.check_out")}
            </button>
          ))}
        </div>
      )}

      {/* The punch. */}
      <section className="flex flex-col items-center gap-3 py-2">
        {finishedForToday ? (
          <>
            <p className="text-sm text-muted-foreground">
              {t("attendance.punch.finished", "You are done for today.")}
            </p>
            <Button variant="outline" onClick={() => setAgain(true)}>
              {t("attendance.punch.check_in_again", "Check in again")}
            </Button>
          </>
        ) : (
          <>
            <button
              type="button"
              onClick={() => void submit(false)}
              disabled={Boolean(reason) || punch.isPending}
              className={cn(
                "flex h-40 w-40 flex-col items-center justify-center gap-2 rounded-full text-lg font-semibold shadow-lg transition-transform focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-ring/40 active:scale-95 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100",
                type === "check_in"
                  ? "bg-primary text-primary-foreground"
                  : "bg-foreground text-background",
              )}
            >
              {punch.isPending ? (
                <Loader2 className="h-8 w-8 animate-spin" aria-hidden="true" />
              ) : type === "check_in" ? (
                <LogIn className="h-8 w-8" aria-hidden="true" />
              ) : (
                <LogOut className="h-8 w-8" aria-hidden="true" />
              )}
              {type === "check_in"
                ? t("common.check_in")
                : t("common.check_out")}
            </button>
            {reason && (
              <p
                className="text-center text-sm text-muted-foreground"
                role="status"
              >
                {reason}
              </p>
            )}
            {error && (
              <p className="text-center text-sm text-destructive" role="alert">
                {error}
              </p>
            )}
          </>
        )}
      </section>

      {/* Where they are. */}
      <section className="flex items-center gap-3 rounded-xl border border-border bg-card p-4 shadow-xs">
        <span
          className={cn(
            "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
            fence.kind === "inside" && "bg-success-soft text-success-on-soft",
            fence.kind === "outside" && "bg-warning-soft text-warning-on-soft",
            fence.kind === "none" && "bg-neutral-soft text-neutral-on-soft",
          )}
        >
          {fence.kind === "inside" ? (
            <MapPinCheck className="h-[18px] w-[18px]" aria-hidden="true" />
          ) : fence.kind === "outside" ? (
            <MapPinOff className="h-[18px] w-[18px]" aria-hidden="true" />
          ) : (
            <MapPin className="h-[18px] w-[18px]" aria-hidden="true" />
          )}
        </span>
        <div className="min-w-0 flex-1 text-sm">
          <p className="font-medium text-foreground">
            {locating
              ? t("attendance.mobile_page.getting_location")
              : locationError
                ? t("attendance.mobile_page.location_required")
                : fence.kind === "inside"
                  ? t("attendance.punch.inside_area", "Inside your branch area")
                  : fence.kind === "outside"
                    ? t(
                        "attendance.punch.outside_area",
                        "Outside your branch area",
                      )
                    : position
                      ? t("attendance.mobile_page.your_location")
                      : t("attendance.mobile_page.location_required")}
          </p>
          <p className="truncate text-xs text-muted-foreground">
            {locationError
              ? locationError
              : position
                ? [
                    fence.kind !== "none"
                      ? `${formatDistance(fence.distance)} ${t("attendance.punch.from_branch", "from your branch")}`
                      : null,
                    `±${Math.round(position.accuracy)} m`,
                  ]
                    .filter(Boolean)
                    .join(" · ")
                : ""}
          </p>
        </div>
        <Button
          variant="ghost"
          size="icon"
          className="h-8 w-8 shrink-0"
          onClick={locate}
          disabled={locating}
          aria-label={t("attendance.mobile_page.refresh_location")}
        >
          <RefreshCw className={cn("h-4 w-4", locating && "animate-spin")} />
        </Button>
      </section>

      {/* The selfie, when it is a check-in. */}
      {type === "check_in" && !finishedForToday && (
        <section className="rounded-xl border border-border bg-card p-4 shadow-xs">
          <div className="flex items-center justify-between gap-2">
            <p className="flex items-center gap-2 text-sm font-medium text-foreground">
              <Camera
                className="h-4 w-4 text-muted-foreground"
                aria-hidden="true"
              />
              {t("attendance.mobile_page.selfie")}
            </p>
            <Badge
              variant="outline"
              className={cn(
                "border-0",
                photoRequired
                  ? "bg-warning-soft text-warning-on-soft"
                  : "bg-neutral-soft text-neutral-on-soft",
              )}
            >
              {photoRequired
                ? t("attendance.punch.required", "Required")
                : t("attendance.mobile_page.optional")}
            </Badge>
          </div>
          <video
            ref={videoRef}
            className={cn(
              "mt-3 aspect-[4/3] w-full rounded-lg bg-muted object-cover",
              !cameraOn && "hidden",
            )}
            playsInline
            muted
          />
          {photo && !cameraOn && (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={photo}
              alt={t("attendance.mobile_page.selfie_preview_alt")}
              className="mt-3 aspect-[4/3] w-full rounded-lg object-cover"
            />
          )}
          <div className="mt-3 flex gap-2">
            {cameraOn ? (
              <Button className="flex-1" onClick={snapPhoto}>
                {t("attendance.mobile_page.capture")}
              </Button>
            ) : (
              <Button variant="outline" className="flex-1" onClick={takeSelfie}>
                {photo
                  ? t("attendance.mobile_page.retake")
                  : t("attendance.punch.take_selfie", "Take a selfie")}
              </Button>
            )}
          </div>
        </section>
      )}

      <PhotoConsentDialog
        open={consentOpen}
        onOpenChange={setConsentOpen}
        onAgree={agreeToPhoto}
        agreeing={giveConsent.isPending}
      />

      {/* The assistant: the employee's own switches. */}
      {shift &&
        (shift.assistant.reminders_allowed ||
          shift.assistant.auto_check_in_allowed) && (
          <AssistantCard
            shift={shift}
            punchReminders={user?.preferences?.punch_reminders ?? true}
            autoCheckIn={user?.preferences?.auto_check_in ?? false}
            saving={updatePrefs.isPending}
            onChange={(change) =>
              updatePrefs.mutate(change, {
                onError: (err) =>
                  toast.error(
                    apiErrorMessage(
                      err,
                      t(
                        "attendance.punch.save_failed",
                        "Couldn't save that. Try again.",
                      ),
                    ),
                  ),
              })
            }
          />
        )}

      <div className="flex justify-center gap-4 pb-2 text-sm">
        <Link href="/attendance" className="text-primary hover:underline">
          {t("attendance.punch.history", "My history")}
        </Link>
        <Link
          href="/attendance/corrections"
          className="text-primary hover:underline"
        >
          {t("nav.corrections", "Corrections")}
        </Link>
      </div>
    </div>
  );
}

function AssistantCard({
  shift,
  punchReminders,
  autoCheckIn,
  saving,
  onChange,
}: {
  shift: NonNullable<ReturnType<typeof useMyShift>["data"]>;
  punchReminders: boolean;
  autoCheckIn: boolean;
  saving: boolean;
  onChange: (change: {
    punch_reminders?: boolean;
    auto_check_in?: boolean;
  }) => void;
}) {
  const { t } = useT();
  const [permission, setPermission] = useState<
    NotificationPermission | "unsupported"
  >(() =>
    typeof Notification === "undefined"
      ? "unsupported"
      : Notification.permission,
  );

  async function enableReminders(on: boolean) {
    onChange({ punch_reminders: on });
    // Asked only when turning reminders on, from a tap - browsers ignore or
    // penalise a permission prompt that appears on its own.
    if (
      on &&
      typeof Notification !== "undefined" &&
      Notification.permission === "default"
    ) {
      setPermission(await Notification.requestPermission());
    }
  }

  return (
    <section className="space-y-3 rounded-xl border border-border bg-card p-4 shadow-xs">
      <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
        <Sparkles
          className="h-4 w-4 text-muted-foreground"
          aria-hidden="true"
        />
        {t("attendance.punch.assistant", "Punch assistant")}
      </p>

      {shift.assistant.reminders_allowed && (
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="flex items-center gap-1.5 text-sm font-medium text-foreground">
              <AlarmClock
                className="h-3.5 w-3.5 text-muted-foreground"
                aria-hidden="true"
              />
              {t("attendance.punch.reminders", "Shift reminders")}
            </p>
            <p className="text-xs text-muted-foreground">
              {t(
                "attendance.punch.reminders_desc",
                ":minutes min before your shift, and at its end.",
                {
                  minutes: shift.assistant.reminder_lead_minutes,
                },
              )}
            </p>
            {punchReminders && permission === "denied" && (
              <p className="mt-1 text-xs text-warning-on-soft">
                {t(
                  "attendance.punch.notifications_blocked",
                  "Notifications are blocked for ETHR in your browser settings; reminders show only while ETHR is open.",
                )}
              </p>
            )}
          </div>
          <Switch
            checked={punchReminders}
            disabled={saving}
            onCheckedChange={(on) => void enableReminders(on)}
            aria-label={t("attendance.punch.reminders", "Shift reminders")}
          />
        </div>
      )}

      {shift.assistant.auto_check_in_allowed && (
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-sm font-medium text-foreground">
              {t("attendance.punch.auto_check_in", "Check me in on arrival")}
            </p>
            <p className="text-xs text-muted-foreground">
              {shift.policy.photo_required
                ? t(
                    "attendance.punch.auto_needs_selfie",
                    "Not available while a selfie is required to check in.",
                  )
                : t(
                    "attendance.punch.auto_check_in_desc",
                    "When you open ETHR inside your branch area, up to an hour before your shift.",
                  )}
            </p>
          </div>
          <Switch
            checked={autoCheckIn}
            disabled={saving || shift.policy.photo_required}
            onCheckedChange={(on) => onChange({ auto_check_in: on })}
            aria-label={t(
              "attendance.punch.auto_check_in",
              "Check me in on arrival",
            )}
          />
        </div>
      )}
    </section>
  );
}
