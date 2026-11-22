/**
 * Field visits logged without a connection, kept on the phone until it is
 * back. A visit is not a punch, so it has its own small store rather than
 * the attendance queue the server's sync endpoint reads.
 *
 * localStorage, not IndexedDB: a visit is a few hundred bytes, and a photo
 * that will not fit is dropped from the queued copy rather than losing the
 * visit itself.
 *
 * Each entry is the request body plus two fields the server never sees: the
 * person who logged it (`owner`, their employee_public_id) and the
 * organisation it was logged in (`tenant`, the X-Tenant value). A shared phone
 * can hold several people's visits, and the server credits a visit to whoever
 * sends it, so only the owner's session may send it, in the same organisation.
 * Entries queued before this existed carry neither field.
 */
import type { LogVisit } from "@/features/attendance/api";
import { requestPersistentStorage } from "@/lib/persist-storage";

const KEY = "ethr-visit-queue";

/** Dispatched whenever the queue changes, so counts on screen can re-read. */
export const VISIT_QUEUE_CHANGED_EVENT = "ethr-visit-queue-changed";

export type QueuedVisit = LogVisit & {
  owner?: string | null;
  tenant?: string | null;
};

function read(): QueuedVisit[] {
  try {
    const raw = window.localStorage.getItem(KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? (parsed as QueuedVisit[]) : [];
  } catch {
    return [];
  }
}

function notify() {
  try {
    window.dispatchEvent(new Event(VISIT_QUEUE_CHANGED_EVENT));
  } catch {
    // No window to tell.
  }
}

function write(visits: QueuedVisit[]): boolean {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(visits));
    return true;
  } catch {
    return false;
  }
}

export function queuedVisits(): QueuedVisit[] {
  return read();
}

/** The request body of a queued visit: exactly LogVisit, no owner, no tenant. */
export function visitBody(entry: QueuedVisit): LogVisit {
  const body: QueuedVisit = { ...entry };
  delete body.owner;
  delete body.tenant;
  return body;
}

/**
 * Whether `entry` is this person's to send here. An entry with no owner is an
 * old one, treated as the signed-in person's; one with no tenant field is
 * likewise sent wherever the person is.
 */
export function isSendableBy(
  entry: QueuedVisit,
  owner: string,
  tenant: string | null,
): boolean {
  if (entry.owner != null && entry.owner !== owner) return false;
  if (entry.tenant !== undefined && entry.tenant !== tenant) return false;
  return true;
}

/** Entries that belong to `owner`, plus unowned old ones. */
export function visitsOwnedBy(owner: string | null): QueuedVisit[] {
  if (!owner) return [];
  return read().filter((v) => v.owner == null || v.owner === owner);
}

/** Queue a visit; returns false only if not even the text could be kept. */
export function queueVisit(
  visit: LogVisit,
  owner: string | null,
  tenant: string | null,
): boolean {
  requestPersistentStorage();
  const entry: QueuedVisit = { ...visit, owner, tenant };
  const visits = read();
  const kept =
    write([...visits, entry]) || write([...visits, { ...entry, photo: null }]);
  if (kept) notify();
  return kept;
}

export function dropQueuedVisit(idempotencyKey: string): void {
  write(read().filter((v) => v.idempotency_key !== idempotencyKey));
  notify();
}

/**
 * At sign out: give every entry with no owner to the departing person, in the
 * organisation they are leaving, so the next person on this phone cannot send
 * it as theirs. With no one to give them to, they are left as they are.
 */
export function stampUnownedVisits(
  owner: string | null | undefined,
  tenant: string | null,
): void {
  if (!owner) return;
  const visits = read();
  if (!visits.some((v) => v.owner == null)) return;
  write(
    visits.map((v) =>
      v.owner == null ? { ...v, owner, tenant: v.tenant ?? tenant } : v,
    ),
  );
  notify();
}
