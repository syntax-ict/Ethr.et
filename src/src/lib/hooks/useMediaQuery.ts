"use client";

import { useCallback, useSyncExternalStore } from "react";

/**
 * Whether a CSS media query matches, kept in step with the window.
 *
 * The server snapshot is `false`, so the static export and the first
 * hydration pass agree; the browser's real answer follows at once. Where
 * `matchMedia` does not exist (jsdom in tests, very old browsers) it stays
 * `false`, so callers should make `false` the desktop-safe default.
 */
export function useMediaQuery(query: string): boolean {
  const subscribe = useCallback(
    (onChange: () => void) => {
      if (typeof window === "undefined" || !window.matchMedia) return () => {};
      const list = window.matchMedia(query);
      list.addEventListener("change", onChange);
      return () => list.removeEventListener("change", onChange);
    },
    [query],
  );

  return useSyncExternalStore(
    subscribe,
    () =>
      typeof window !== "undefined" && !!window.matchMedia
        ? window.matchMedia(query).matches
        : false,
    () => false,
  );
}

/** Below Tailwind's `sm` breakpoint (640px): a phone held upright. */
export function useIsPhone(): boolean {
  return useMediaQuery("(max-width: 639.98px)");
}
