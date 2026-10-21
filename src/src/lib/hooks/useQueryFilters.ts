"use client";

import { useCallback, useMemo, useSyncExternalStore } from "react";

/** Fired after this hook rewrites the address, which `replaceState` does not announce. */
const CHANGE_EVENT = "ethr:query-filters";

function subscribe(onChange: () => void): () => void {
  window.addEventListener("popstate", onChange);
  window.addEventListener(CHANGE_EVENT, onChange);
  return () => {
    window.removeEventListener("popstate", onChange);
    window.removeEventListener(CHANGE_EVENT, onChange);
  };
}

/**
 * A list page's filters, kept in the address.
 *
 * Filters lived in component state, so a reload lost them and a filtered view
 * could not be sent to a colleague (FRONTEND_REDESIGN_PLAN.md, `FilterBar`).
 * This reads them from `?key=value`, and writes each change back with
 * `history.replaceState`: the address updates without a navigation, a reload,
 * or a new history entry per keystroke.
 *
 * - A value equal to its default is left out of the address, so an unfiltered
 *   page has a clean URL.
 * - `allowed` names the values a key may take; anything else in the address
 *   (a stale link, a typo) falls back to the default instead of reaching the
 *   API.
 * - Read through `useSyncExternalStore`, like `useQueryTab`, because the
 *   static export prerenders these pages and `useSearchParams` would need a
 *   Suspense boundary on each. The server snapshot is empty, so the
 *   prerendered HTML shows the defaults.
 */
export function useQueryFilters<T extends Record<string, string>>(
  defaults: T,
  allowed: Partial<Record<keyof T, readonly string[]>> = {},
): {
  values: T;
  set: (patch: Partial<T>) => void;
  clear: () => void;
  /** How many filters differ from their default: for a "Clear filters (2)" label. */
  activeCount: number;
} {
  const search = useSyncExternalStore(
    subscribe,
    () => window.location.search,
    () => "",
  );

  // `defaults` and `allowed` are usually inline literals; keyed by their
  // content so a new object each render does not recompute everything.
  const defaultsKey = JSON.stringify(defaults);
  const allowedKey = JSON.stringify(allowed);

  const values = useMemo(() => {
    const params = new URLSearchParams(search);
    const out = { ...defaults };
    for (const key of Object.keys(defaults) as (keyof T)[]) {
      const raw = params.get(String(key));
      if (raw === null) continue;
      const ok = allowed[key];
      if (ok && !ok.includes(raw)) continue;
      out[key] = raw as T[keyof T];
    }
    return out;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, defaultsKey, allowedKey]);

  const write = useCallback(
    (next: T) => {
      const params = new URLSearchParams(window.location.search);
      for (const key of Object.keys(defaults)) {
        const v = next[key];
        if (v === undefined || v === defaults[key] || v === "") {
          params.delete(key);
        } else {
          params.set(key, v);
        }
      }
      const query = params.toString();
      const url = `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash}`;
      // Keep the router's own state object: Next.js reads it on back/forward.
      window.history.replaceState(window.history.state, "", url);
      window.dispatchEvent(new Event(CHANGE_EVENT));
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [defaultsKey],
  );

  const set = useCallback(
    (patch: Partial<T>) => write({ ...values, ...patch } as T),
    [values, write],
  );
  const clear = useCallback(() => write({ ...defaults }), [write, defaults]);

  const activeCount = (Object.keys(defaults) as (keyof T)[]).filter(
    (key) => values[key] !== defaults[key],
  ).length;

  return { values, set, clear, activeCount };
}
