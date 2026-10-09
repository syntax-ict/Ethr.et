"use client";

import { useSyncExternalStore } from "react";

const subscribe = () => () => {};
const onClient = () => true;
const onPrerender = () => false;

/**
 * `false` while the prerendered HTML is being produced and hydrated, `true` after.
 *
 * For values that exist only in the browser — the URL, the hostname — on a page
 * whose HTML was prerendered without them. Reading such a value during the first
 * client render produces markup that disagrees with the HTML React is hydrating
 * against, which React reports as **hydration error #418**. Measured on both of
 * this hook's callers, 2026-09-26.
 *
 * `useSyncExternalStore`'s `getServerSnapshot` is React's own answer to a value
 * that differs between the prerender and the client: it hydrates against
 * `false`, then re-renders with `true`, and no mismatch is possible. A
 * `useEffect` + `useState` pair gets to the same place, and an `if (typeof
 * window !== "undefined")` does not — that is true *during* hydration, which is
 * precisely when it must not be read.
 *
 * The store never changes after hydration, so `subscribe` has nothing to
 * subscribe to. Whatever the caller derives has to re-render on its own —
 * `useRouteId` leans on `usePathname()` for that; `useHost` needs nothing,
 * because a hostname cannot change without a page load.
 */
export function useHydrated(): boolean {
  return useSyncExternalStore(subscribe, onClient, onPrerender);
}
