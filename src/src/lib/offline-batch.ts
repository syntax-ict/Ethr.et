import type { OfflineAttendanceRecord } from "@/lib/offline-queue";

/**
 * Phone punches are sent a few at a time. The API takes at most 100 records a
 * request and the host refuses a body over 8 MB, and a selfie alone is about
 * 2 MB as text, so one request for the whole queue stopped working once a phone
 * had been away from signal for a day. These two limits keep every request far
 * inside both.
 *
 * public/sw.js sends the same queue with the app closed and cannot import this
 * file. It holds its own copy of both numbers; a test reads the worker's source
 * and fails if they differ, so change them in both places.
 */
export const SYNC_BATCH_RECORDS = 20;
export const SYNC_BATCH_CHARS = 4_000_000;

/** One punch as the sync endpoint takes it. */
export function toSyncPayload(r: OfflineAttendanceRecord, clientTime: string) {
  return {
    employee_public_id: r.employee_public_id,
    type: r.type,
    // Required by OfflineSyncRequest, and the time the punch is recorded at.
    timestamp: r.captured_at,
    idempotency_key: r.idempotency_key,
    offline_token: r.offline_token,
    latitude: r.latitude,
    longitude: r.longitude,
    accuracy: r.accuracy ?? null,
    photo: r.photo ?? null,
    altitude: r.altitude,
    speed: r.speed,
    heading: r.heading,
    fix_age_ms: r.fix_age_ms,
    device_id: r.device_id,
    webdriver: r.webdriver,
    liveness: r.liveness ?? null,
    device_proof: r.device_proof ?? null,
    // When the batch is sent, so the server can see the phone's clock.
    client_time: clientTime,
  };
}

/**
 * Splits items, in order, into batches of at most `maxRecords` items whose
 * sizes add up to at most `maxChars`. An item larger than the budget goes in a
 * batch of its own: it is not dropped, and the server decides about it.
 *
 * Order is kept because a check-in must reach the server before its check-out.
 */
export function splitIntoBatches<T>(
  items: readonly T[],
  sizeOf: (item: T) => number,
  maxRecords = SYNC_BATCH_RECORDS,
  maxChars = SYNC_BATCH_CHARS,
): T[][] {
  const batches: T[][] = [];
  let current: T[] = [];
  let currentChars = 0;

  for (const item of items) {
    const size = sizeOf(item);
    if (
      current.length > 0 &&
      (current.length >= maxRecords || currentChars + size > maxChars)
    ) {
      batches.push(current);
      current = [];
      currentChars = 0;
    }
    current.push(item);
    currentChars += size;
  }
  if (current.length > 0) batches.push(current);

  return batches;
}
