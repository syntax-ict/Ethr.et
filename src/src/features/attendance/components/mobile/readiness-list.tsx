"use client";

import { useEffect, useState, type ReactNode, type RefObject } from "react";
import {
  Camera,
  CheckCircle2,
  MapPin,
  MapPinCheck,
  MapPinOff,
  RefreshCw,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import type { MyShift } from "@/features/attendance/api";
import { formatDistance } from "@/lib/attendance/format-distance";
import type { GeofenceState } from "@/lib/attendance/punch-assistant";
import type { Fix } from "@/lib/attendance/punch-signals";
import type { LivenessPrompt } from "@/lib/attendance/liveness";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

export type LocationProblem = "denied" | "unavailable" | "unsupported";
type Tone = "success" | "warning" | "neutral";

const TONE: Record<Tone, string> = {
  success: "bg-success-soft text-success-on-soft",
  warning: "bg-warning-soft text-warning-on-soft",
  neutral: "bg-neutral-soft text-neutral-on-soft",
};

function Check() {
  return (
    <CheckCircle2
      className="h-5 w-5 shrink-0 animate-scale-in text-success-on-soft"
      aria-hidden="true"
    />
  );
}

/**
 * What the punch needs, as one list: where you are, the selfie, and (once it
 * is settled) this phone. Each row says what it knows and what to do.
 */
export function ReadinessList({
  position,
  fence,
  problem,
  locating,
  shift,
  onLocate,
  selfie,
  videoRef,
  children,
}: {
  position: Fix | null;
  fence: GeofenceState;
  problem: LocationProblem | null;
  locating: boolean;
  shift: MyShift | undefined;
  onLocate: () => void;
  /** Where the camera stream attaches; the video is always mounted. */
  videoRef: RefObject<HTMLVideoElement | null>;
  selfie: {
    show: boolean;
    photo: string | null;
    required: boolean;
    cameraOn: boolean;
    cameraProblem: boolean;
    livenessPrompt: LivenessPrompt | null;
    /** Goes up by one each time a photo is taken; plays the shutter flash. */
    flashKey: number;
    onTake: () => void;
    onSnap: () => void;
  };
  /** Further rows, such as this phone once it is settled. */
  children?: ReactNode;
}) {
  const { t, locale } = useT();

  // The shutter flash. Taking the photo stops the camera, which would hide the
  // frame at once, so the frame is kept in view for the length of the flash.
  const [seenFlash, setSeenFlash] = useState(selfie.flashKey);
  const [flashing, setFlashing] = useState(false);
  if (seenFlash !== selfie.flashKey) {
    setSeenFlash(selfie.flashKey);
    if (selfie.flashKey > 0) setFlashing(true);
  }
  useEffect(() => {
    if (!flashing) return;
    const id = window.setTimeout(() => setFlashing(false), 250);
    return () => window.clearTimeout(id);
  }, [flashing]);
  const livenessText =
    selfie.livenessPrompt === "blink"
      ? t("liveness.blink", "Blink once")
      : selfie.livenessPrompt === "turn"
        ? t("liveness.turn", "Turn your head to one side")
        : null;

  // Location: a good fix stays on screen while a refresh runs; only a person
  // with no fix yet sees "getting location".
  const noFix = !position;
  const locationTitle = !position
    ? problem
      ? t("attendance.mobile_page.location_required")
      : t("attendance.mobile_page.getting_location")
    : fence.kind === "inside"
      ? t("attendance.punch.inside_area", "Inside your branch area")
      : fence.kind === "outside"
        ? t("attendance.punch.outside_area", "Outside your branch area")
        : t("attendance.mobile_page.your_location");

  let locationDetail = "";
  if (!position) {
    locationDetail =
      problem === "denied"
        ? t(
            "attendance.punch.location_denied_help",
            "Allow location for ETHR in your browser or phone settings, then tap here.",
          )
        : problem === "unavailable"
          ? t(
              "attendance.punch.location_unavailable_help",
              "No location yet. Move outside or near a window, then tap here.",
            )
          : problem === "unsupported"
            ? t("attendance.mobile_page.geolocation_unsupported")
            : "";
  } else {
    const parts: string[] = [];
    if (fence.kind !== "none") {
      parts.push(
        `${formatDistance(fence.distance, locale)} ${t("attendance.punch.from_branch", "from your branch")}`,
      );
      if (fence.place) parts.push(fence.place);
    }
    parts.push(
      t("attendance.punch.accuracy", "Accurate to about :distance", {
        distance: formatDistance(position.accuracy, locale),
      }),
    );
    if (shift && position.accuracy > shift.policy.accuracy_threshold_meters) {
      parts.push(
        t(
          "attendance.punch.weak_fix",
          "Weak signal. Move outside for a better fix.",
        ),
      );
    }
    locationDetail = parts.join(" · ");
  }

  const locationTone: Tone = noFix
    ? problem
      ? "warning"
      : "neutral"
    : fence.kind === "inside"
      ? "success"
      : fence.kind === "outside"
        ? "warning"
        : "neutral";
  const LocationIcon =
    !noFix && fence.kind === "inside"
      ? MapPinCheck
      : !noFix && fence.kind === "outside"
        ? MapPinOff
        : MapPin;

  const photoTone: Tone = selfie.photo
    ? "success"
    : selfie.cameraProblem || selfie.required
      ? "warning"
      : "neutral";

  return (
    <section className="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card">
      <button
        type="button"
        onClick={onLocate}
        aria-busy={locating}
        className="flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
      >
        <span
          className={cn(
            "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
            TONE[locationTone],
          )}
        >
          <LocationIcon className="h-[18px] w-[18px]" aria-hidden="true" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-medium text-foreground">
            {locationTitle}
          </span>
          {locationDetail && (
            <span className="block text-xs text-muted-foreground">
              {locationDetail}
            </span>
          )}
        </span>
        {locationTone === "success" && <Check />}
        <RefreshCw
          className={cn(
            "h-4 w-4 shrink-0 text-muted-foreground",
            locating && "animate-spin",
          )}
          aria-hidden="true"
        />
        <span className="sr-only">
          {t("attendance.mobile_page.refresh_location")}
        </span>
      </button>

      {selfie.show && (
        <div>
          <div className="flex min-h-14 items-center gap-3 px-4 py-3">
            {selfie.photo ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={selfie.photo}
                alt={t("attendance.mobile_page.selfie_preview_alt")}
                className="h-10 w-10 shrink-0 rounded-lg object-cover"
              />
            ) : (
              <span
                className={cn(
                  "flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
                  TONE[photoTone],
                )}
              >
                <Camera className="h-[18px] w-[18px]" aria-hidden="true" />
              </span>
            )}
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-foreground">
                {t("attendance.mobile_page.selfie")}
              </p>
              {selfie.cameraProblem ? (
                <p role="alert" className="text-xs text-warning-on-soft">
                  <span className="block">
                    {t("attendance.mobile_page.camera_denied")}
                  </span>
                  <span className="block">
                    {t(
                      "attendance.punch.camera_denied_help",
                      "Allow the camera for ETHR in your browser settings, then try again.",
                    )}
                  </span>
                </p>
              ) : (
                <p className="text-xs text-muted-foreground first-letter:uppercase">
                  {selfie.photo
                    ? t("attendance.punch.selfie_ready", "Photo ready")
                    : selfie.required
                      ? t("attendance.punch.required", "Required")
                      : t("attendance.mobile_page.optional")}
                </p>
              )}
            </div>
            {selfie.photo && <Check />}
            {!selfie.cameraOn && (
              <Button
                variant="outline"
                className="min-h-11 shrink-0"
                onClick={selfie.onTake}
              >
                {selfie.photo
                  ? t("attendance.mobile_page.retake")
                  : t("attendance.punch.take_selfie", "Take a selfie")}
              </Button>
            )}
          </div>
          {/* The video stays mounted so the stream has somewhere to attach. */}
          <div
            className={cn(
              "space-y-3 px-4 pb-4",
              !selfie.cameraOn && !flashing && "hidden",
            )}
          >
            {/* A portrait frame, mirrored for the person looking at it; the
                photo itself is not mirrored. */}
            <div className="relative mx-auto aspect-[3/4] h-[50vh] max-h-[50vh] max-w-full overflow-hidden rounded-lg">
              <video
                ref={videoRef}
                className="h-full w-full -scale-x-100 rounded-lg bg-muted object-cover"
                playsInline
                muted
              />
              <div
                aria-hidden="true"
                className="pointer-events-none absolute left-1/2 top-1/2 h-[72%] w-[62%] -translate-x-1/2 -translate-y-1/2 rounded-[50%] border-2 border-background/80"
              />
              {livenessText && (
                <div
                  aria-hidden="true"
                  className="pointer-events-none absolute inset-x-0 bottom-0 bg-foreground/70 py-3 text-center text-lg font-semibold text-background"
                >
                  {livenessText}
                </div>
              )}
              {selfie.flashKey > 0 && (
                <div
                  key={selfie.flashKey}
                  aria-hidden="true"
                  className="ethr-punch-flash pointer-events-none absolute inset-0 bg-background"
                />
              )}
            </div>
            {selfie.cameraOn && (
              <Button
                className="min-h-11 w-full"
                disabled={selfie.livenessPrompt !== null}
                onClick={selfie.onSnap}
              >
                {livenessText ?? t("attendance.mobile_page.capture")}
              </Button>
            )}
          </div>
        </div>
      )}

      {children}
    </section>
  );
}
