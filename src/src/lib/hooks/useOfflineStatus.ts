"use client";

import { useSyncExternalStore } from "react";

/**
 * Connectivity status (PHASE_00 S04: "Offline detection hook `useOfflineStatus`").
 *
 * The hook the phase document specifies never existed. Instead the same block —
 * `useState(true)`, then an effect that calls `setIsOnline(navigator.onLine)` and
 * attaches online/offline listeners — was copy-pasted into `OfflineBanner` and
 * `useOfflineSync`, so the two could briefly disagree about whether the app was
 * online.
 *
 * Built on `useSyncExternalStore` rather than state-plus-effect, which is what
 * that API exists for:
 *
 *  - No `setState` inside an effect, so no extra render pass on every mount
 *    (this is what `react-hooks/set-state-in-effect` was reporting).
 *  - `getServerSnapshot` returns `true`, so server rendering and the first client
 *    render agree and there is no hydration mismatch. The previous code got this
 *    right only by accident: it initialised to `true` and corrected in an effect.
 *  - React resubscribes and re-reads for us, so a listener can never leak or go
 *    stale.
 */

function subscribe(onStoreChange: () => void): () => void {
  window.addEventListener("online", onStoreChange);
  window.addEventListener("offline", onStoreChange);

  return () => {
    window.removeEventListener("online", onStoreChange);
    window.removeEventListener("offline", onStoreChange);
  };
}

function getSnapshot(): boolean {
  return navigator.onLine;
}

/**
 * Assume online on the server. `navigator` does not exist there, and rendering
 * an offline banner into the HTML of a page that was fetched over the network
 * would be visibly wrong for the split second before hydration.
 */
function getServerSnapshot(): boolean {
  return true;
}

export function useOfflineStatus(): { isOnline: boolean; isOffline: boolean } {
  const isOnline = useSyncExternalStore(
    subscribe,
    getSnapshot,
    getServerSnapshot,
  );

  return { isOnline, isOffline: !isOnline };
}
