export type ShiftMoment =
  | { kind: "starts_in"; minutes: number }
  | { kind: "started_ago"; minutes: number }
  | { kind: "worked"; minutes: number; endsInMinutes: number | null }
  | { kind: "worked_total"; minutes: number };

const wholeMinutes = (ms: number) => Math.max(0, Math.floor(ms / 60_000));

/**
 * The one fact worth saying under the shift line: how long until it starts,
 * how long ago it did, or how long the person has worked. Null when there is
 * nothing to say (the shift is over and nobody came, or a date is unreadable).
 */
export function shiftMoment(input: {
  startsAt: string;
  endsAt: string;
  status: "not_checked_in" | "checked_in" | "checked_out";
  checkIn: string | null;
  checkOut: string | null;
  now: Date;
}): ShiftMoment | null {
  const start = Date.parse(input.startsAt);
  const end = Date.parse(input.endsAt);
  const now = input.now.getTime();
  if (!Number.isFinite(start) || !Number.isFinite(end) || !Number.isFinite(now))
    return null;

  if (input.status === "not_checked_in") {
    if (now < start)
      return { kind: "starts_in", minutes: wholeMinutes(start - now) };
    if (now <= end)
      return { kind: "started_ago", minutes: wholeMinutes(now - start) };
    return null;
  }

  const checkIn = input.checkIn ? Date.parse(input.checkIn) : NaN;
  if (!Number.isFinite(checkIn)) return null;

  if (input.status === "checked_in") {
    return {
      kind: "worked",
      minutes: wholeMinutes(now - checkIn),
      endsInMinutes: now < end ? wholeMinutes(end - now) : null,
    };
  }

  const checkOut = input.checkOut ? Date.parse(input.checkOut) : NaN;
  if (!Number.isFinite(checkOut)) return null;
  return { kind: "worked_total", minutes: wholeMinutes(checkOut - checkIn) };
}
