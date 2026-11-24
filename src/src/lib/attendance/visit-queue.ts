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
  /**
   * Set when the server refused this visit for good. It is never sent again,
   * and it is kept so the person can be told: until 2026-11-24 a refused
   * visit was dropped from the queue and nobody learned it had not counted,
   * where a refused punch was listed with its reason.
   */
  refused?: { reason: string | null; at: string } | null;
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

/** The request body of a queued visit: exactly LogVisit, none of the phone's own fields. */
export function visitBody(entry: QueuedVisit): LogVisit {
  const body: QueuedVisit = { ...entry };
  delete body.owner;
  delete body.tenant;
  delete body.refused;
  return body;
}

/**
 * Whether `entry` is this person's to send here. An entry with no owner is an
 * old one, treated as the signed-in person's; one with no tenant field is
 * likewise sent wherever the person is.
 *
 * The organisation is refused only when both sides name one and they differ.
 * A null on either side is not a mismatch: `tenantHeaderValue()` is null on an
 * organisation's own subdomain and the slug on the apex, and both addresses
 * reach the same organisation, so a visit queued at one must send from the
 * other. The owner check is the real guarantee: the same person has a
 * different employee id in each organisation.
 */
export function isSendableBy(
  entry: QueuedVisit,
  owner: string,
  tenant: string | null,
): boolean {
  if (entry.refused) return false;
  if (entry.owner != null && entry.owner !== owner) return false;
  if (entry.tenant != null && tenant != null && entry.tenant !== tenant) {
    return false;
  }
  return true;
}

function ownedBy(owner: string | null): QueuedVisit[] {
  if (!owner) return [];
  return read().filter((v) => v.owner == null || v.owner === owner);
}

/** Entries still waiting to be sent that belong to `owner`, plus unowned old ones. */
export function visitsOwnedBy(owner: string | null): QueuedVisit[] {
  return ownedBy(owner).filter((v) => !v.refused);
}

/** This person's visits the server refused for good, kept to be shown. */
export function refusedVisitsOwnedBy(owner: string | null): QueuedVisit[] {
  return ownedBy(owner).filter((v) => Boolean(v.refused));
}

/**
 * When a queued visit was logged. The body carries no time of its own (the
 * server stamps a visit when it arrives), but the key is made from the moment
 * it was logged: `visit-<milliseconds>-<random>`.
 */
export function loggedAt(idempotencyKey: string): string | null {
  const match = /^visit-(\d{10,})-/.exec(idempotencyKey);
  if (!match) return null;
  const at = new Date(Number(match[1]));
  return Number.isNaN(at.getTime()) ? null : at.toISOString();
}

/** The server will never accept this visit: keep it, stop sending it. */
export function markVisitRefused(
  idempotencyKey: string,
  reason: string | null,
): void {
  write(
    read().map((v) =>
      v.idempotency_key === idempotencyKey
        ? {
            ...v,
            photo: null,
            refused: { reason, at: new Date().toISOString() },
          }
        : v,
    ),
  );
  notify();
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
