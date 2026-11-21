"use client";

import { useEffect, useId, useRef, useState } from "react";
import { Loader2, LogIn, LogOut } from "lucide-react";
import { haptic } from "@/lib/attendance/haptics";
import { useT } from "@/lib/i18n/useT";
import { cn } from "@/lib/utils";

export type PunchButtonState = "waiting" | "ready" | "blocked" | "busy";
export type PunchPhase = "idle" | "confirming" | "sending";

/** After this long in flight the screen says the connection is weak. */
const SLOW_AFTER_MS = 8000;

/**
 * The round punch button, with a ring that says what it is waiting for:
 * a turning arc while it waits or sends, a dashed ring when something named
 * blocks it, a solid ring when it can be pressed. The line under it says why.
 *
 * `reason` is the sentence for waiting or blocked; `phase` the sentence for
 * a punch in flight.
 */
export function PunchButton({
  type,
  state,
  onPress,
  label,
  reason,
  phase = "idle",
  onSlow,
  onBlockedPress,
  nudgeKey = 0,
}: {
  type: "check_in" | "check_out";
  state: PunchButtonState;
  onPress: () => void;
  label: string;
  reason?: string | null;
  phase?: PunchPhase;
  /** Called once when a send has taken longer than 8 seconds. */
  onSlow?: () => void;
  /** A tap while the button is waiting or blocked: say why, send nothing. */
  onBlockedPress?: () => void;
  /** Goes up by one on each such tap; the reason line shakes once. */
  nudgeKey?: number;
}) {
  const { t } = useT();
  const statusId = useId();
  const [prev, setPrev] = useState(state);
  const [pulseKey, setPulseKey] = useState(0);
  const [slow, setSlow] = useState(false);
  const onSlowRef = useRef(onSlow);
  useEffect(() => {
    onSlowRef.current = onSlow;
  });

  // One pulse when the button becomes pressable; none when it mounts ready.
  if (prev !== state) {
    setPrev(state);
    if ((prev === "waiting" || prev === "blocked") && state === "ready") {
      setPulseKey((k) => k + 1);
    }
  }
  useEffect(() => {
    if (pulseKey > 0) haptic("ready");
  }, [pulseKey]);

  useEffect(() => {
    if (phase !== "sending") return;
    const id = window.setTimeout(() => {
      setSlow(true);
      onSlowRef.current?.();
    }, SLOW_AFTER_MS);
    return () => {
      window.clearTimeout(id);
      setSlow(false);
    };
  }, [phase]);

  const notReady = state === "waiting" || state === "blocked";
  const busy = state === "busy";

  const status = busy
    ? slow
      ? t("attendance.punch.slow", "Still sending. The connection is weak.")
      : phase === "sending"
        ? t("attendance.punch.sending", "Sending…")
        : t("attendance.punch.confirming", "Confirming it is you…")
    : reason;

  // Only a punch in flight is disabled for real. A button that is waiting or
  // blocked stays focusable and answers a tap with the reason (aria-disabled).
  function handleClick() {
    if (state === "ready") onPress();
    else if (state === "waiting" || state === "blocked") onBlockedPress?.();
  }

  return (
    <>
      <div className="relative flex h-44 w-44 items-center justify-center">
        <svg
          aria-hidden="true"
          viewBox="0 0 176 176"
          className="pointer-events-none absolute inset-0 h-full w-full"
          fill="none"
          strokeWidth="3"
        >
          {(state === "waiting" || busy) && (
            <circle
              cx="88"
              cy="88"
              r="86"
              stroke="currentColor"
              strokeLinecap="round"
              strokeDasharray="135 406"
              className="ethr-punch-sweep text-primary"
            />
          )}
          {state === "ready" && (
            <circle
              cx="88"
              cy="88"
              r="86"
              stroke="currentColor"
              className="text-primary opacity-50"
            />
          )}
          {state === "blocked" && (
            <circle
              cx="88"
              cy="88"
              r="86"
              stroke="currentColor"
              strokeDasharray="6 8"
              className="text-warning-edge"
            />
          )}
          {pulseKey > 0 && (
            <circle
              key={pulseKey}
              cx="88"
              cy="88"
              r="86"
              stroke="currentColor"
              className="ethr-punch-ready text-primary"
            />
          )}
        </svg>
        <button
          type="button"
          onClick={handleClick}
          disabled={busy}
          aria-disabled={state !== "ready"}
          aria-describedby={status ? statusId : undefined}
          aria-busy={busy}
          className={cn(
            "flex h-40 w-40 touch-manipulation flex-col items-center justify-center gap-2 rounded-full border text-lg font-semibold shadow-lg transition-transform focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-ring/40 active:scale-95 aria-disabled:cursor-not-allowed aria-disabled:active:scale-100",
            notReady
              ? "border-border bg-muted text-muted-foreground shadow-none"
              : type === "check_in"
                ? "border-transparent bg-primary text-primary-foreground"
                : "border-transparent bg-foreground text-background",
          )}
        >
          {busy ? (
            <Loader2 className="h-8 w-8 animate-spin" aria-hidden="true" />
          ) : type === "check_in" ? (
            <LogIn className="h-8 w-8" aria-hidden="true" />
          ) : (
            <LogOut className="h-8 w-8" aria-hidden="true" />
          )}
          {label}
        </button>
      </div>
      {status && (
        <p
          id={statusId}
          className="text-center text-sm text-muted-foreground"
          role="status"
          data-nudged={nudgeKey > 0 ? "true" : undefined}
        >
          {nudgeKey > 0 ? (
            <span key={nudgeKey} className="ethr-punch-nudge inline-block">
              {status}
            </span>
          ) : (
            status
          )}
        </p>
      )}
    </>
  );
}
