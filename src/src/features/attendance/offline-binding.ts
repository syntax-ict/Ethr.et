import type { AttendanceDeviceState } from "@/features/attendance/api";

/**
 * Whether a punch saved on this phone would be refused when it is sent, so it
 * should not be saved and promised.
 *
 * When the organisation enforces phone binding, an offline punch is accepted
 * only with a signature from the registered phone, and that needs a grant that
 * names the phone (`currentOfflineDevice`). HR can exempt a person; the server
 * reads that as the end of the `exempt_until` day in UTC, the application's
 * zone (AttendanceDeviceGate::exempt), so this compares on the same clock:
 * in Addis Ababa the last exempt day runs until 03:00 the next morning, and a
 * punch the server would still take is not refused here. An office network cannot
 * exempt an offline punch: the server cannot see the network of a punch made
 * with no connection (offlineVerdict has no office case).
 *
 * With the device state unknown (not loaded, as when the page opened offline)
 * this says no, and the punch is saved as before.
 */
export function offlinePunchWouldBeRefused(
  state: AttendanceDeviceState | undefined,
  hasOfflineDevice: boolean,
  now: Date = new Date(),
): boolean {
  if (state?.binding !== "enforce" || hasOfflineDevice) return false;

  const exemptUntil = state.exempt_until;
  if (
    exemptUntil &&
    exemptUntil.slice(0, 10) >= now.toISOString().slice(0, 10)
  ) {
    return false;
  }
  return true;
}
