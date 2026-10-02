/**
 * The UTC instant at which a wall clock in `timeZone` reads `date` + `time`.
 *
 * A correction is entered as a tenant-local date and time ("09:00 on the 30th"
 * in Addis Ababa), but the API stores UTC (Convention #2) and the model's
 * datetime cast discards any offset other than UTC — `"…T09:00+03:00"` is saved
 * as 09:00 UTC, three hours late. So the page sends an explicit `Z` instant.
 *
 * Returns null for an empty or malformed time.
 */
export function zonedWallTimeToUtcIso(
  date: string,
  time: string,
  timeZone: string,
): string | null {
  const d = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
  const t = /^(\d{2}):(\d{2})$/.exec(time);
  if (!d || !t) return null;

  const asIfUtc = Date.UTC(+d[1], +d[2] - 1, +d[3], +t[1], +t[2]);
  const instant = asIfUtc - offsetMs(asIfUtc, timeZone);

  return new Date(instant).toISOString().replace(/\.\d{3}Z$/, "Z");
}

/** How far `timeZone`'s wall clock runs ahead of UTC at `utcMs`. */
function offsetMs(utcMs: number, timeZone: string): number {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone,
    hourCycle: "h23",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  }).formatToParts(new Date(utcMs));

  const get = (type: string) =>
    Number(parts.find((p) => p.type === type)?.value ?? 0);

  const wall = Date.UTC(
    get("year"),
    get("month") - 1,
    get("day"),
    get("hour"),
    get("minute"),
    get("second"),
  );

  return wall - utcMs;
}
