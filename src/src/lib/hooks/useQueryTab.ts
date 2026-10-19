"use client";

import { useState, useSyncExternalStore } from "react";

function subscribe(onChange: () => void): () => void {
  window.addEventListener("popstate", onChange);
  return () => window.removeEventListener("popstate", onChange);
}

/**
 * A page's tab, opened from `?tab=` in the address (how search links straight
 * to an employee's attendance or the team's leave), and then the person's own
 * choice once they click another tab.
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
  const [chosen, setChosen] = useState<T | null>(null);

  const requested = allowed.find((tab) => tab === fromUrl) ?? fallback;
  const value = chosen ?? requested;

  return [
    value,
    (next: string) => {
      const match = allowed.find((tab) => tab === next);
      if (match) setChosen(match);
    },
  ];
}
