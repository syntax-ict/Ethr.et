"use client";

import { useEffect, useRef, useState } from "react";
import { BellRing, CheckCircle2, Loader2, MapPin, WifiOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  useConfirmPresence,
  type PresenceCheck,
} from "@/features/attendance/api";
import type { LocationProblem } from "@/features/attendance/components/mobile/readiness-list";
import type { DeviceProof } from "@/lib/attendance/passkey";
import { formatDistance } from "@/lib/attendance/format-distance";
import { punchSignals, type Fix } from "@/lib/attendance/punch-signals";
import { apiErrorDetail } from "@/lib/api/error-message";
import { intlLocale } from "@/lib/i18n/config";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

/** How long an answer stays on screen before the card goes. */
const RESULT_MS = 20_000;
/** How long the quiet "nothing to confirm" line stays. */
const QUIET_MS = 8_000;

type Tone = "success" | "warning" | "neutral";

const TONE: Record<Tone, string> = {
  success: "border-success-edge bg-success-soft text-success-on-soft",
  warning: "border-warning-edge bg-warning-soft text-warning-on-soft",
  neutral: "border-border bg-neutral-soft text-neutral-on-soft",
};

/** Minutes and seconds in the reader's numerals: 14:05. */
function countdown(seconds: number, locale: string): string {
  const digits = (n: number) => {
    try {
      return new Intl.NumberFormat(intlLocale(locale), {
        minimumIntegerDigits: 2,
        useGrouping: false,
      }).format(n);
    } catch {
      return String(n).padStart(2, "0");
    }
  };
  return `${digits(Math.floor(seconds / 60))}:${digits(seconds % 60)}`;
}

/**
 * "Confirm you are at work": the card the phone punch screen shows while a
 * presence check is waiting for this person, and for a moment after the
 * answer. It holds no position, signer or connection of its own: the page
 * passes the ones the punch button uses, so an answer is made the way a punch
 * is (same fix, same passkey, same refusals).
 *
 * A check lasts 15 minutes; one answered after that is still recorded, as
 * late, so the button stays while the card does.
 */
export function PresenceCard({
  pending,
  position,
  locating,
  problem,
  onLocate,
  sign,
  isOnline,
}: {
  pending: { asked_at: string; expires_at: string } | null;
  position: Fix | null;
  locating: boolean;
  problem: LocationProblem | null;
  onLocate: () => void;
  /** The page's passkey signer; `stop` means the person cancelled under enforce. */
  sign: () => Promise<{ proof?: DeviceProof; stop: boolean }>;
  isOnline: boolean;
}) {
  const { t, locale } = useT();
  const confirm = useConfirmPresence();
  const [now, setNow] = useState(() => Date.now());
  const [result, setResult] = useState<PresenceCheck | null>(null);
  const [error, setError] = useState("");
  const [gone, setGone] = useState(false);
  const [quiet, setQuiet] = useState(false);
  const [busy, setBusy] = useState(false);
  // One key per check, kept across a retry so the server answers it once.
  const key = useRef<{ for: string; value: string } | null>(null);
  const submitting = useRef(false);

  const askedAt = pending?.asked_at ?? null;
  const [seenAsk, setSeenAsk] = useState(askedAt);
  if (askedAt !== seenAsk) {
    setSeenAsk(askedAt);
    // A new check replaces whatever the last one left on screen.
    if (askedAt !== null) {
      setResult(null);
      setError("");
      setGone(false);
      setQuiet(false);
    }
  }

  useEffect(() => {
    if (!pending) return;
    const id = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(id);
  }, [pending]);

  useEffect(() => {
    if (!result) return;
    const id = window.setTimeout(() => setResult(null), RESULT_MS);
    return () => window.clearTimeout(id);
  }, [result]);

  useEffect(() => {
    if (!quiet) return;
    const id = window.setTimeout(() => setQuiet(false), QUIET_MS);
    return () => window.clearTimeout(id);
  }, [quiet]);

  async function answer() {
    if (!pending || !position || submitting.current) return;
    submitting.current = true;
    setBusy(true);
    setError("");
    try {
      const signed = await sign();
      if (signed.stop) {
        setError(
          t(
            "device.sign_cancelled",
            "Unlock your phone when it asks, to confirm it is you.",
          ),
        );
        return;
      }
      if (key.current?.for !== pending.asked_at) {
        key.current = {
          for: pending.asked_at,
          value: `presence-${Date.now()}-${Math.random().toString(36).slice(2)}`,
        };
      }
      try {
        const check = await confirm.mutateAsync({
          idempotency_key: key.current.value,
          latitude: position.latitude,
          longitude: position.longitude,
          accuracy: position.accuracy,
          ...punchSignals(position),
          ...(signed.proof ? { device_proof: signed.proof } : {}),
        });
        setResult(check);
      } catch (err) {
        const status = (err as { response?: { status?: number } })?.response
          ?.status;
        if (status === 404) {
          // Ran out, or answered on another phone: nothing more to do here.
          setGone(true);
          setQuiet(true);
          return;
        }
        setError(
          apiErrorDetail(err) ??
            t(
              "attendance.presence.failed",
              "Your answer could not be sent. Try again.",
            ),
        );
      }
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  }

  let body: React.ReactNode = null;

  if (result) {
    const late = result.late || result.status === "missed";
    const tone: Tone =
      !late && result.status === "confirmed" ? "success" : "warning";
    const title = late
      ? t(
          "attendance.presence.result_late",
          "Answered after the time was up. It is recorded, marked late.",
        )
      : result.status === "confirmed"
        ? t("attendance.presence.result_confirmed", "Confirmed. Thank you.")
        : result.status === "outside"
          ? result.distance_meters != null
            ? t(
                "attendance.presence.result_outside_distance",
                "You are :distance from your workplace. Your answer is recorded and a reviewer will see it.",
                { distance: formatDistance(result.distance_meters, locale) },
              )
            : t(
                "attendance.presence.result_outside",
                "You are away from your workplace. Your answer is recorded and a reviewer will see it.",
              )
          : t(
              "attendance.presence.result_unverified",
              "Received, but not from your registered phone. Your answer is recorded for review.",
            );
    body = (
      <div
        className={cn(
          "flex items-start gap-3 rounded-xl border p-4 text-sm",
          TONE[tone],
        )}
      >
        {tone === "success" ? (
          <CheckCircle2
            className="mt-0.5 h-5 w-5 shrink-0 animate-scale-in"
            aria-hidden="true"
          />
        ) : (
          <MapPin className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        )}
        <p className="min-w-0 font-medium">{title}</p>
      </div>
    );
  } else if (quiet) {
    body = (
      <p className="rounded-xl border border-border bg-neutral-soft p-3 text-xs text-neutral-on-soft">
        {t(
          "attendance.presence.nothing_pending",
          "There is nothing to confirm now. The check ran out or was answered already.",
        )}
      </p>
    );
  } else if (pending && !gone) {
    const left = Math.max(
      0,
      Math.ceil((new Date(pending.expires_at).getTime() - now) / 1000),
    );
    const over = left === 0;
    const needsLocation = !position;
    const problemText =
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
          : t(
              "attendance.presence.no_position",
              "We need your location to confirm you are at work.",
            );

    body = (
      <section
        aria-label={t("attendance.presence.title", "Confirm you are at work")}
        className={cn("space-y-3 rounded-xl border p-4 text-sm", TONE.warning)}
      >
        <div className="flex items-start gap-3">
          <BellRing className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
          <div className="min-w-0 flex-1">
            <p className="font-semibold">
              {t("attendance.presence.title", "Confirm you are at work")}
            </p>
            <p className="text-xs">
              {over
                ? t(
                    "attendance.presence.time_up",
                    "The time is up. You can still answer; it is recorded as late.",
                  )
                : t("attendance.presence.time_left", "Time left")}
            </p>
          </div>
          {!over && (
            <p
              role="timer"
              aria-live="off"
              className="shrink-0 text-lg font-semibold tabular-nums"
            >
              {countdown(left, locale)}
            </p>
          )}
        </div>

        {!isOnline && (
          <p className="flex items-start gap-2 text-xs">
            <WifiOff className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            {t(
              "attendance.presence.offline",
              "You are offline, so this cannot be confirmed now. Answer when the connection returns; a late answer is still recorded.",
            )}
          </p>
        )}

        {needsLocation && (
          <div className="space-y-2">
            <p className="text-xs">{problemText}</p>
            <Button
              type="button"
              variant="outline"
              className="min-h-11 w-full"
              onClick={onLocate}
              disabled={locating}
            >
              {locating && (
                <Loader2
                  className="mr-2 h-4 w-4 animate-spin"
                  aria-hidden="true"
                />
              )}
              {t("attendance.presence.find_location", "Find my location")}
            </Button>
          </div>
        )}

        {error && (
          <p role="alert" className="text-xs font-medium">
            {error}
          </p>
        )}

        <Button
          type="button"
          className="min-h-11 w-full"
          onClick={() => void answer()}
          disabled={busy || needsLocation || !isOnline}
        >
          {busy && (
            <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />
          )}
          {t("attendance.presence.i_am_here", "I am here")}
        </Button>
      </section>
    );
  }

  // The live region is always mounted, so a card that appears is announced.
  return (
    <div aria-live="polite" className="empty:hidden">
      {body}
    </div>
  );
}
