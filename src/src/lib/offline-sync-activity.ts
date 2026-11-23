"use client";

import { useSyncExternalStore } from "react";

/**
 * What this page is sending to the server right now, and what it last sent.
 *
 * Punches and field visits saved on the phone are sent from any page by
 * OfflineSyncAgent, which renders nothing. Until this existed only the punch
 * screen said "sending" or "sent"; everywhere else the banner's count simply
 * vanished and a person was never told their attendance had arrived. The two
 * senders report here and the banner reads it.
 *
 * Page-wide on purpose, like the in-flight guards in useOfflineSync and
 * visit-sync: however many components mount, there is one send and one answer.
 */
export interface SyncActivity {
  /** Punches in the run under way; 0 when none is. */
  sendingPunches: number;
  /** Visits in the run under way; 0 when none is. */
  sendingVisits: number;
  /** The last runs that sent something, until the banner has shown it. */
  lastSent: { punches: number; visits: number; at: number } | null;
}

/** How long "sent" is worth saying; a later run inside it adds to the same line. */
export const SENT_SHOWN_FOR_MS = 4000;

const IDLE: SyncActivity = {
  sendingPunches: 0,
  sendingVisits: 0,
  lastSent: null,
};

let state: SyncActivity = IDLE;
const listeners = new Set<() => void>();

function set(next: SyncActivity) {
  state = next;
  for (const listener of listeners) listener();
}

type Kind = "punches" | "visits";

/** A run has records to send. Call only when there is at least one. */
export function syncStarted(kind: Kind, count: number) {
  if (count <= 0) return;
  set(
    kind === "punches"
      ? { ...state, sendingPunches: count }
      : { ...state, sendingVisits: count },
  );
}

/** The run that called syncStarted is over; `sent` is how many the server took. */
export function syncFinished(kind: Kind, sent: number) {
  const cleared: SyncActivity =
    kind === "punches"
      ? { ...state, sendingPunches: 0 }
      : { ...state, sendingVisits: 0 };
  if (sent <= 0) {
    set(cleared);
    return;
  }
  const now = Date.now();
  // Punches and visits go one after the other; say both in one line.
  const recent =
    state.lastSent && now - state.lastSent.at < SENT_SHOWN_FOR_MS
      ? state.lastSent
      : { punches: 0, visits: 0 };
  set({
    ...cleared,
    lastSent: {
      punches: recent.punches + (kind === "punches" ? sent : 0),
      visits: recent.visits + (kind === "visits" ? sent : 0),
      at: now,
    },
  });
}

/** The banner has shown "sent" for long enough. */
export function clearLastSent() {
  if (state.lastSent) set({ ...state, lastSent: null });
}

function subscribe(listener: () => void) {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function getSyncActivity(): SyncActivity {
  return state;
}

export function useSyncActivity(): SyncActivity {
  return useSyncExternalStore(subscribe, getSyncActivity, () => IDLE);
}

/** For tests: forget everything between cases. */
export function resetSyncActivityForTests() {
  state = IDLE;
}
