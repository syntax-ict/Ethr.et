export interface ShiftRail {
  /** Fractions 0..1 along the rail. */
  start: number;
  end: number;
  now: number;
  nowState: "before" | "during" | "after";
  checkIn: number | null;
  checkOut: number | null;
}

const MIN_MARGIN_MS = 30 * 60_000;

/**
 * Where the shift, now and the day's punches sit along a rail.
 *
 * The rail's window is the shift plus a margin on each side (30 minutes or 8%
 * of the shift, whichever is longer), so someone who arrives early still has a
 * dot before the start mark. Inputs are instants, so an overnight shift needs
 * no special case. How late someone may arrive is each organisation's policy
 * and is deliberately not part of this.
 */
export function shiftRail(input: {
  startsAt: string;
  endsAt: string;
  checkIn: string | null;
  checkOut: string | null;
  now: Date;
}): ShiftRail | null {
  const start = Date.parse(input.startsAt);
  const end = Date.parse(input.endsAt);
  const now = input.now.getTime();
  if (!Number.isFinite(start) || !Number.isFinite(end) || !Number.isFinite(now))
    return null;
  if (end <= start) return null;

  const length = end - start;
  const margin = Math.max(MIN_MARGIN_MS, length * 0.08);
  const from = start - margin;
  const span = length + margin * 2;
  const along = (ms: number) => Math.min(1, Math.max(0, (ms - from) / span));
  const at = (iso: string | null) => {
    if (!iso) return null;
    const ms = Date.parse(iso);
    return Number.isFinite(ms) ? along(ms) : null;
  };

  return {
    start: along(start),
    end: along(end),
    now: along(now),
    nowState: now < start ? "before" : now > end ? "after" : "during",
    checkIn: at(input.checkIn),
    checkOut: at(input.checkOut),
  };
}
