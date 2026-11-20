"use client";

import {
  checkLiveness,
  randomPrompt,
  type LivenessPrompt,
  type LivenessResult,
} from "@/lib/attendance/liveness";
import { PushToggle } from "@/features/notifications/components/push-toggle";
import { VisitLogCard } from "@/features/attendance/components/visit-log-card";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { AlarmClock, Sparkles, UserX, WifiOff } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Switch } from "@/components/ui/switch";
import { EmptyState } from "@/components/shared/empty-state";
import { DayStrip } from "@/features/attendance/components/mobile/day-strip";
import { PunchButton } from "@/features/attendance/components/mobile/punch-button";
import { PresenceCard } from "@/features/attendance/components/mobile/presence-card";
import { PunchConfirmation } from "@/features/attendance/components/mobile/punch-confirmation";
import { SyncStatus } from "@/features/attendance/components/mobile/sync-status";
import {
  ReadinessList,
  type LocationProblem,
} from "@/features/attendance/components/mobile/readiness-list";
import { fixIsOld } from "@/lib/attendance/fix-age";
import { haptic } from "@/lib/attendance/haptics";
import { isNoAnswer } from "@/lib/attendance/no-answer";
import { InstallAppBanner } from "@/components/shared/install-app";
import {
  usePunchSigner,
  useAttendanceDevice,
  useMobilePunch,
  useMyShift,
} from "@/features/attendance/api";
import {
  DeviceCard,
  usePhoneSettled,
} from "@/features/attendance/components/device-card";
import { DayNote } from "@/features/attendance/components/day-note";
import {
  punchOutcomeText,
  type PunchOutcomeInfo,
} from "@/features/attendance/punch-outcome";
import { fixedCoordinate, signOfflinePunch } from "@/lib/attendance/passkey";
import { useCurrentUser } from "@/features/auth/api";
import {
  useGivePhotoConsent,
  useUpdatePreferences,
} from "@/features/profile/api";
import { PhotoConsentDialog } from "@/features/attendance/components/photo-consent-notice";
import { useOfflineSync } from "@/lib/hooks/useOfflineSync";
import {
  enqueueOfflineRecord,
  type OfflineAttendanceRecord,
} from "@/lib/offline-queue";
import { offlinePunchWouldBeRefused } from "@/features/attendance/offline-binding";
import { RefusedPunches } from "@/features/attendance/components/mobile/refused-punches";
import {
  fixFrom,
  punchSignals,
  type Fix,
} from "@/lib/attendance/punch-signals";
import {
  currentOfflineDevice,
  currentOfflineGrant,
  refreshOfflineGrant,
} from "@/lib/attendance/offline-grant";
import { requestAttendanceSync } from "@/lib/hooks/useServiceWorker";
import { tenantHeaderValue } from "@/api/client";
import { useT } from "@/lib/i18n/useT";
import { useDateFormatters } from "@/lib/hooks/useTenantTimezone";
import {
  formatCalendarDayMonth,
  formatCalendarWeekday,
  formatClockTime,
} from "@/lib/utils/date";
import { captureSelfie } from "@/lib/uploads/selfie";
import { apiErrorDetail, apiErrorMessage } from "@/lib/api/error-message";
import {
  autoCheckInDecision,
  geofenceState,
  type GeofenceState,
} from "@/lib/attendance/punch-assistant";
import { cn } from "@/lib/utils";

// quiet-on-error: the punch screen is offline-first and must work with no signal; today's shift line is advisory, so a failed lookup leaves it blank rather than putting an error between a person and the punch button.

type PunchType = "check_in" | "check_out";

const NO_REFUSED: OfflineAttendanceRecord[] = [];

/** A punch that has been asked for and not yet answered or saved. */
interface Attempt {
  type: PunchType;
  key: string;
  tappedAt: string;
}

interface Done {
  type: PunchType;
  at: Date;
  auto: boolean;
  offline: boolean;
  /** What the server said the punch came to; absent for one saved offline. */
  outcome?: PunchOutcomeInfo | null;
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
  const {
    timeZone,
    calendar,
    clock: readerClock,
    formatTimeOfDay,
  } = useDateFormatters();
  const { data: user, isLoading: userLoading } = useCurrentUser();
  const hasEmployee = Boolean(user?.employee_public_id);
  const shiftQuery = useMyShift(hasEmployee);
  const shift = shiftQuery.data;
  // Only this person's saved punches: a shared phone may hold others'.
  const {
    isOnline,
    pendingCount,
    refused = NO_REFUSED,
    syncing,
    syncNow,
    removeRefused,
  } = useOfflineSync(user?.employee_public_id ?? null);
  const punch = useMobilePunch();
  const deviceQuery = useAttendanceDevice(hasEmployee);
  const deviceState = deviceQuery.data;
  const hasRegisteredPhone = Boolean(deviceState?.device);
  const signPunch = usePunchSigner(deviceState);
  const updatePrefs = useUpdatePreferences();
  const giveConsent = useGivePhotoConsent();
  const [consentOpen, setConsentOpen] = useState(false);

  const [now, setNow] = useState(() => new Date());
  const [position, setPosition] = useState<Fix | null>(null);
  const [locating, setLocating] = useState(false);
  const [locationProblem, setLocationProblem] =
    useState<LocationProblem | null>(null);
  const [photo, setPhoto] = useState<string | null>(null);
  const [cameraOn, setCameraOn] = useState(false);
  const [cameraProblem, setCameraProblem] = useState(false);
  const [phase, setPhase] = useState<"idle" | "confirming" | "sending">("idle");
  const phoneSettled = usePhoneSettled(hasEmployee);
  // Read by timers and listeners that must not re-subscribe every second.
  const positionRef = useRef<Fix | null>(null);
  const locatingRef = useRef(false);
  const submitting = useRef(false);
  // Which submit is current: a late answer to an older one is ignored.
  const attempt = useRef(0);
  // The key and tap time of the punch in flight, for the slow-send offer.
  const inFlight = useRef<Attempt | null>(null);
  const [unsent, setUnsent] = useState<Attempt | null>(null);
  const [slowAttempt, setSlowAttempt] = useState<Attempt | null>(null);
  const [flashKey, setFlashKey] = useState(0);
  const [manualType, setManualType] = useState<PunchType>("check_in");
  const [again, setAgain] = useState(false);
  // Goes up on each tap of a button that cannot punch yet.
  const [nudged, setNudged] = useState<{
    key: number;
    row: "location" | "selfie" | null;
  }>({ key: 0, row: null });
  const [done, setDone] = useState<Done | null>(null);
  const [error, setError] = useState("");
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const autoTried = useRef(false);
  const [pageVisible, setPageVisible] = useState(true);

  useEffect(() => {
    const id = window.setInterval(() => setNow(new Date()), 1000);
    return () => window.clearInterval(id);
  }, []);

  // Keep a fresh offline grant while there is signal, so a punch made in a
  // power cut or outage later can be vouched for (see offline-grant.ts).
  const employeePublicId = user?.employee_public_id ?? null;
  useEffect(() => {
    if (!isOnline || !employeePublicId) return;
    void refreshOfflineGrant(employeePublicId, hasRegisteredPhone);
  }, [isOnline, employeePublicId, hasRegisteredPhone]);

  useEffect(() => {
    locate();
    return () => stopCamera();
  }, []);

  // The server scores a fix older than 120 seconds as stale, so the screen
  // takes a new one once the last is 60 seconds old: when the page comes back
  // to the front, and every 30 seconds while it is in view. A page in the
  // background also lets go of the camera.
  useEffect(() => {
    const stale = () => fixIsOld(positionRef.current, Date.now());
    const onVisibility = () => {
      if (document.visibilityState === "hidden") {
        stopCamera();
        return;
      }
      if (stale()) locate();
    };
    const id = window.setInterval(() => {
      if (document.visibilityState === "visible" && stale()) locate();
    }, 30_000);
    document.addEventListener("visibilitychange", onVisibility);
    return () => {
      window.clearInterval(id);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, []);

  useEffect(() => {
    const sync = () => setPageVisible(document.visibilityState !== "hidden");
    sync();
    document.addEventListener("visibilitychange", sync);
    return () => document.removeEventListener("visibilitychange", sync);
  }, []);

  function locate() {
    if (locatingRef.current) return;
    setLocationProblem(null);
    if (!navigator.geolocation) {
      setLocationProblem("unsupported");
      return;
    }
    locatingRef.current = true;
    setLocating(true);
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const fix = fixFrom(pos);
        positionRef.current = fix;
        setPosition(fix);
        locatingRef.current = false;
        setLocating(false);
      },
      (err) => {
        // A refresh that fails keeps the fix already held; the problem is
        // told only to a person who has none.
        if (!positionRef.current) {
          setLocationProblem(err.code === 1 ? "denied" : "unavailable");
        }
        locatingRef.current = false;
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
      setCameraProblem(false);
      setCameraOn(true);
    } catch {
      setCameraProblem(true);
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
    setFlashKey((k) => k + 1);
    stopCamera();
  }

  // On-device liveness, where the organisation turned it on: a random blink
  // or head turn before the photo. The outcome goes with the punch; a failed
  // check never blocks it (the reviewer sees it), and a phone that cannot run
  // the check sends "unavailable", which counts for nothing.
  const livenessRequired = Boolean(shift?.policy.liveness_required);
  const [livenessPrompt, setLivenessPrompt] = useState<LivenessPrompt | null>(
    null,
  );
  const [liveness, setLiveness] = useState<LivenessResult | null>(null);

  async function checkThenSnap() {
    const video = videoRef.current;
    if (!video) return;
    const prompt = randomPrompt();
    setLivenessPrompt(prompt);
    const result = await checkLiveness(video, prompt);
    setLivenessPrompt(null);
    setLiveness(result);
    if (result === "failed" || result === "no_face") {
      toast.error(
        t(
          "liveness.retry",
          "We could not confirm a live face. Face the camera in good light and try again.",
        ),
      );
    }
    snapPhoto();
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

  // Why the button cannot be pressed. "Waiting" is only that the location, or
  // an employee's shift lookup, has not answered yet; every other reason is a
  // named one the person can act on.
  // Who this is, and what their shift is, are still being asked. Offline with
  // nothing cached neither query is loading, so a punch can still be saved.
  // Capped at 4 seconds: on a weak connection a person must still be able to
  // punch, as they could before the lookups were waited on.
  const [shiftWaitOver, setShiftWaitOver] = useState(false);
  const lookingUp = userLoading || (shiftQuery.isLoading && hasEmployee);
  useEffect(() => {
    if (!lookingUp) return;
    const id = window.setTimeout(() => setShiftWaitOver(true), 4000);
    return () => window.clearTimeout(id);
  }, [lookingUp]);
  const shiftLoading = lookingUp && !shiftWaitOver;
  const waiting =
    (!position && !locationProblem) || (Boolean(position) && shiftLoading);
  const reason = !position
    ? locationProblem
      ? t("attendance.mobile_page.location_required_notice")
      : t("attendance.punch.needs_location", "Waiting for your location…")
    : shiftLoading
      ? t("attendance.punch.checking_shift", "Checking your shift…")
      : photoRequired && type === "check_in" && !photo
        ? t("attendance.punch.needs_selfie", "Take a selfie to check in.")
        : blockedByFence
          ? t(
              "attendance.punch.needs_area",
              "Move inside your branch area to check in.",
            )
          : null;
  // Which row of the readiness list fixes the reason: the location row when
  // there is no fix or the fence holds the button, the selfie row when the
  // photo is missing, none while the shift lookup is what is being waited on.
  const fixRow: "location" | "selfie" | null = !position
    ? "location"
    : shiftLoading
      ? null
      : photoRequired && type === "check_in" && !photo
        ? "selfie"
        : blockedByFence
          ? "location"
          : null;
  const buttonState =
    phase !== "idle" || punch.isPending
      ? "busy"
      : waiting
        ? "waiting"
        : reason
          ? "blocked"
          : "ready";

  // Live position, only while it matters: the page is in view and either no
  // fix has come yet or the branch fence is holding the button. The distance
  // in the location row then counts down as the person walks, and the button
  // unlocks itself. Each fix takes the path of locate()'s success.
  const watchingPosition =
    pageVisible && ((!position && !locationProblem) || blockedByFence);
  useEffect(() => {
    if (!watchingPosition) return;
    if (typeof navigator === "undefined") return;
    if (!navigator.geolocation?.watchPosition) return;
    const id = navigator.geolocation.watchPosition(
      (pos) => {
        const fix = fixFrom(pos);
        positionRef.current = fix;
        setPosition(fix);
      },
      (err) => {
        if (!positionRef.current) {
          setLocationProblem(err.code === 1 ? "denied" : "unavailable");
        }
      },
      { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 },
    );
    return () => navigator.geolocation?.clearWatch?.(id);
  }, [watchingPosition]);

  // A tap on the button while it is waiting or blocked: answer it, send nothing.
  // A tap is a user gesture, so a refused location is asked for again here.
  function explainBlocked() {
    haptic("refused");
    setNudged((prev) => ({ key: prev.key + 1, row: fixRow }));
    if (!positionRef.current && locationProblem && !locatingRef.current) {
      locate();
    }
  }

  async function submit(
    auto = false,
    opts: {
      key?: string;
      capturedAt?: string;
      forceOffline?: boolean;
      type?: PunchType;
    } = {},
  ) {
    // The moment of the tap, taken before anything is awaited.
    const tappedAt = opts.capturedAt ?? new Date().toISOString();
    // A retry is the same punch as the first tap, whatever the day now says.
    const punchType = opts.type ?? type;
    if (!position) return;
    // One punch in flight at a time. Set before any await, so a second tap
    // in the same frame finds it held.
    if (submitting.current) return;
    submitting.current = true;
    attempt.current += 1;
    const mine = attempt.current;
    setPhase("confirming");
    setUnsent(null);
    setSlowAttempt(null);
    if (!auto) haptic("tap");
    const release = () => {
      // An attempt that was given up on must not free the one that replaced it.
      if (attempt.current !== mine) return;
      submitting.current = false;
      inFlight.current = null;
      setPhase("idle");
    };
    const refuse = (message: string) => {
      setError(message);
      if (!auto) haptic("refused");
    };
    setError("");
    const idempotencyKey =
      opts.key ?? `mobile-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    inFlight.current = { type: punchType, key: idempotencyKey, tappedAt };
    let handedOff = false;

    try {
      if (!isOnline || opts.forceOffline) {
        if (!user?.employee_public_id) {
          refuse(t("attendance.mobile_page.cannot_determine_identity"));
          return;
        }
        // Signed on the registered phone over the punch's own fields, so it
        // cannot be edited before it is sent (passkey.ts).
        const capturedAt = tappedAt;
        const latitude = fixedCoordinate(position.latitude);
        const longitude = fixedCoordinate(position.longitude);
        const offlineDevice = currentOfflineDevice(user.employee_public_id);
        // The server accepts an offline punch under an enforced rule only
        // with the registered phone's signature, which needs a grant naming
        // the phone. Without one it would refuse the punch at sync, so do not
        // save it and promise it.
        if (offlinePunchWouldBeRefused(deviceState, Boolean(offlineDevice))) {
          refuse(
            t(
              "attendance.punch.cannot_confirm_offline",
              "This phone cannot confirm a punch without a connection right now. Connect to the internet and try again, or ask HR to enter this punch.",
            ),
          );
          return;
        }
        let offlineProof: unknown = null;
        if (offlineDevice) {
          try {
            offlineProof = await signOfflinePunch(offlineDevice, {
              idempotency_key: idempotencyKey,
              type: punchType,
              captured_at: capturedAt,
              latitude,
              longitude,
            });
          } catch {
            if (deviceState?.binding === "enforce") {
              refuse(
                t(
                  "device.sign_cancelled",
                  "Unlock your phone when it asks, to confirm it is you.",
                ),
              );
              return;
            }
          }
        }
        setPhase("sending");
        try {
          await enqueueOfflineRecord({
            employee_public_id: user.employee_public_id,
            type: punchType,
            idempotency_key: idempotencyKey,
            // The server's grant, fetched while online, lets it vouch for this
            // punch; without one it is accepted but reviewed.
            offline_token:
              currentOfflineGrant(user.employee_public_id) ??
              `offline-${Date.now()}`,
            latitude,
            longitude,
            accuracy: position.accuracy,
            photo: photo ?? null,
            captured_at: capturedAt,
            ...punchSignals(position),
            liveness: photo && liveness ? liveness : null,
            device_proof: offlineProof,
            tenant: tenantHeaderValue(),
            locale,
          });
          // Sent when the connection returns, even with ETHR closed by then
          // (where Background Sync is supported).
          void requestAttendanceSync();
          haptic("success");
          setDone({
            type: punchType,
            at: new Date(),
            auto: false,
            offline: true,
          });
        } catch {
          refuse(t("attendance.mobile_page.offline_save_failed"));
        }
        return;
      }

      // The registered phone's signature. An automatic punch has no tap, and a
      // passkey can only be used after one, so it goes unsigned.
      const signed = auto ? { stop: false } : await signPunch();
      if (signed.stop) {
        refuse(
          t(
            "device.sign_cancelled",
            "Unlock your phone when it asks, to confirm it is you.",
          ),
        );
        return;
      }
      const proof = "proof" in signed ? signed.proof : undefined;

      setPhase("sending");
      handedOff = true;
      punch.mutate(
        {
          type: punchType,
          idempotency_key: idempotencyKey,
          latitude: position.latitude,
          longitude: position.longitude,
          accuracy: position.accuracy,
          ...punchSignals(position),
          ...(proof ? { device_proof: proof } : {}),
          ...(photo && liveness ? { liveness } : {}),
          // The selfie goes with the check-out too: until 2026-10-24 only the
          // check-in carried one, so a check-out was never evidenced.
          ...(photo ? { photo } : {}),
          ...(auto ? { auto: true } : {}),
        },
        {
          onSuccess: (punched) => {
            if (mine !== attempt.current) return;
            setUnsent(null);
            haptic("success");
            setDone({
              type: punchType,
              at: new Date(),
              auto,
              offline: false,
              outcome: punched.outcome,
            });
            setPhoto(null);
            setAgain(false);
          },
          onError: (err: unknown) => {
            if (mine !== attempt.current) return;
            // A refused automatic check-in stays quiet: the employee can still
            // tap the button, and an error they did not ask for is noise.
            if (auto) return;
            // No answer is not a refusal: the punch may or may not have
            // arrived, so the person is offered the two safe ways on, both
            // under the same key.
            if (isNoAnswer(err)) {
              setUnsent({ type: punchType, key: idempotencyKey, tappedAt });
              // The request may have arrived: ask what the day holds.
              void shiftQuery.refetch();
              haptic("refused");
              return;
            }
            refuse(
              apiErrorDetail(err) ?? t("attendance.mobile_page.submit_failed"),
            );
          },
          onSettled: () => {
            if (mine !== attempt.current) return;
            setSlowAttempt(null);
            release();
          },
        },
      );
    } finally {
      if (!handedOff) release();
    }
  }

  // If the punch did arrive after all, the day says so: stop saying it did not.
  const landed = (a: Attempt | null) =>
    a !== null &&
    ((a.type === "check_in" && status === "checked_in") ||
      (a.type === "check_out" && status === "checked_out"));
  const unsentShown = landed(unsent) ? null : unsent;
  const slowShown = landed(slowAttempt) ? null : slowAttempt;

  // Both offers appear below the first screen, behind the fixed bottom
  // navigation: bring each into view when it shows (jsdom has no
  // scrollIntoView, hence the optional call).
  const unsentRef = useRef<HTMLDivElement | null>(null);
  const slowRef = useRef<HTMLDivElement | null>(null);
  const unsentVisible = unsentShown !== null;
  const slowVisible = slowShown !== null && !unsentVisible;
  useEffect(() => {
    if (unsentVisible) {
      unsentRef.current?.scrollIntoView?.({
        block: "nearest",
        behavior: "smooth",
      });
    }
  }, [unsentVisible]);
  useEffect(() => {
    if (slowVisible) {
      slowRef.current?.scrollIntoView?.({
        block: "nearest",
        behavior: "smooth",
      });
    }
  }, [slowVisible]);

  // A punch saved on the phone has been sent: the server's day has changed,
  // so ask for it again rather than show the day as it was before.
  const sawPending = useRef(false);
  useEffect(() => {
    if (pendingCount > 0) {
      sawPending.current = true;
      return;
    }
    if (sawPending.current && isOnline) {
      sawPending.current = false;
      void shiftQuery.refetch();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingCount, isOnline]);

  // Saving on the phone needs to know who the person is, and the
  // organisation to allow punches made without a connection.
  const canSaveHere =
    shift?.policy.offline_sync_enabled !== false &&
    Boolean(user?.employee_public_id) &&
    !offlinePunchWouldBeRefused(
      deviceState,
      Boolean(
        user?.employee_public_id &&
        currentOfflineDevice(user.employee_public_id),
      ),
    );

  // From a tap on the slow-send offer: give up on the request in flight (its
  // answer, if one comes, is ignored; the copy saved here carries the same
  // key) and save the punch on the phone, from this tap.
  function saveSlowHere() {
    const slow = slowAttempt;
    if (!slow) return;
    attempt.current += 1;
    submitting.current = false;
    inFlight.current = null;
    setPhase("idle");
    setSlowAttempt(null);
    void submit(false, {
      key: slow.key,
      capturedAt: slow.tappedAt,
      forceOffline: true,
      type: slow.type,
    });
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

  // The shift's zone when the server sent one, else the tenant's; the reader's
  // clock and calendar either way.
  const zone = shift?.timezone ?? timeZone;
  const nowIso = now.toISOString();
  const clock = formatClockTime(nowIso, zone, readerClock, locale, {
    seconds: true,
  });
  const dateLine = `${formatCalendarWeekday(nowIso, zone, locale, "long")}, ${formatCalendarDayMonth(nowIso, zone, calendar, locale)}`;
  const timeOf = (iso: string | null | undefined) =>
    iso ? formatClockTime(iso, zone, readerClock, locale) : "";

  // What the server said the punch came to: late, early, or already on
  // record from another method. A punch saved offline has no answer yet.
  const outcome = done ? punchOutcomeText(done.outcome, t, timeOf) : null;
  const already = outcome?.already === true;
  if (user && !user.employee_public_id) {
    // The server refuses a phone punch from an account with no employee
    // record (MobileAttendanceController, 404), so say so instead of showing
    // a button that cannot work.
    return (
      <div className="mx-auto max-w-md">
        <EmptyState
          icon={UserX}
          title={t(
            "attendance.punch.no_employee_title",
            "This account has no employee record",
          )}
          description={t(
            "attendance.punch.no_employee_body",
            "Phone check-in is for employees. Ask HR to link this account to your employee record.",
          )}
          action={
            <Button asChild className="min-h-11">
              <Link href="/dashboard">{t("nav.dashboard", "Dashboard")}</Link>
            </Button>
          }
        />
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-md space-y-4">
      {hasEmployee && !phoneSettled && <DeviceCard />}
      {shift && !shift.policy.mobile_enabled && (
        <div
          role="status"
          className="rounded-lg border border-warning-edge bg-warning-soft px-3 py-2 text-sm text-warning-on-soft"
        >
          {t(
            "attendance.punch.mobile_off",
            "Phone check-in is turned off where you work. Use the device, kiosk or web check-in your organisation provides.",
          )}
        </div>
      )}
      {!isOnline && (
        <div className="animate-fade-in space-y-1 rounded-lg border border-warning-edge bg-warning-soft px-3 py-2 text-sm text-warning-on-soft">
          <p className="flex items-center gap-2">
            <WifiOff className="h-4 w-4 shrink-0" aria-hidden="true" />
            {t("attendance.mobile_page.offline_banner")}
          </p>
          {pendingCount > 0 && (
            <p>
              {pendingCount === 1
                ? t(
                    "attendance.punch.saved_here_one",
                    ":count punch saved on this phone.",
                    { count: pendingCount },
                  )
                : t(
                    "attendance.punch.saved_here_many",
                    ":count punches saved on this phone.",
                    { count: pendingCount },
                  )}
            </p>
          )}
        </div>
      )}
      <SyncStatus
        isOnline={isOnline}
        pendingCount={pendingCount}
        syncing={syncing}
        onSync={() => void syncNow()}
      />
      <RefusedPunches
        records={refused}
        whenOf={(iso) =>
          `${formatCalendarDayMonth(iso, zone, calendar, locale)} ${timeOf(iso)}`
        }
        onRemove={(id) => void removeRefused?.(id)}
      />

      {/* A holiday, the weekend, a day off or leave: said before the clock. */}
      <DayNote day={shift?.day} />

      {/* Today: the date and clock, the status, the shift. */}
      <DayStrip
        dateLine={dateLine}
        clock={clock}
        shift={shift}
        shiftLoading={shiftLoading}
        timeOf={timeOf}
        formatTimeOfDay={formatTimeOfDay}
        now={now}
        pendingPunch={
          done && !already ? { type: done.type, at: done.at } : null
        }
      />

      {/* A presence check waiting for an answer, above the punch button. */}
      {shift?.presence?.enabled && (
        <PresenceCard
          pending={shift.presence.pending}
          position={position}
          locating={locating}
          problem={locationProblem}
          onLocate={locate}
          sign={signPunch}
          isOnline={isOnline}
        />
      )}

      {done && (
        <PunchConfirmation
          tone={outcome?.tone ?? "success"}
          title={
            outcome ? (
              outcome.title
            ) : (
              <>
                {done.type === "check_in"
                  ? t("attendance.checked_in_label")
                  : t("attendance.checked_out_label")}{" "}
                <span className="tabular-nums">
                  {timeOf(done.at.toISOString())}
                </span>
              </>
            )
          }
          hint={
            outcome?.hint ??
            (done.offline
              ? t("attendance.mobile_page.saved_offline_toast")
              : done.auto
                ? t(
                    "attendance.punch.auto_done",
                    "Checked in automatically when you arrived.",
                  )
                : t(
                    "attendance.punch.recorded",
                    "Your attendance is recorded.",
                  ))
          }
        />
      )}

      {/* Without the server's answer (offline, or no shift data), the
          employee says which punch this is. */}
      {!done && !shift && !shiftLoading && (
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
                "min-h-11 rounded-lg text-sm font-medium transition-colors",
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
      {!done && (
        <section className="flex flex-col items-center gap-3 py-2">
          {finishedForToday ? (
            <>
              <p className="text-sm text-muted-foreground">
                {t("attendance.punch.finished", "You are done for today.")}
              </p>
              <Button
                variant="outline"
                className="min-h-11"
                onClick={() => setAgain(true)}
              >
                {t("attendance.punch.check_in_again", "Check in again")}
              </Button>
            </>
          ) : (
            <>
              <PunchButton
                type={type}
                state={buttonState}
                phase={phase}
                reason={reason}
                onPress={() => {
                  if (buttonState === "ready") void submit(false);
                }}
                onBlockedPress={explainBlocked}
                nudgeKey={nudged.key}
                onSlow={() => {
                  if (inFlight.current) setSlowAttempt(inFlight.current);
                }}
                label={
                  type === "check_in"
                    ? t("common.check_in")
                    : t("common.check_out")
                }
              />
              {error && !unsentShown && (
                <p
                  className="text-center text-sm text-destructive"
                  role="alert"
                >
                  {error}
                </p>
              )}
              {unsentShown && (
                <div
                  ref={unsentRef}
                  role="alert"
                  className="w-full scroll-mb-24 space-y-3 rounded-lg border border-warning-edge bg-warning-soft p-3 text-sm text-warning-on-soft"
                >
                  <p>
                    {t(
                      "attendance.punch.no_answer",
                      "No answer from the server. Your :type at :time is not recorded yet.",
                      {
                        type:
                          unsentShown.type === "check_in"
                            ? t("common.check_in")
                            : t("common.check_out"),
                        time: timeOf(unsentShown.tappedAt),
                      },
                    )}
                  </p>
                  <div className="flex flex-col gap-2">
                    {canSaveHere && (
                      <Button
                        className="min-h-11"
                        onClick={() =>
                          void submit(false, {
                            key: unsentShown.key,
                            capturedAt: unsentShown.tappedAt,
                            forceOffline: true,
                            type: unsentShown.type,
                          })
                        }
                      >
                        {t("attendance.punch.save_here", "Save on this phone")}
                      </Button>
                    )}
                    <Button
                      variant="outline"
                      className="min-h-11"
                      onClick={() =>
                        void submit(false, {
                          key: unsentShown.key,
                          type: unsentShown.type,
                        })
                      }
                    >
                      {t("attendance.punch.try_again", "Try again")}
                    </Button>
                  </div>
                  {canSaveHere && (
                    <p className="text-xs">
                      {t(
                        "attendance.punch.save_here_hint",
                        "Saved punches are sent when the connection returns.",
                      )}
                    </p>
                  )}
                </div>
              )}
              {!unsentShown && slowShown && canSaveHere && (
                <div
                  ref={slowRef}
                  className="scroll-mb-24 space-y-1 text-center"
                >
                  <Button
                    variant="outline"
                    className="min-h-11"
                    onClick={saveSlowHere}
                  >
                    {t("attendance.punch.save_here", "Save on this phone")}
                  </Button>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      "attendance.punch.save_here_hint",
                      "Saved punches are sent when the connection returns.",
                    )}
                  </p>
                </div>
              )}
            </>
          )}
        </section>
      )}

      {/* What the punch needs: where they are, the selfie, this phone. */}
      {!done && (
        <ReadinessList
          position={position}
          fence={fence}
          problem={locationProblem}
          locating={locating}
          shift={shift}
          onLocate={locate}
          videoRef={videoRef}
          attention={nudged.row ? { row: nudged.row, key: nudged.key } : null}
          selfie={{
            show: type === "check_in" && !finishedForToday,
            photo,
            required: photoRequired,
            cameraOn,
            cameraProblem,
            livenessPrompt,
            flashKey,
            onTake: takeSelfie,
            onSnap: () =>
              livenessRequired ? void checkThenSnap() : snapPhoto(),
          }}
        >
          {hasEmployee && phoneSettled && <DeviceCard variant="row" />}
        </ReadinessList>
      )}

      <PhotoConsentDialog
        open={consentOpen}
        onOpenChange={setConsentOpen}
        onAgree={agreeToPhoto}
        agreeing={giveConsent.isPending}
      />

      {/* The assistant: the employee's own switches. */}
      {!done &&
        shift &&
        (shift.assistant.reminders_allowed ||
          shift.assistant.auto_check_in_allowed ||
          shift.presence?.enabled) && (
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

      {!done &&
        shift &&
        shift.policy.mobile_enabled &&
        shift.policy.attendance_mode !== "office" && (
          <VisitLogCard photoConsent={shift.policy.photo_consent} />
        )}

      {!done && <InstallAppBanner />}

      {!done && (
        <div className="flex justify-center gap-2 pb-2 text-sm">
          <Button asChild variant="ghost" className="min-h-11">
            <Link href="/attendance">
              {t("attendance.punch.history", "My history")}
            </Link>
          </Button>
          <Button asChild variant="ghost" className="min-h-11">
            <Link href="/attendance/corrections">
              {t("nav.corrections", "Corrections")}
            </Link>
          </Button>
        </div>
      )}
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

      {(shift.assistant.reminders_allowed || shift.presence?.enabled) && (
        <PushToggle />
      )}
      {shift.presence?.enabled && (
        <p className="text-xs text-muted-foreground">
          {t(
            "attendance.presence.uses_checks",
            "Your organisation uses presence checks during the shift. Notifications are how they reach you, so keep them on.",
          )}
        </p>
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
