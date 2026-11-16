"use client";

import { shiftRail } from "@/lib/attendance/shift-rail";
import { cn } from "@/lib/utils";

/**
 * Today's shift as a quiet line: the shift's span between two ticks, a ring
 * for now, and an amber dot for each punch. The shift line above it carries
 * the times, so the rail carries none.
 */
export function ShiftRail({
  startsAt,
  endsAt,
  checkIn,
  checkOut,
  now,
  label,
  landing = null,
}: {
  startsAt: string;
  endsAt: string;
  checkIn: string | null;
  checkOut: string | null;
  now: Date;
  /** The accessible name, with the times already formatted by the caller. */
  label: string;
  /** The punch that has just been made: its dot plays the landing animation. */
  landing?: "check_in" | "check_out" | null;
}) {
  const rail = shiftRail({ startsAt, endsAt, checkIn, checkOut, now });
  if (!rail) return null;

  const pct = (fraction: number) => `${fraction * 100}%`;
  const workedTo =
    rail.checkIn === null
      ? null
      : rail.checkOut !== null
        ? rail.checkOut
        : rail.now;
  const showNow = !(rail.nowState === "after" && rail.checkOut !== null);

  // The wrapper centres the dot; the inner span carries the landing
  // animation, so its transform never fights the centring.
  const dot = (fraction: number, kind: "check_in" | "check_out") => (
    <span
      key={kind}
      className="absolute top-1/2 h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2"
      style={{ left: pct(fraction) }}
    >
      <span
        className={cn(
          "block h-full w-full rounded-full ring-2 ring-background",
          landing === kind && "ethr-punch-land",
        )}
        style={{ backgroundColor: "var(--brand-mark-dot)" }}
      />
    </span>
  );

  return (
    <div
      role="img"
      aria-label={label}
      className="relative h-7 w-full"
      data-testid="shift-rail"
    >
      <div aria-hidden="true" className="absolute inset-0">
        <span className="absolute left-0 right-0 top-1/2 h-0.5 -translate-y-1/2 rounded-full bg-border" />
        <span
          className="absolute top-1/2 h-0.5 -translate-y-1/2 bg-muted-foreground/40"
          style={{
            left: pct(rail.start),
            width: pct(rail.end - rail.start),
          }}
        />
        {rail.checkIn !== null &&
          workedTo !== null &&
          workedTo > rail.checkIn && (
            <span
              className="absolute top-1/2 h-[3px] -translate-y-1/2 rounded-full bg-primary"
              style={{
                left: pct(rail.checkIn),
                width: pct(workedTo - rail.checkIn),
              }}
            />
          )}
        {[rail.start, rail.end].map((at, i) => (
          <span
            key={i}
            className="absolute top-1/2 h-2 w-px -translate-x-1/2 -translate-y-1/2 bg-muted-foreground"
            style={{ left: pct(at) }}
          />
        ))}
        {showNow && (
          <span
            className="absolute top-1/2 h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-foreground bg-background"
            style={{ left: pct(rail.now) }}
          />
        )}
        {rail.checkIn !== null && dot(rail.checkIn, "check_in")}
        {rail.checkOut !== null && dot(rail.checkOut, "check_out")}
      </div>
    </div>
  );
}
