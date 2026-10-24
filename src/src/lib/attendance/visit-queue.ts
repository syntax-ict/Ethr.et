/**
 * Field visits logged without a connection, kept on the phone until it is
 * back. A visit is not a punch, so it has its own small store rather than
 * the attendance queue the server's sync endpoint reads.
 *
 * localStorage, not IndexedDB: a visit is a few hundred bytes, and a photo
 * that will not fit is dropped from the queued copy rather than losing the
 * visit itself.
 */
import type { LogVisit } from "@/features/attendance/api";

const KEY = "ethr-visit-queue";

function read(): LogVisit[] {
  try {
    const raw = window.localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as LogVisit[]) : [];
  } catch {
    return [];
  }
}

function write(visits: LogVisit[]): boolean {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(visits));
    return true;
  } catch {
    return false;
  }
}

export function queuedVisits(): LogVisit[] {
  return read();
}

/** Queue a visit; returns false only if not even the text could be kept. */
export function queueVisit(visit: LogVisit): boolean {
  const visits = read();
  if (write([...visits, visit])) return true;
  return write([...visits, { ...visit, photo: null }]);
}

export function dropQueuedVisit(idempotencyKey: string): void {
  write(read().filter((v) => v.idempotency_key !== idempotencyKey));
}
