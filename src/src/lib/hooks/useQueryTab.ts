"use client";

import { useSyncExternalStore } from "react";

/** Fired after this hook rewrites the address, which `replaceState` does not announce. */
const CHANGE_EVENT = "ethr:query-tab";

function subscribe(onChange: () => void): () => void {
  window.addEventListener("popstate", onChange);
  window.addEventListener(CHANGE_EVENT, onChange);
  return () => {
    window.removeEventListener("popstate", onChange);
    window.removeEventListener(CHANGE_EVENT, onChange);
  };
}

/**
 * A page's tab, kept in the address as `?tab=`.
 *
 * It opens from `?tab=` (how search links straight to an employee's attendance
 * or the team's leave), and choosing another tab writes the choice back with
 * `history.replaceState`. So a reload stays on the tab, a link can point at
 * Security or SSO, and Back leaves the page in one step instead of walking
 * back through every tab that was opened.
 *
 * - The default tab (`fallback`) is left out of the address, so the default
 *   view keeps a clean URL.
 * - A `?tab=` that is not in `allowed` (a stale link, a role that lost a tab)
 *   opens `fallback`; the address is left as it was until a tab is chosen.
 * - Other query parameters and the hash are kept.
 * - The address is the one source of truth, so the tab follows Back/Forward
 *   to an entry that carries a `?tab=` and a link to the same page with
 *   another `?tab=`.
 *
 * Read through `useSyncExternalStore` rather than `useSearchParams`: the
 * static export prerenders these pages, and `useSearchParams` would need a
 * Suspense boundary around each one. The server snapshot is the fallback, so
 * the prerendered HTML and the first client render agree.
 */
export function useQueryTab<T extends string>(
  allowed: readonly T[],
  fallback: T,
): [T, (next: string) => void] {
  const fromUrl = useSyncExternalStore(
    subscribe,
    () => new URLSearchParams(window.location.search).get("tab"),
    () => null,
  );

  const value = allowed.find((tab) => tab === fromUrl) ?? fallback;

  return [
    value,
    (next: string) => {
      const match = allowed.find((tab) => tab === next);
      if (!match) return;

      const params = new URLSearchParams(window.location.search);
      if (match === fallback) params.delete("tab");
      else params.set("tab", match);

      const query = params.toString();
      const url = `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash}`;
      const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
      if (url !== current) {
        // Keep the router's own state object: Next.js reads it on back/forward.
        window.history.replaceState(window.history.state, "", url);
      }
      window.dispatchEvent(new Event(CHANGE_EVENT));
    },
  ];
}
